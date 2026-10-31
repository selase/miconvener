<?php

declare(strict_types=1);

use App\Models\CommercePaymentReference;
use App\Models\Feature;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreReceipt;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialQuote;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use App\Services\Marketplace\StorePaymentNotifications;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\VenueBookingApprovals;
use App\Services\Marketplace\VenueCommercialQuotes;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueRequestRollout;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    refreshTenantDatabases();
    config(['services.settlement.paystack.secret_key' => 'sk_test_venue_payments']);
    Http::preventStrayRequests();
    Http::fake([
        '*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_VENUE', 'email' => 'buyer@planner.test']]),
        '*api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/venue-test']]),
    ]);
    $this->tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
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
    Permission::query()->firstOrCreate(['name' => 'manage venue', 'guard_name' => 'web'], ['category' => 'venue']);
    $this->host = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->host->givePermissionTo('manage venue');
    app(TenantContext::class)->setTenant($this->tenant);

    $this->eventStart = CarbonImmutable::now()->addDays(30)->setTime(9, 0);
    $this->heldBooking = function (array $overrides = []): VenueBooking {
        $booking = VenueBooking::factory()->create($overrides + [
            'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id,
            'status' => VenueBooking::STATUS_APPROVED_HOLD, 'approval_flow_version' => 1, 'hold_expires_at' => CarbonImmutable::now()->addDays(3)->setMicroseconds(0),
            'starts_at' => $this->eventStart, 'ends_at' => $this->eventStart->addHours(8), 'planner_email' => 'buyer@planner.test',
        ]);
        VenueBookingAction::factory()->create(['booking_id' => $booking->id, 'shop_id' => $booking->shop_id, 'tenant_id' => $booking->tenant_id, 'action' => VenueBookingAction::ACTION_APPROVED, 'hold_expires_at' => $booking->hold_expires_at]);

        return $booking;
    };
    $this->booking = ($this->heldBooking)();
    $this->session = fn (string $email = 'buyer@planner.test'): Store => tap(new Store('venue-pay', new ArraySessionHandler(120)), fn (Store $s) => $s->put(proofFor($email)));
    $this->input = fn (array $overrides = []): array => $overrides + [
        'rental_pesewas' => 100000, 'extras_pesewas' => 5000, 'security_pesewas' => 20000,
        'payment_plan' => 'deposit', 'deposit_pesewas' => 30000,
        'balance_due_at' => $this->eventStart->subDays(7)->toIso8601String(), 'security_due_at' => $this->eventStart->subDays(2)->toIso8601String(),
        'cancellation_terms' => 'Full refund of any instalment up to 14 days before the event.', 'support_contact' => 'bookings@host.example',
    ];
    $this->accepted = function (array $overrides = [], ?VenueBooking $booking = null): VenueFinancialQuote {
        $quotes = app(VenueCommercialQuotes::class);
        $quote = $quotes->issue($booking ?? $this->booking, $this->host, ($this->input)($overrides), (string) Str::uuid());
        $quotes->accept($quote, ($this->session)(), $quote->terms_hash, (string) Str::uuid());

        return $quote;
    };
    $this->attemptFor = function (VenueFinancialQuote $quote, string $purpose = VenueBookingCharge::RENTAL_INITIAL): StorePaymentAttempt {
        $charge = VenueBookingCharge::query()->where('quote_id', $quote->id)->where('purpose', $purpose)->firstOrFail();

        return app(StorePayments::class)->start($charge->order, ($this->session)(), (string) Str::uuid(), $quote->terms_hash);
    };
    $this->evidence = fn (StorePaymentAttempt $attempt, array $changes = []): array => array_replace([
        'status' => 'success', 'provider' => 'paystack', 'reference' => $attempt->reference, 'reference_reported' => true,
        'account_fingerprint' => $attempt->account_fingerprint, 'amount' => $attempt->amount_pesewas, 'currency' => 'GHS', 'fees' => 150,
        'paid_at' => now()->toIso8601String(), 'transaction_id' => 4242,
        'metadata' => ['source' => 'miconvener_venue', 'venue_charge_id' => VenueBookingCharge::query()->where('order_id', $attempt->order_id)->value('id'),
            'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id],
    ], $changes);
    $this->capture = function (StorePaymentAttempt $attempt, array $changes = []): StorePaymentObservation {
        $capture = app(StorePaymentEvidence::class)->record($attempt, ($this->evidence)($attempt, $changes));
        app(StorePaymentConfirmer::class)->confirm($capture);

        return $capture->fresh();
    };
    $this->sales = fn (): int => LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count();
});

describe('starting a venue payment', function (): void {
    test('sends the venue source and charge to the provider and claims the reference once', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);

        $charge = VenueBookingCharge::query()->where('order_id', $attempt->order_id)->sole();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transaction/initialize') && $r['amount'] === 30000
            && $r['metadata']['source'] === 'miconvener_venue' && $r['metadata']['venue_charge_id'] === $charge->id && $r['reference'] === $attempt->reference);
        expect($attempt->status)->toBe('ready')
            ->and(CommercePaymentReference::query()->where('reference', $attempt->reference)->where('order_id', $attempt->order_id)->count())->toBe(1);
    });

    test('refuses to start a payment without an accepted quote or for another buyer', function (): void {
        $quote = ($this->accepted)();
        $order = VenueBookingCharge::query()->where('quote_id', $quote->id)->firstOrFail()->order;

        expect(fn () => app(StorePayments::class)->start($order, ($this->session)('other@planner.test'), (string) Str::uuid(), $quote->terms_hash))->toThrow(ValidationException::class)
            ->and(fn () => app(StorePayments::class)->start($order, ($this->session)(), (string) Str::uuid(), hash('sha256', 'forged')))->toThrow(ValidationException::class);
        expect(StorePaymentAttempt::query()->count())->toBe(0);
    });

    test('the HTTP start returns the hosted checkout for the buyer and rejects caller-supplied money or another buyer', function (): void {
        $quote = ($this->accepted)();
        $charge = VenueBookingCharge::query()->where('quote_id', $quote->id)->where('purpose', VenueBookingCharge::RENTAL_INITIAL)->firstOrFail();
        $url = route('venue.charges.checkout', $charge->id);
        $body = ['submission_key' => (string) Str::uuid(), 'accepted_quote_hash' => $quote->terms_hash];

        $this->withSession(proofFor('buyer@planner.test'))->postJson($url, $body)->assertOk()
            ->assertJsonPath('status', 'ready')->assertJsonPath('checkout_url', 'https://checkout.paystack.com/venue-test');
        foreach (['amount' => 1, 'fee' => 0, 'buyer_email' => 'x@y.test', 'tenant_id' => $this->tenant->id, 'reference' => 'fake'] as $key => $value) {
            $this->withSession(proofFor('buyer@planner.test'))->postJson($url, [...$body, $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->withSession(proofFor('other@planner.test'))->postJson($url, $body)->assertNotFound();
        $this->postJson($url, $body)->assertNotFound();
        expect(StorePaymentAttempt::query()->count())->toBe(1);
    });

    test('the HTTP start refuses a forged terms hash and a disabled rollout without calling the provider', function (): void {
        $quote = ($this->accepted)();
        $charge = VenueBookingCharge::query()->where('quote_id', $quote->id)->where('purpose', VenueBookingCharge::RENTAL_INITIAL)->firstOrFail();
        $url = route('venue.charges.checkout', $charge->id);

        $this->withSession(proofFor('buyer@planner.test'))->postJson($url, ['submission_key' => (string) Str::uuid(), 'accepted_quote_hash' => hash('sha256', 'forged')])->assertUnprocessable();
        $this->tenant->features()->where('feature_key', VenuePaymentRollout::FEATURE)->update(['enabled' => false]);
        Cache::forget('tenant_'.$this->tenant->id.'_feature_'.VenuePaymentRollout::FEATURE);
        $this->withSession(proofFor('buyer@planner.test'))->postJson($url, ['submission_key' => (string) Str::uuid(), 'accepted_quote_hash' => $quote->terms_hash])->assertUnprocessable();

        Http::assertNothingSent();
        expect(StorePaymentAttempt::query()->count())->toBe(0);
    });

    test('keeps an unknown initialisation as one attempt and never replaces it', function (): void {
        $quote = ($this->accepted)();
        Http::fake(['*api.paystack.co/customer*' => Http::response(['data' => ['customer_code' => 'CUS_VENUE']]),
            '*api.paystack.co/transaction/initialize' => fn () => throw new Illuminate\Http\Client\ConnectionException('Unknown provider response')]);

        $first = ($this->attemptFor)($quote);
        $second = ($this->attemptFor)($quote);

        expect($first->status)->toBe('unknown')->and($second->id)->toBe($first->id)->and(StorePaymentAttempt::query()->count())->toBe(1);
    });
});

describe('confirming the booking from verified money', function (): void {
    test('a verified initial payment posts the sale once, confirms the booking and issues one receipt', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);

        $capture = ($this->capture)($attempt);
        app(StorePaymentConfirmer::class)->confirm($capture);

        $order = $attempt->order->fresh();
        $sale = LedgerTransaction::withoutGlobalScopes()->where('reference', $attempt->reference)->where('transaction_type', 'commerce_sale')->sole()->entries()->with('account')->get()->keyBy(fn ($entry) => $entry->account->code);
        expect($capture->processing_state)->toBe('posted')
            ->and($order)->state->toBe('confirmed')->payment_state->toBe('paid')->ledger_state->toBe('posted')
            ->and($this->booking->fresh())->status->toBe(VenueBooking::STATUS_CONFIRMED)->payment_status->toBe(VenueBooking::PAYMENT_DEPOSIT_PAID)
            ->and(($this->sales)())->toBe(1)
            ->and($sale[LedgerAccount::CODE_PLATFORM_REVENUE]->amount)->toBe(900)->and($sale[LedgerAccount::CODE_ORGANIZER_PAYABLE]->amount)->toBe(30000 - 150 - 900)
            ->and(StoreReceipt::query()->where('order_id', $order->id)->count())->toBe(1)
            ->and(CommercePaymentReference::query()->where('reference', $attempt->reference)->value('ledger_reference'))->toBe($attempt->reference);
    });

    test('full payment marks the booking fully paid', function (): void {
        $quote = ($this->accepted)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);

        ($this->capture)(($this->attemptFor)($quote));

        expect($this->booking->fresh())->status->toBe(VenueBooking::STATUS_CONFIRMED)->payment_status->toBe(VenueBooking::PAYMENT_FULLY_PAID);
    });

    test('a callback and a webhook for the same payment post and confirm once', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        Http::fake(['*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 4242, ...($this->evidence)($attempt), 'metadata' => json_encode(($this->evidence)($attempt)['metadata'])]])]);

        app(StorePaymentNotifications::class)->reconcile($attempt);
        app(StorePaymentNotifications::class)->reconcile($attempt);
        $handled = app(StorePaymentNotifications::class)->paystack(['reference' => $attempt->reference, 'metadata' => ['source' => 'miconvener_venue']], app(GivingGateways::class)->platformIdentity());

        expect($handled)->toBeTrue()->and(($this->sales)())->toBe(1)
            ->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
            ->and(StoreReceipt::query()->count())->toBe(1);
    });

    test('the signed settlement webhook routes venue money through the same verification', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        $data = ['id' => 4242, ...($this->evidence)($attempt)];
        $data['metadata'] = json_encode($data['metadata']);
        Http::fake(['*api.paystack.co/transaction/verify/*' => Http::response(['data' => $data])]);
        $payload = json_encode(['event' => 'charge.success', 'data' => $data], JSON_THROW_ON_ERROR);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, 'sk_test_venue_payments')];

        $this->call('POST', route('webhooks.paystack'), [], [], [], $headers, $payload)->assertOk();
        $this->call('POST', route('webhooks.paystack'), [], [], [], $headers, $payload)->assertOk();

        expect(($this->sales)())->toBe(1)->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED);
    });

    test('verified evidence that differs in any identity is held for review and confirms nothing', function (array $change): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);

        $capture = ($this->capture)($attempt, $change);

        expect($capture->processing_state)->toBe('review')->and($attempt->order->fresh()->payment_state)->toBe('captured_review')
            ->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD)
            ->and(($this->sales)())->toBe(0)
            ->and(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_capture_review')->count())->toBeLessThanOrEqual(1);
    })->with([
        'amount' => [['amount' => 29999]], 'currency' => [['currency' => 'USD']], 'account' => [['account_fingerprint' => 'wrong-account']],
        'reference' => [['reference' => 'foreign-ref']], 'fees missing' => [['fees' => null]],
        'wrong source' => [['metadata' => ['source' => 'miconvener_store']]],
    ]);

    test('metadata of the wrong type or another charge is never trusted', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        $evidence = ($this->evidence)($attempt);

        $capture = ($this->capture)($attempt, ['metadata' => [...$evidence['metadata'], 'venue_charge_id' => 12345]]);

        expect($capture->processing_state)->toBe('review')->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD);
    });

    test('a later verification that differs from posted money is reviewed, never reposted', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        ($this->capture)($attempt);

        $changed = ($this->capture)($attempt, ['transaction_id' => 777]);

        expect($changed->processing_state)->toBe('review')->and(($this->sales)())->toBe(1)->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED);
    });

    test('a ledger failure keeps the evidence but confirms nothing, and a retry confirms once', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        $capture = app(StorePaymentEvidence::class)->record($attempt, ($this->evidence)($attempt));
        $db = $attempt->order->getConnection();
        $db->statement("ALTER TABLE ledger_transactions ADD CONSTRAINT test_venue_ledger_failure CHECK (transaction_type <> 'commerce_sale')");

        expect(fn () => app(StorePaymentConfirmer::class)->confirm($capture))->toThrow(Illuminate\Database\QueryException::class);
        expect(StorePaymentObservation::query()->count())->toBe(1)->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD)
            ->and($attempt->order->fresh()->payment_state)->toBe('captured_review');

        $db->statement('ALTER TABLE ledger_transactions DROP CONSTRAINT test_venue_ledger_failure');
        app(StorePaymentConfirmer::class)->confirm($capture);
        app(StorePaymentConfirmer::class)->confirm($capture);

        expect($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)->and(($this->sales)())->toBe(1);
    });
});

describe('timing and the calendar', function (): void {
    test('timely money delivered after the hold expired confirms while the dates are still free', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        $paidAt = now()->addHour()->toIso8601String();

        $this->travelTo($this->booking->hold_expires_at->addMinutes(5));
        $capture = ($this->capture)($attempt, ['paid_at' => $paidAt]);

        expect($capture->processing_state)->toBe('posted')->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED);
    });

    test('money paid at the exact expiry instant or later is reserved for review', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);

        $this->travelTo($quote->expires_at);
        $capture = ($this->capture)($attempt, ['paid_at' => now()->toIso8601String()]);

        expect($capture->processing_state)->toBe('review')->and($capture->review_reason)->toBe('capture_after_expiry')
            ->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD);
    });

    test('timely money never displaces a different booking that took the dates', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        $this->travelTo($this->booking->hold_expires_at->addMinutes(5));
        $winner = ($this->heldBooking)(['status' => VenueBooking::STATUS_CONFIRMED, 'hold_expires_at' => null, 'planner_email' => 'winner@planner.test']);

        $capture = ($this->capture)($attempt, ['paid_at' => $this->booking->hold_expires_at->subMinute()->toIso8601String()]);

        expect($capture->processing_state)->toBe('review')->and($capture->review_reason)->toBe('calendar_conflict')
            ->and($winner->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
            ->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD)
            ->and(($this->sales)())->toBe(0);
    });

    test('money for a booking the host released meanwhile is reserved, not confirmed', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        app(VenueBookingApprovals::class)->release($this->booking, $this->host, 'Dates needed elsewhere.', (string) Str::uuid());

        $capture = ($this->capture)($attempt);

        expect($capture->review_reason)->toBe('booking_closed')->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED);
    });

    test('the balance is payable after the initial hold has long expired and completes the payment', function (): void {
        $quote = ($this->accepted)();
        ($this->capture)(($this->attemptFor)($quote));
        $this->travelTo($this->booking->hold_expires_at->addDays(2));

        $balance = ($this->capture)(($this->attemptFor)($quote, VenueBookingCharge::RENTAL_BALANCE));

        expect($balance->processing_state)->toBe('posted')->and($this->booking->fresh())->status->toBe(VenueBooking::STATUS_CONFIRMED)->payment_status->toBe(VenueBooking::PAYMENT_FULLY_PAID)
            ->and(($this->sales)())->toBe(2);
    });

    test('a balance cannot be started before the booking is confirmed', function (): void {
        $quote = ($this->accepted)();

        expect(fn () => ($this->attemptFor)($quote, VenueBookingCharge::RENTAL_BALANCE))->toThrow(ValidationException::class);
    });

    test('a security payment is posted as a refundable liability and never confirms or completes the booking', function (): void {
        $quote = ($this->accepted)();
        ($this->capture)(($this->attemptFor)($quote));
        $security = ($this->capture)(($this->attemptFor)($quote, VenueBookingCharge::SECURITY));

        expect($security->processing_state)->toBe('posted')->and($security->review_reason)->toBeNull()
            ->and($this->booking->fresh()->payment_status)->toBe(VenueBooking::PAYMENT_DEPOSIT_PAID)
            ->and(($this->sales)())->toBe(1);
    });
});

describe('protecting paid and in-flight bookings', function (): void {
    test('the unpaid release and buyer cancellation cannot touch a paid booking', function (): void {
        $quote = ($this->accepted)();
        ($this->capture)(($this->attemptFor)($quote));

        expect(fn () => app(VenueBookingApprovals::class)->release($this->booking, $this->host, 'Changed our mind.', (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => app(VenueBookingApprovals::class)->cancelByBuyer($this->booking, ($this->session)(), (string) Str::uuid()))->toThrow(ValidationException::class);
        expect($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED);
    });

    test('a hold with captured but unprocessed money cannot be released or cancelled', function (): void {
        $quote = ($this->accepted)();
        $attempt = ($this->attemptFor)($quote);
        app(StorePaymentEvidence::class)->record($attempt, ($this->evidence)($attempt));

        expect(fn () => app(VenueBookingApprovals::class)->release($this->booking, $this->host, 'Dates needed elsewhere.', (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => app(VenueBookingApprovals::class)->cancelByBuyer($this->booking, ($this->session)(), (string) Str::uuid()))->toThrow(ValidationException::class);
        expect($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD);
    });
});

describe('legacy payment boundary', function (): void {
    test('a reference taken by the legacy venue path can never be claimed as a new sale', function (): void {
        $legacy = ($this->heldBooking)(['paystack_reference' => 'legacy-ref-1', 'starts_at' => $this->eventStart->addDays(20), 'ends_at' => $this->eventStart->addDays(20)->addHours(4)]);
        $quote = ($this->accepted)();
        $order = VenueBookingCharge::query()->where('quote_id', $quote->id)->firstOrFail()->order;

        expect(fn () => app(App\Services\Marketplace\CommercePaymentReferences::class)->claimForOrder($order, 'legacy-ref-1'))->toThrow(RuntimeException::class);
        expect($legacy->paystack_reference)->toBe('legacy-ref-1');
    });

    test('settling a legacy booking payment claims its reference for that booking only', function (): void {
        $booking = VenueBooking::factory()->create([
            'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id,
            'status' => VenueBooking::STATUS_PENDING_PAYMENT, 'approval_flow_version' => null, 'planner_email' => 'old@planner.test',
            'starts_at' => $this->eventStart->addDays(10), 'ends_at' => $this->eventStart->addDays(10)->addHours(4),
        ]);

        app(App\Services\Marketplace\VenueBookingService::class)->confirmBookingPayment('legacy-pay-9', ['amount' => 5000, 'fees' => 100, 'metadata' => ['venue_booking_id' => $booking->id]]);

        $claim = CommercePaymentReference::query()->where('reference', 'legacy-pay-9')->sole();
        expect($claim->source_type)->toBe(CommercePaymentReference::SOURCE_LEGACY_BOOKING)->and($claim->booking_id)->toBe($booking->id);
    });
});

test('review a calendar-conflicted capture stays held after the competing booking is cancelled', function (): void {
    $quote = ($this->accepted)();
    $attempt = ($this->attemptFor)($quote);
    $paidAt = now()->addHour()->toIso8601String();
    $this->travelTo($this->booking->hold_expires_at->addMinutes(5));
    $winner = ($this->heldBooking)(['status' => VenueBooking::STATUS_CONFIRMED, 'hold_expires_at' => null, 'planner_email' => 'winner@planner.test']);
    $capture = ($this->capture)($attempt, ['paid_at' => $paidAt]);
    expect($capture->review_reason)->toBe('calendar_conflict');
    $winner->update(['status' => VenueBooking::STATUS_CANCELLED]);
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($attempt->order->fresh()->payment_state)->toBe('captured_review')
        ->and(($this->sales)())->toBe(0)
        ->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD);
});
