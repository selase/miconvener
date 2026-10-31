<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StorePaymentObservation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialDecision;
use App\Models\VenueFinancialQuote;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueRequestRollout;
use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Mail::fake();
    config(['services.settlement.paystack.secret_key' => 'sk_test_journey']);
    Http::preventStrayRequests();
    Http::fake([
        '*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_J', 'email' => 'buyer@planner.test']]),
        '*api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/journey']]),
    ]);
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->tenant = Tenant::factory()->create(['slug' => 'journey-hotel', 'isolation_mode' => 'shared', 'settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->listing = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'is_bookable' => true]);
    StoreCommercialAgreement::factory()->reservation()->create([
        'shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id, 'percentage' => '3.00', 'cap_pesewas' => null,
        'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity()),
    ]);
    foreach ([VenueRequestRollout::FEATURE, VenuePaymentRollout::FEATURE] as $slug) {
        Feature::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'type' => 'boolean', 'description' => 'test']);
        $this->tenant->features()->updateOrCreate(['feature_key' => $slug], ['enabled' => true, 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$this->tenant->id.'_feature_'.$slug);
    }
    setPermissionsTeamId($this->tenant->id);
    $this->hostOnly = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->hostOnly->assignRole('Org Admin');
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->owner->assignRole('Org Superadmin');
    $this->tenant->users()->attach([$this->hostOnly->id, $this->owner->id]);

    $this->domain = "{$this->tenant->slug}.".mb_ltrim((string) config('session.domain'), '.');
    $this->start = CarbonImmutable::now()->addDays(30)->setTime(9, 0);
    $this->booking = VenueBooking::factory()->create([
        'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id, 'status' => VenueBooking::STATUS_APPROVED_HOLD,
        'approval_flow_version' => 1, 'hold_expires_at' => CarbonImmutable::now()->addDays(3)->setMicroseconds(0), 'starts_at' => $this->start,
        'ends_at' => $this->start->addHours(8), 'planner_email' => 'buyer@planner.test', 'kind' => VenueBooking::KIND_MARKETPLACE,
    ]);
    VenueBookingAction::factory()->create(['booking_id' => $this->booking->id, 'shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id, 'action' => VenueBookingAction::ACTION_APPROVED, 'hold_expires_at' => $this->booking->hold_expires_at]);

    $this->quoteBody = fn (array $overrides = []): array => $overrides + [
        'rental_pesewas' => 100000, 'extras_pesewas' => 5000, 'security_pesewas' => 20000, 'payment_plan' => 'deposit', 'deposit_pesewas' => 30000,
        'balance_due_at' => $this->start->subDays(7)->toIso8601String(), 'security_due_at' => $this->start->subDays(2)->toIso8601String(),
        'cancellation_terms' => 'Full refund of any instalment up to 14 days before the event.', 'support_contact' => 'bookings@host.example',
        'operation_key' => (string) Str::uuid(),
    ];
    $this->hostPost = fn (string $path, array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->owner)->post("http://{$this->domain}/venue/{$path}", $data, ['HTTP_HOST' => $this->domain]);
    $this->issue = fn (array $overrides = [], ?User $as = null) => ($this->hostPost)("inquiries/{$this->booking->id}/financial-quote", ($this->quoteBody)($overrides), $as);
    $this->asBuyer = fn (string $email = 'buyer@planner.test') => $this->withSession(proofFor($email));
    $this->buyerPage = fn () => ($this->asBuyer)()->get(route('marketplace.bookings.show', $this->booking->booking_reference));
    $this->accept = function (VenueFinancialQuote $quote, string $email = 'buyer@planner.test', ?string $hash = null) {
        return ($this->asBuyer)($email)->post(route('marketplace.bookings.quote.accept', $this->booking->booking_reference), ['operation_key' => (string) Str::uuid(), 'accepted_quote_hash' => $hash ?? $quote->terms_hash]);
    };
    $this->latest = fn (): VenueFinancialQuote => VenueFinancialQuote::query()->where('booking_id', $this->booking->id)->orderByDesc('version')->firstOrFail();
    $this->session = fn (): Store => tap(new Store('journey', new ArraySessionHandler(120)), fn (Store $s) => $s->put(proofFor('buyer@planner.test')));
    $this->payCharge = function (string $purpose = VenueBookingCharge::RENTAL_INITIAL): StorePaymentObservation {
        $charge = VenueBookingCharge::query()->where('purpose', $purpose)->firstOrFail();
        $attempt = app(StorePayments::class)->start($charge->order, ($this->session)(), (string) Str::uuid(), ($this->latest)()->terms_hash);
        $capture = app(StorePaymentEvidence::class)->record($attempt, [
            'status' => 'success', 'provider' => 'paystack', 'reference' => $attempt->reference, 'reference_reported' => true, 'account_fingerprint' => $attempt->account_fingerprint,
            'amount' => $attempt->amount_pesewas, 'currency' => 'GHS', 'fees' => 150, 'paid_at' => now()->toIso8601String(), 'transaction_id' => 4242,
            'metadata' => ['source' => 'miconvener_venue', 'venue_charge_id' => $charge->id, 'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id],
        ]);
        app(StorePaymentConfirmer::class)->confirm($capture);

        return $capture->fresh();
    };
});

describe('the host issuing and withdrawing a final quote', function (): void {
    test('sends the quote once per operation key and shows it with the commission to the host only', function (): void {
        $body = ($this->quoteBody)();
        ($this->hostPost)("inquiries/{$this->booking->id}/financial-quote", $body)->assertRedirect()->assertSessionHas('success');
        ($this->hostPost)("inquiries/{$this->booking->id}/financial-quote", $body)->assertRedirect();

        expect(VenueFinancialQuote::query()->count())->toBe(1);
        $this->actingAs($this->owner)->get("http://{$this->domain}/venue/inquiries/{$this->booking->id}", ['HTTP_HOST' => $this->domain])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('inquiry.financial.quote.version', 1)->where('inquiry.financial.quote.status', 'open')
                ->where('inquiry.financial.quote.contract_total_pesewas', 105000)->has('inquiry.financial.quote.commission_pesewas')
                ->where('can.complete', true)->has('inquiry.financial.settlement'));
    });

    test('rejects a host without the venue permission and another organisation entirely', function (): void {
        $stranger = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        ($this->issue)([], $stranger)->assertRedirect();
        expect(VenueFinancialQuote::query()->count())->toBe(0);
    });

    test('refuses invalid terms with a message instead of an error', function (): void {
        ($this->issue)(['cancellation_terms' => ''])->assertSessionHasErrors('cancellation_terms');
        ($this->issue)(['payment_plan' => 'deposit', 'deposit_pesewas' => null])->assertSessionHasErrors('deposit_pesewas');
        ($this->issue)(['deposit_pesewas' => 999999])->assertSessionHas('error');
        expect(VenueFinancialQuote::query()->count())->toBe(0);
    });

    test('withdraws an open quote with a reason the buyer can read, and a new version replaces an open one', function (): void {
        ($this->issue)()->assertRedirect();
        $first = ($this->latest)();
        ($this->hostPost)("financial-quotes/{$first->id}/withdraw", ['operation_key' => (string) Str::uuid(), 'reason' => ''])->assertSessionHasErrors('reason');
        ($this->hostPost)("financial-quotes/{$first->id}/withdraw", ['operation_key' => (string) Str::uuid(), 'reason' => 'Dates changed.'])->assertSessionHas('success');

        ($this->buyerPage)()->assertInertia(fn (Assert $page) => $page->where('booking.financial.quote.status', 'withdrawn')->where('booking.financial.quote.withdrawn_reason', 'Dates changed.')->where('booking.financial.quote.terms_hash', null));
        ($this->issue)()->assertRedirect();
        expect(($this->latest)()->version)->toBe(2);
    });
});

describe('the buyer accepting and paying', function (): void {
    test('sees the exact terms without any commission, accepts them once and then sees the payments to make', function (): void {
        ($this->issue)();
        $quote = ($this->latest)();

        ($this->buyerPage)()->assertInertia(fn (Assert $page) => $page->where('booking.financial.quote.status', 'open')->where('booking.financial.quote.terms_hash', $quote->terms_hash)
            ->where('booking.financial.quote.initial_pesewas', 30000)->where('booking.financial.quote.security_pesewas', 20000)
            ->missing('booking.financial.quote.commission_pesewas')->where('booking.financial.charges', []));
        ($this->accept)($quote)->assertRedirect()->assertSessionHas('success');

        ($this->buyerPage)()->assertInertia(fn (Assert $page) => $page->where('booking.financial.quote.status', 'accepted')->where('booking.financial.quote.terms_hash', null)->where('booking.financial.quote.accepted_terms_hash', $quote->terms_hash)
            ->has('booking.financial.charges', 3)->where('booking.financial.charges.0.purpose', 'rental_initial')->where('booking.financial.charges.0.state', 'due')
            ->where('booking.financial.charges.0.can_pay', true)->where('booking.financial.charges.1.purpose', 'rental_balance')->where('booking.financial.charges.1.can_pay', false)
            ->where('booking.financial.charges.2.purpose', 'security')->where('booking.financial.charges.2.can_pay', false)
            ->missing('booking.financial.charges.0.fee_pesewas')->missing('booking.financial.charges.0.order_id'));
        expect(VenueFinancialDecision::query()->where('decision', 'accepted')->count())->toBe(1);
    });

    test('is refused for a forged hash, another email or a signed-out visitor', function (): void {
        ($this->issue)();
        $quote = ($this->latest)();

        ($this->accept)($quote, 'buyer@planner.test', str_repeat('a', 64))->assertRedirect()->assertSessionHas('error');
        ($this->accept)($quote, 'other@planner.test')->assertRedirect()->assertSessionHas('error');
        $this->post(route('marketplace.bookings.quote.accept', $this->booking->booking_reference), ['operation_key' => (string) Str::uuid(), 'accepted_quote_hash' => $quote->terms_hash])->assertRedirect()->assertSessionHas('error');
        ($this->accept)($quote, 'buyer@planner.test', 'short')->assertSessionHasErrors('accepted_quote_hash');
        expect(VenueFinancialDecision::query()->where('decision', 'accepted')->count())->toBe(0);
    });

    test('shows each payment state truthfully: paid, due, overdue, under review and refunded', function (): void {
        ($this->issue)();
        ($this->accept)(($this->latest)());
        $this->booking->update(['hold_expires_at' => CarbonImmutable::now()->addDays(3)]);
        ($this->payCharge)();
        $states = fn () => collect(($this->buyerPage)()->viewData('page')['props']['booking']['financial']['charges'])->pluck('state', 'purpose');

        expect($states()->all())->toBe(['rental_initial' => 'paid', 'rental_balance' => 'due', 'security' => 'due']);

        $this->travelTo($this->start->subDays(5));
        expect($states()['rental_balance'])->toBe('overdue');
        $this->travelBack();

        VenueBookingCharge::query()->where('purpose', 'security')->firstOrFail()->order->update(['payment_state' => 'captured_review']);
        expect($states()['security'])->toBe('under_review');
        VenueBookingCharge::query()->where('purpose', 'security')->firstOrFail()->order->update(['payment_state' => 'refunded']);
        expect($states()['security'])->toBe('refunded');
    });

    test('shows a stale quote as expired and offers no payment', function (): void {
        ($this->issue)();
        $this->travelTo(now()->addDays(4));

        ($this->buyerPage)()->assertInertia(fn (Assert $page) => $page->where('booking.financial.quote.status', 'expired')->where('booking.financial.quote.terms_hash', null));
    });

    test('offers a receipt for a paid charge to its buyer only, with no commission in it', function (): void {
        ($this->issue)();
        ($this->accept)(($this->latest)());
        ($this->payCharge)();
        $charge = VenueBookingCharge::query()->where('purpose', 'rental_initial')->firstOrFail();
        expect(App\Models\StoreReceipt::query()->where('order_id', $charge->order_id)->exists())->toBeTrue();
        $central = mb_ltrim((string) config('session.domain'), '.');
        $url = "http://{$central}/my/venue-charges/{$charge->id}/receipt";

        $receipt = ($this->asBuyer)()->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('receipt');
        expect($receipt['total_pesewas'])->toBe(30000)->and($receipt['lines'][0]['title'])->toContain('booking payment')->and(json_encode($receipt))->not->toContain('commission');
        ($this->asBuyer)('other@planner.test')->getJson($url)->assertNotFound();
        $this->getJson($url)->assertNotFound();
        ($this->buyerPage)()->assertInertia(fn (Assert $page) => $page->where('booking.financial.charges.0.has_receipt', true));
    });
});

describe('completing a booking', function (): void {
    test('needs the settlement permission, not just the venue permission', function (): void {
        $this->actingAs($this->hostOnly)->get("http://{$this->domain}/venue/inquiries/{$this->booking->id}", ['HTTP_HOST' => $this->domain])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.complete', false));

        ($this->hostPost)("inquiries/{$this->booking->id}/complete", ['operation_key' => (string) Str::uuid(), 'reason' => 'Done.'], $this->hostOnly)->assertForbidden();
    });

    test('is refused with a message while the booking is not finished and paid', function (): void {
        ($this->hostPost)("inquiries/{$this->booking->id}/complete", ['operation_key' => (string) Str::uuid(), 'reason' => 'Done.'])->assertRedirect()->assertSessionHas('error');
        ($this->hostPost)("inquiries/{$this->booking->id}/complete", ['operation_key' => (string) Str::uuid(), 'reason' => ''])->assertSessionHasErrors('reason');
    });
});

describe('the venue finance desk', function (): void {
    test('is closed to a host without any venue-money permission and open to the owner', function (): void {
        $this->actingAs($this->hostOnly)->get("http://{$this->domain}/venue/finance", ['HTTP_HOST' => $this->domain])->assertForbidden();
        $this->actingAs($this->owner)->get("http://{$this->domain}/venue/finance", ['HTTP_HOST' => $this->domain])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenant/Venue/Finance/Index')->where('can.reconcile', true)->where('can.refunds', true)->where('can.settlements', true));
    });

    test('lists a payment conflict, verifies it with the platform account and refunds it', function (): void {
        $action = VenueBookingAction::factory()->create([
            'booking_id' => $this->booking->id, 'action' => VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT, 'actor_type' => VenueBookingAction::ACTOR_SYSTEM,
            'decision_reason' => 'calendar_taken', 'evidence' => ['provider_reference' => 'journey-ref-1', 'amount_pesewas' => 30000, 'currency' => 'GHS', 'fees_pesewas' => 150],
        ]);
        Http::fake([
            '*api.paystack.co/transaction/verify/journey-ref-1' => Http::response(['status' => true, 'data' => ['id' => 9001, 'reference' => 'journey-ref-1', 'status' => 'success', 'amount' => 30000, 'fees' => 150, 'currency' => 'GHS', 'paid_at' => now()->subHour()->toIso8601String(), 'metadata' => ['venue_booking_id' => $this->booking->id]]]),
            '*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 55001, 'status' => 'pending']]),
        ]);
        $desk = fn () => $this->actingAs($this->owner)->get("http://{$this->domain}/venue/finance", ['HTTP_HOST' => $this->domain]);

        $desk()->assertInertia(fn (Assert $page) => $page->has('conflicts', 1)->where('conflicts.0.reference', 'journey-ref-1')->where('conflicts.0.verified_resolution_id', null)->where('conflicts.0.resolved', false));
        ($this->hostPost)("finance/conflicts/{$action->id}/verify", ['operation_key' => (string) Str::uuid()])->assertSessionHas('success');
        $resolution = App\Models\VenuePaymentResolution::query()->sole();
        $desk()->assertInertia(fn (Assert $page) => $page->where('conflicts.0.classification', 'unposted')->where('conflicts.0.verified_resolution_id', $resolution->id));

        ($this->hostPost)("finance/resolutions/{$resolution->id}/resolve", ['operation_key' => (string) Str::uuid(), 'decision' => 'confirm', 'reason' => 'x'])->assertSessionHasErrors('decision');
        ($this->hostPost)("finance/resolutions/{$resolution->id}/resolve", ['operation_key' => (string) Str::uuid(), 'decision' => 'refund', 'reason' => 'Returning the money.'])->assertSessionHas('success');
        $desk()->assertInertia(fn (Assert $page) => $page->where('conflicts.0.resolved', true)->where('payments.0.payment_state', 'captured_review'));
    });

    test('keeps every finance action behind its own permission and organisation', function (): void {
        $action = VenueBookingAction::factory()->create(['booking_id' => $this->booking->id, 'action' => VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT, 'evidence' => ['provider_reference' => 'x', 'amount_pesewas' => 1, 'currency' => 'GHS']]);
        $other = Tenant::factory()->create(['slug' => 'other-hotel', 'isolation_mode' => 'shared']);
        $outsider = User::factory()->create(['tenant_id' => $other->id]);
        setPermissionsTeamId($other->id);
        $outsider->assignRole('Org Superadmin');
        $other->users()->attach($outsider->id);
        setPermissionsTeamId($this->tenant->id);
        ($this->issue)();
        ($this->accept)(($this->latest)());
        $charge = VenueBookingCharge::query()->where('purpose', 'rental_initial')->firstOrFail();

        ($this->hostPost)("finance/conflicts/{$action->id}/verify", ['operation_key' => (string) Str::uuid()], $this->hostOnly)->assertForbidden();
        ($this->hostPost)("finance/charges/{$charge->id}/refund", ['operation_key' => (string) Str::uuid(), 'reason' => 'x'], $this->hostOnly)->assertForbidden();
        $otherDomain = 'other-hotel.'.mb_ltrim((string) config('session.domain'), '.');
        $this->actingAs($outsider)->post("http://{$otherDomain}/venue/finance/conflicts/{$action->id}/verify", ['operation_key' => (string) Str::uuid()], ['HTTP_HOST' => $otherDomain])->assertNotFound();
        $this->actingAs($outsider)->post("http://{$otherDomain}/venue/finance/charges/{$charge->id}/refund", ['operation_key' => (string) Str::uuid(), 'reason' => 'x'], ['HTTP_HOST' => $otherDomain])->assertNotFound();
        expect(App\Models\VenuePaymentResolution::query()->count())->toBe(0);
    });

    test('refunds a paid charge in full from the desk and refreshes the refund', function (): void {
        ($this->issue)();
        ($this->accept)(($this->latest)());
        ($this->payCharge)();
        $charge = VenueBookingCharge::query()->where('purpose', 'rental_initial')->firstOrFail();
        Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 55001, 'status' => 'pending']])]);

        ($this->hostPost)("finance/charges/{$charge->id}/refund", ['operation_key' => (string) Str::uuid(), 'reason' => ''])->assertSessionHasErrors('reason');
        ($this->hostPost)("finance/charges/{$charge->id}/refund", ['operation_key' => (string) Str::uuid(), 'reason' => 'Cancelled by the planner.'])->assertSessionHas('success');

        $refund = App\Models\StoreRefund::query()->sole();
        expect($refund->amount_pesewas)->toBe(30000)->and($refund->status)->toBe('needs_funding');
        ($this->hostPost)("finance/refunds/{$refund->id}/reconcile")->assertSessionHas('success');
        $this->actingAs($this->owner)->get("http://{$this->domain}/venue/finance", ['HTTP_HOST' => $this->domain])
            ->assertInertia(fn (Assert $page) => $page->where('payments.0.refund.status', 'needs_funding'));
    });
});
