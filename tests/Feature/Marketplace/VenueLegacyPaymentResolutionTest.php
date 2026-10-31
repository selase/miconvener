<?php

declare(strict_types=1);

use App\Models\CommercePaymentReference;
use App\Models\LedgerTransaction;
use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenuePaymentResolution;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\VenueBookingService;
use App\Services\Marketplace\VenuePaymentReconciliation;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    refreshTenantDatabases();
    Mail::fake();
    config(['services.settlement.paystack.secret_key' => 'sk_test_legacy_resolution']);
    Http::preventStrayRequests();
    $this->tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->listing = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'is_bookable' => true]);
    StoreCommercialAgreement::factory()->reservation()->create([
        'shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id,
        'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity()),
    ]);
    foreach (['reconcile venue payments', 'manage venue refunds'] as $name) {
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['category' => 'venue']);
    }
    $this->finance = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->finance->givePermissionTo(['reconcile venue payments', 'manage venue refunds']);
    $this->bystander = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->start = CarbonImmutable::now()->addDays(30)->setTime(9, 0);
    $this->booking = fn (array $overrides = []): VenueBooking => VenueBooking::factory()->create($overrides + [
        'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id,
        'status' => VenueBooking::STATUS_PENDING_PAYMENT, 'starts_at' => $this->start, 'ends_at' => $this->start->addHours(8), 'planner_email' => 'buyer@planner.test',
    ]);
    $this->conflict = function (VenueBooking $booking, string $reference = 'legacy-late-1', int $amount = 30000): VenueBookingAction {
        app(VenueBookingService::class);

        return VenueBookingAction::factory()->create([
            'booking_id' => $booking->id, 'action' => VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT, 'actor_type' => VenueBookingAction::ACTOR_SYSTEM,
            'operation_key' => Ramsey\Uuid\Uuid::uuid5(Ramsey\Uuid\Uuid::NAMESPACE_URL, 'venue-payment-calendar-conflict:'.$reference)->toString(),
            'previous_status' => $booking->status, 'result_status' => $booking->status, 'decision_reason' => 'calendar_taken',
            'evidence' => ['provider_reference' => $reference, 'amount_pesewas' => $amount, 'currency' => 'GHS', 'fees_pesewas' => 150],
        ]);
    };
    $this->providerSays = function (VenueBooking $booking, array $changes = [], string $reference = 'legacy-late-1'): void {
        Http::fake([
            "*api.paystack.co/transaction/verify/{$reference}" => Http::response(['status' => true, 'data' => array_replace_recursive([
                'id' => 777001, 'reference' => $reference, 'status' => 'success', 'amount' => 30000, 'fees' => 150, 'currency' => 'GHS',
                'paid_at' => now()->subHour()->toIso8601String(), 'metadata' => ['venue_booking_id' => $booking->id], 'customer' => ['email' => 'buyer@planner.test'],
            ], $changes)]),
            '*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 55001, 'status' => 'pending']]),
        ]);
    };
    $this->verify = fn (VenueBookingAction $action, ?User $actor = null): VenuePaymentResolution => app(VenuePaymentReconciliation::class)->verify($action, $actor ?? $this->finance, (string) Str::uuid());
    $this->fund = fn (int $pesewas = 100000) => app(App\Services\Finance\LedgerService::class)->postTransaction($this->tenant, null, 'test_funding', 'Earlier venue earnings', 'test-funding-'.Str::uuid(), [
        ['code' => App\Models\LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => 'debit', 'amount' => $pesewas],
        ['code' => App\Models\LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => 'credit', 'amount' => $pesewas],
    ], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    $this->reconciliation = fn (): VenuePaymentReconciliation => app(VenuePaymentReconciliation::class);
    $this->sales = fn (): int => LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count();
    $this->reviews = fn (): int => LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_capture_review')->count();
});

describe('verifying a conflict payment', function (): void {
    test('records the platform account result and changes nothing else', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);

        $verified = ($this->verify)($action);

        expect($verified->step)->toBe('verified')->and($verified->verified_amount_pesewas)->toBe(30000)->and($verified->evidence['classification'])->toBe('unposted')
            ->and($verified->account_fingerprint)->toBe(hash('sha256', app(GivingGateways::class)->platformIdentity()))
            ->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)->and(($this->sales)())->toBe(0)->and(($this->reviews)())->toBe(0);
    });

    test('is idempotent per operation key and refuses a key used for something else', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        $other = ($this->conflict)(($this->booking)(['starts_at' => $this->start->addDays(3), 'ends_at' => $this->start->addDays(3)->addHours(8)]), 'legacy-late-2');
        ($this->providerSays)($booking);
        $key = (string) Str::uuid();

        $first = ($this->reconciliation)()->verify($action, $this->finance, $key);
        $again = ($this->reconciliation)()->verify($action, $this->finance, $key);

        expect($again->id)->toBe($first->id)->and(VenuePaymentResolution::query()->count())->toBe(1);
        expect(fn () => ($this->reconciliation)()->verify($other, $this->finance, $key))->toThrow(ValidationException::class);
    });

    test('refuses an actor without finance permission and a finance user of another organisation', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $outsider = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $outsider->givePermissionTo(['reconcile venue payments', 'manage venue refunds']);

        expect(fn () => ($this->verify)($action, $this->bystander))->toThrow(AuthorizationException::class)
            ->and(fn () => ($this->verify)($action, $outsider))->toThrow(AuthorizationException::class);
        expect(VenuePaymentResolution::query()->count())->toBe(0);
    });

    test('refuses money the provider does not confirm as recorded', function (array $changes): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking, $changes);

        expect(fn () => ($this->verify)($action))->toThrow(ValidationException::class);
        expect(VenuePaymentResolution::query()->count())->toBe(0);
    })->with([
        'a different amount' => [['amount' => 29999]],
        'not captured' => [['status' => 'abandoned']],
        'another currency' => [['currency' => 'USD']],
        'another booking' => [['metadata' => ['venue_booking_id' => 'f5a1c1ba-0000-4000-8000-000000000000']]],
        'fees unreported' => [['fees' => null]],
        'a different reference' => [['reference' => 'someone-elses']],
    ]);

    test('refuses an organisation whose payments are not on the platform account', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $this->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

        expect(fn () => ($this->verify)($action))->toThrow(ValidationException::class);
    });

    test('refuses a row that is not a recorded payment conflict', function (): void {
        $booking = ($this->booking)();
        $approval = VenueBookingAction::factory()->create(['booking_id' => $booking->id, 'action' => VenueBookingAction::ACTION_APPROVED]);

        expect(fn () => ($this->verify)($approval))->toThrow(ValidationException::class);
    });

    test('marks money the older path already posted as blocked', function (): void {
        $booking = ($this->booking)();
        ($this->booking)(['paystack_reference' => 'legacy-late-1', 'starts_at' => $this->start->addDays(5), 'ends_at' => $this->start->addDays(5)->addHours(8)]);
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);

        expect(($this->verify)($action)->evidence['classification'])->toBe('posted_legacy');
    });
});

describe('resolving verified money', function (): void {
    test('retaining keeps everything as it was and can be followed by a refund', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);

        $retained = ($this->reconciliation)()->resolve($verified, $this->finance, 'retain', 'Waiting for the planner to reply.', (string) Str::uuid());

        expect($retained->step)->toBe('retained')->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
            ->and(($this->reviews)())->toBe(0)->and(VenueBookingCharge::query()->count())->toBe(0);
        ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Planner asked for the money back.', (string) Str::uuid());
        expect(VenuePaymentResolution::query()->where('step', 'refunded')->count())->toBe(1);
    });

    test('a refund imports the verified money once as a review liability and requests a full refund', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);
        $key = (string) Str::uuid();

        $refunded = ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Dates were taken; the planner was told.', $key);
        $again = ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Dates were taken; the planner was told.', $key);

        $charge = VenueBookingCharge::query()->sole();
        $observation = StorePaymentObservation::query()->sole();
        $refund = StoreRefund::query()->sole();
        expect($again->id)->toBe($refunded->id)->and($refunded->step)->toBe('refunded')->and($refunded->evidence['refund_id'])->toBe($refund->id)
            ->and($charge->purpose)->toBe('legacy_review')->and($charge->quote_id)->toBeNull()->and($charge->amount_pesewas)->toBe(30000)
            ->and($charge->order->payment_state)->toBe('captured_review')->and($charge->order->accepted_at)->toBeNull()
            ->and($observation->reference)->toBe('legacy-late-1')->and($observation->processing_state)->toBe('review')->and($observation->review_reason)->toBe('quote_not_accepted')
            ->and($refund->amount_pesewas)->toBe(30000)->and($refund->review_liability_pesewas)->toBe(30000)->and($refund->commission_return_pesewas)->toBe(0)
            ->and(($this->reviews)())->toBe(1)->and(($this->sales)())->toBe(0)
            ->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)->and($booking->fresh()->amount_paid_pesewas)->toBe(0);
        expect($refund->status)->toBe('needs_funding');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/refund'));
    });

    test('a funded organisation has the full refund sent once under the original reference', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        ($this->fund)();

        ($this->reconciliation)()->resolve(($this->verify)($action), $this->finance, 'refund', 'Dates were taken.', (string) Str::uuid());

        expect(StoreRefund::query()->sole()->status)->toBe('pending');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/refund') && $r['transaction'] === 'legacy-late-1' && $r['amount'] === 30000);
        expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/refund'))->count())->toBe(1);
    });

    test('keeps the legacy booking as owner of the reference and links its ledger entry', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        app(App\Services\Marketplace\CommercePaymentReferences::class)->claimForLegacyBooking($booking, 'paystack', hash('sha256', app(GivingGateways::class)->platformIdentity()), 'legacy-late-1');
        ($this->providerSays)($booking);

        ($this->reconciliation)()->resolve(($this->verify)($action), $this->finance, 'refund', 'Returning the money.', (string) Str::uuid());

        $claim = CommercePaymentReference::query()->where('reference', 'legacy-late-1')->sole();
        expect($claim->source_type)->toBe(CommercePaymentReference::SOURCE_LEGACY_BOOKING)->and($claim->booking_id)->toBe($booking->id)
            ->and($claim->ledger_reference)->toBe('legacy-late-1')->and(CommercePaymentReference::query()->where('reference', 'legacy-late-1')->count())->toBe(1);
    });

    test('an action is resolved to one terminal outcome only', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);
        ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'First decision.', (string) Str::uuid());

        expect(fn () => ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Second decision.', (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => ($this->reconciliation)()->resolve($verified, $this->finance, 'retain', 'Too late.', (string) Str::uuid()))->toThrow(ValidationException::class);
        expect(StoreRefund::query()->count())->toBe(1)->and(VenueBookingCharge::query()->count())->toBe(1);
    });

    test('never confirms captured legacy money as a booking payment, whatever happened to the competitor', function (): void {
        $winner = ($this->booking)(['status' => VenueBooking::STATUS_CONFIRMED]);
        $booking = ($this->booking)(['starts_at' => $winner->starts_at, 'ends_at' => $winner->ends_at]);
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);
        $winner->update(['status' => VenueBooking::STATUS_CANCELLED]);

        expect(fn () => ($this->reconciliation)()->resolve($verified, $this->finance, 'confirm', 'The dates are free again.', (string) Str::uuid()))->toThrow(ValidationException::class);

        app(VenueBookingService::class)->confirmBookingPayment('legacy-late-1', ['amount' => 30000, 'fees' => 150, 'metadata' => ['venue_booking_id' => $booking->id]]);
        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)->and(($this->sales)())->toBe(0)->and(VenuePaymentResolution::query()->where('step', 'confirmed')->count())->toBe(0);
    });

    test('refuses to refund or confirm money the older path already posted', function (): void {
        $booking = ($this->booking)();
        ($this->booking)(['paystack_reference' => 'legacy-late-1', 'starts_at' => $this->start->addDays(5), 'ends_at' => $this->start->addDays(5)->addHours(8)]);
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);

        expect(fn () => ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Return it.', (string) Str::uuid()))->toThrow(ValidationException::class);
        ($this->reconciliation)()->resolve($verified, $this->finance, 'retain', 'Blocked until a legacy refund exists.', (string) Str::uuid());
        expect(VenueBookingCharge::query()->count())->toBe(0)->and(StoreRefund::query()->count())->toBe(0);
    });

    test('a changed provider amount since verification stops the refund', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);
        Http::swap(new Illuminate\Http\Client\Factory);
        ($this->providerSays)($booking, ['amount' => 25000]);

        expect(fn () => ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Return it.', (string) Str::uuid()))->toThrow(ValidationException::class);
        expect(VenueBookingCharge::query()->count())->toBe(0);
    });

    test('refuses a decision from an actor without finance permission or another organisation', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);
        $outsider = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $outsider->givePermissionTo(['reconcile venue payments', 'manage venue refunds']);

        foreach ([$this->bystander, $outsider] as $actor) {
            expect(fn () => ($this->reconciliation)()->resolve($verified, $actor, 'refund', 'Return it.', (string) Str::uuid()))->toThrow(AuthorizationException::class);
        }
        expect(VenueBookingCharge::query()->count())->toBe(0);
    });

    test('refuses a reference that already belongs to another payment source', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        $stranger = ($this->booking)(['starts_at' => $this->start->addDays(9), 'ends_at' => $this->start->addDays(9)->addHours(8)]);
        app(App\Services\Marketplace\CommercePaymentReferences::class)->claimForLegacyBooking($stranger, 'paystack', hash('sha256', app(GivingGateways::class)->platformIdentity()), 'legacy-late-1');
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);

        expect(fn () => ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Return it.', (string) Str::uuid()))->toThrow(RuntimeException::class);
        expect(VenueBookingCharge::query()->where('purpose', 'legacy_review')->count())->toBe(0)->and(StoreRefund::query()->count())->toBe(0);
    });

    test('a payment received after the booking was confirmed by another payment can still be refunded', function (): void {
        $booking = ($this->booking)(['status' => VenueBooking::STATUS_CONFIRMED, 'payment_status' => VenueBooking::PAYMENT_DEPOSIT_PAID, 'amount_paid_pesewas' => 30000, 'paystack_reference' => 'the-first-payment']);
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);

        ($this->reconciliation)()->resolve(($this->verify)($action), $this->finance, 'refund', 'Paid twice by mistake.', (string) Str::uuid());

        expect(StoreRefund::query()->sole()->amount_pesewas)->toBe(30000)->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
            ->and($booking->fresh()->amount_paid_pesewas)->toBe(30000)->and(($this->sales)())->toBe(0);
    });

    test('a closed booking does not stop money being returned', function (): void {
        $booking = ($this->booking)(['status' => VenueBooking::STATUS_CANCELLED]);
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);

        ($this->reconciliation)()->resolve(($this->verify)($action), $this->finance, 'refund', 'Booking was cancelled.', (string) Str::uuid());

        expect(StoreRefund::query()->count())->toBe(1)->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED);
    });

    test('an unknown refund outcome stays reconcilable under the same reference', function (): void {
        $booking = ($this->booking)();
        $action = ($this->conflict)($booking);
        ($this->providerSays)($booking);
        $verified = ($this->verify)($action);
        ($this->fund)();
        Http::swap(new Illuminate\Http\Client\Factory);
        Http::fake([
            '*api.paystack.co/transaction/verify/legacy-late-1' => Http::response(['status' => true, 'data' => ['id' => 777001, 'reference' => 'legacy-late-1', 'status' => 'success', 'amount' => 30000, 'fees' => 150, 'currency' => 'GHS', 'paid_at' => now()->subHour()->toIso8601String(), 'metadata' => ['venue_booking_id' => $booking->id]]]),
            '*api.paystack.co/refund' => Http::response('boom', 500),
        ]);

        ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Return it.', (string) Str::uuid());

        $refund = StoreRefund::query()->sole();
        expect($refund->status)->toBe('unknown')->and($refund->attempt->reference)->toBe('legacy-late-1');
    });
});

it('a venue reconciliation does not borrow purchase-only refund terms', function (): void {
    $this->tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->listing = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE]);
    StoreCommercialAgreement::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id,
        'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
    $this->finance = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->finance->givePermissionTo(['reconcile venue payments', 'manage venue refunds']);
    app(TenantContext::class)->setTenant($this->tenant);
    $booking = ($this->booking)();
    $action = ($this->conflict)($booking);
    ($this->providerSays)($booking);
    $verified = ($this->verify)($action);
    expect(fn () => ($this->reconciliation)()->resolve($verified, $this->finance, 'refund', 'Return old money.', (string) Str::uuid()))->toThrow(ValidationException::class);
});
