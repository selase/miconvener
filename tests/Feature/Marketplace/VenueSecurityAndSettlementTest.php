<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreReceipt;
use App\Models\StoreRefund;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialQuote;
use App\Models\VenueSettlementReserve;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\StoreBalance;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\StoreRefunds;
use App\Services\Marketplace\VenueCommercialQuotes;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueRequestRollout;
use App\Services\Marketplace\VenueSettlementRelease;
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
    foreach (['manage venue refunds', 'release venue settlements'] as $name) {
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['category' => 'venue']);
        $this->host->givePermissionTo($name);
    }
    $this->fund = fn (int $pesewas = 200000) => app(App\Services\Finance\LedgerService::class)->postTransaction($this->tenant, null, 'test_funding', 'Earlier venue earnings', 'test-funding-'.Str::uuid(), [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => 'debit', 'amount' => $pesewas],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => 'credit', 'amount' => $pesewas],
    ], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    $this->balances = fn (): array => LedgerEntry::query()->with('account')->whereHas('account', fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $this->tenant->id))->get()
        ->groupBy(fn ($e) => $e->account->code)->map(fn ($rows) => $rows->sum(fn ($e) => $e->direction === 'credit' ? $e->amount : -$e->amount))->all();
    $this->sales = fn (): int => LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count();
});

beforeEach(function (): void {
    $this->pay = function (VenueFinancialQuote $quote, string $purpose = VenueBookingCharge::RENTAL_INITIAL): StorePaymentObservation {
        return ($this->capture)(($this->attemptFor)($quote, $purpose));
    };
    $this->securityOrder = fn (VenueFinancialQuote $quote) => VenueBookingCharge::query()->where('quote_id', $quote->id)->where('purpose', VenueBookingCharge::SECURITY)->firstOrFail()->order;
    $this->refundFake = function (StoreRefund $refund): void {
        Http::fake([
            '*api.paystack.co/refund/55001' => Http::response(['data' => ['id' => 55001, 'status' => 'processed', 'amount' => $refund->amount_pesewas, 'currency' => 'GHS', 'merchant_note' => 'store:'.$refund->operation_key, 'transaction' => ['id' => 987]]]),
            '*api.paystack.co/refund' => Http::response(['data' => ['id' => 55001, 'status' => 'pending']]),
            '*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 987, 'reference' => $refund->attempt->reference, 'status' => 'success', 'amount' => $refund->amount_pesewas, 'fees' => 150, 'currency' => 'GHS']]),
        ]);
    };
    $this->returnMoney = function ($order, string $reason = 'Returned after the event.'): StoreRefund {
        Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 55001, 'status' => 'pending']])]);
        $refund = app(StoreRefunds::class)->request($order, $this->host, (string) Str::uuid(), $reason);
        ($this->refundFake)($refund);

        return app(StoreRefunds::class)->reconcile($refund->fresh());
    };
});

describe('security deposits', function (): void {
    test('a security payment is a refundable liability that takes no commission and confirms nothing', function (): void {
        $quote = ($this->accepted)();
        ($this->pay)($quote);
        $booking = $this->booking->fresh();
        $before = ($this->balances)();

        ($this->pay)($quote, VenueBookingCharge::SECURITY);
        $after = ($this->balances)();

        $delta = fn (string $code): int => ($after[$code] ?? 0) - ($before[$code] ?? 0);
        $txn = LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'venue_security_capture')->sole();
        expect($delta(LedgerAccount::CODE_SECURITY_DEPOSITS))->toBe(20000)->and($delta(LedgerAccount::CODE_GATEWAY_CLEARING))->toBe(-19850)
            ->and($delta(LedgerAccount::CODE_GATEWAY_FEES))->toBe(-150)->and($delta(LedgerAccount::CODE_ORGANIZER_PAYABLE))->toBe(0)->and($delta(LedgerAccount::CODE_PLATFORM_REVENUE))->toBe(0)
            ->and($txn->entries()->where('direction', 'debit')->sum('amount'))->toBe($txn->entries()->where('direction', 'credit')->sum('amount'))
            ->and($this->booking->fresh()->payment_status)->toBe($booking->payment_status)->and($this->booking->fresh()->amount_paid_pesewas)->toBe($booking->amount_paid_pesewas)
            ->and(($this->securityOrder)($quote)->payment_state)->toBe('paid')->and(StoreReceipt::query()->count())->toBe(2)
            ->and(VenueSettlementReserve::query()->count())->toBe(1);
    });

    test('security money never raises what can be paid out or what merchandise refunds can use', function (): void {
        $quote = ($this->accepted)();
        ($this->pay)($quote);
        ($this->fund)();
        $balance = app(StoreBalance::class);
        $availableBefore = $balance->available($this->tenant);
        $bufferBefore = $balance->cashBuffer($this->tenant);

        ($this->pay)($quote, VenueBookingCharge::SECURITY);

        expect($balance->available($this->tenant))->toBe($availableBefore)->and($balance->cashBuffer($this->tenant))->toBeLessThanOrEqual($bufferBefore);
    });

    test('a full return releases the liability, recovers the processing fee from the venue and leaves the booking alone', function (): void {
        $quote = ($this->accepted)();
        ($this->pay)($quote);
        ($this->pay)($quote, VenueBookingCharge::SECURITY);
        ($this->fund)();
        $before = ($this->balances)();

        $refund = ($this->returnMoney)(($this->securityOrder)($quote));

        $after = ($this->balances)();
        $delta = fn (string $code): int => ($after[$code] ?? 0) - ($before[$code] ?? 0);
        expect($refund->status)->toBe('confirmed')->and($refund->amount_pesewas)->toBe(20000)->and($refund->commission_return_pesewas)->toBe(0)
            ->and($delta(LedgerAccount::CODE_SECURITY_DEPOSITS))->toBe(-20000)->and($delta(LedgerAccount::CODE_ORGANIZER_PAYABLE))->toBe(-150)
            ->and($delta(LedgerAccount::CODE_GATEWAY_FEES))->toBe(150)->and($delta(LedgerAccount::CODE_GATEWAY_CLEARING))->toBe(20000)
            ->and(($this->securityOrder)($quote)->fresh()->payment_state)->toBe('refunded')
            ->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)->and($this->booking->fresh()->amount_paid_pesewas)->toBe(30000)
            ->and(App\Models\StoreStockMovement::query()->count())->toBe(0);
    });

    test('completing the return twice posts it once', function (): void {
        $quote = ($this->accepted)();
        ($this->pay)($quote);
        ($this->pay)($quote, VenueBookingCharge::SECURITY);
        ($this->fund)();
        $refund = ($this->returnMoney)(($this->securityOrder)($quote));

        app(StoreRefunds::class)->reconcile($refund->fresh());

        expect(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_refund')->count())->toBe(1);
    });

    test('the processing fee of a return is recovered from the venue earnings held for the booking', function (): void {
        $quote = ($this->accepted)();
        ($this->pay)($quote);
        ($this->pay)($quote, VenueBookingCharge::SECURITY);
        Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 55001, 'status' => 'pending']])]);

        $refund = app(StoreRefunds::class)->request(($this->securityOrder)($quote), $this->host, (string) Str::uuid(), 'Event done.');

        expect($refund->status)->toBe('pending')->and($refund->seller_debit_pesewas)->toBe(150);
    });
});

describe('rental refunds', function (): void {
    test('an organisation whose earnings cannot cover a rental refund waits and nothing is sent', function (): void {
        $quote = ($this->accepted)();
        $capture = ($this->pay)($quote);
        Http::fake();

        $refund = app(StoreRefunds::class)->request($capture->order, $this->host, (string) Str::uuid(), 'Cancelled.');

        expect($refund->status)->toBe('needs_funding');
        Http::assertNothingSent();
        expect(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_refund')->count())->toBe(0);
    });

    test('refunding the only rental payment returns the exact commission and frees the booking', function (): void {
        $quote = ($this->accepted)();
        $capture = ($this->pay)($quote);
        ($this->fund)();
        $order = $capture->order;
        $commission = $order->platform_fee_pesewas;
        $before = ($this->balances)();

        $refund = ($this->returnMoney)($order);

        $after = ($this->balances)();
        $delta = fn (string $code): int => ($after[$code] ?? 0) - ($before[$code] ?? 0);
        expect($commission)->toBeGreaterThan(0)->and($refund->status)->toBe('confirmed')->and($refund->commission_return_pesewas)->toBe($commission)
            ->and($delta(LedgerAccount::CODE_PLATFORM_REVENUE))->toBe(-$commission)->and($delta(LedgerAccount::CODE_ORGANIZER_PAYABLE))->toBe(-(30000 - $commission))
            ->and($order->fresh()->payment_state)->toBe('refunded')
            ->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED)->and($this->booking->fresh()->payment_status)->toBe(VenueBooking::PAYMENT_REFUNDED)
            ->and($this->booking->fresh()->amount_paid_pesewas)->toBe(0);
    });

    test('refunding only the balance keeps the booking confirmed on its deposit and releases that reserve', function (): void {
        $quote = ($this->accepted)();
        ($this->pay)($quote);
        $balance = ($this->pay)($quote, VenueBookingCharge::RENTAL_BALANCE);
        ($this->fund)();

        ($this->returnMoney)($balance->order);

        expect($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)->and($this->booking->fresh()->payment_status)->toBe(VenueBooking::PAYMENT_DEPOSIT_PAID)
            ->and($this->booking->fresh()->amount_paid_pesewas)->toBe(30000);
        $held = VenueSettlementReserve::query()->where('state', 'held')->get();
        expect($held)->toHaveCount(2)->and(app(StoreBalance::class)->available($this->tenant))->toBeGreaterThan(0);
    });
});

describe('releasing earnings after the event', function (): void {
    test('rental earnings stay reserved until the host completes a finished, fully paid booking', function (): void {
        $quote = ($this->accepted)();
        ($this->pay)($quote);
        ($this->pay)($quote, VenueBookingCharge::RENTAL_BALANCE);
        ($this->fund)(200000);
        $balance = app(StoreBalance::class);
        $reserved = (int) VenueSettlementReserve::query()->sum('reserved_pesewas');
        expect($balance->available($this->tenant))->toBe(200000)->and(VenueSettlementReserve::query()->count())->toBe(2);

        $this->travelTo($this->eventStart->addHours(9));
        $decision = app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Event finished, no issues.', $key = (string) Str::uuid());
        $again = app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Event finished, no issues.', $key);

        expect($again->id)->toBe($decision->id)->and($decision->decision)->toBe('completed')->and($balance->available($this->tenant))->toBe(200000 + $reserved)
            ->and(VenueSettlementReserve::query()->where('state', 'released')->count())->toBe(2);
        expect(fn () => app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Another note.', (string) Str::uuid()))->toThrow(ValidationException::class);
    });

    test('an event still to come, an unpaid balance or an open refund blocks the release', function (): void {
        $quote = ($this->accepted)();
        ($this->pay)($quote);
        ($this->fund)();

        $this->travelTo($this->eventStart->addHours(9));
        expect(fn () => app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Done.', (string) Str::uuid()))->toThrow(ValidationException::class);
        $this->travelBack();

        $balance = ($this->pay)($quote, VenueBookingCharge::RENTAL_BALANCE);
        expect(fn () => app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Done.', (string) Str::uuid()))->toThrow(ValidationException::class);

        $this->travelTo($this->eventStart->addHours(9));
        Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 55001, 'status' => 'pending']])]);
        app(StoreRefunds::class)->request($balance->order, $this->host, (string) Str::uuid(), 'Dispute.');
        expect(fn () => app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Done.', (string) Str::uuid()))->toThrow(ValidationException::class);
        expect(VenueSettlementReserve::query()->where('state', 'released')->count())->toBe(0);
    });

    test('only the venue host can complete a booking, and a settled rental can no longer be refunded here', function (): void {
        $quote = ($this->accepted)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);
        $paid = ($this->pay)($quote);
        ($this->fund)();
        $this->travelTo($this->eventStart->addHours(9));
        $stranger = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        expect(fn () => app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $stranger, 'Done.', (string) Str::uuid()))->toThrow(Exception::class);
        app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Done.', (string) Str::uuid());

        expect(fn () => app(StoreRefunds::class)->request($paid->order, $this->host, (string) Str::uuid(), 'Too late.'))->toThrow(ValidationException::class);
    });
});

it('refunding an extra rental capture preserves the valid paid booking', function (): void {
    $quote = ($this->accepted)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);
    $paid = ($this->pay)($quote);
    $extra = StorePaymentAttempt::factory()->create(['order_id' => $paid->order_id, 'status' => 'ready']);
    $review = ($this->capture)($extra);
    expect($review->processing_state)->toBe('review');
    ($this->fund)();
    Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 55001, 'status' => 'pending']])]);
    $refund = app(StoreRefunds::class)->request($paid->order, $this->host, (string) Str::uuid(), 'Return extra capture.', $review->id);
    ($this->refundFake)($refund);
    app(StoreRefunds::class)->reconcile($refund->fresh());
    expect($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
        ->and($paid->order->fresh()->payment_state)->toBe('paid');
});

it('an extra capture under review prevents settlement release', function (): void {
    $quote = ($this->accepted)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);
    $paid = ($this->pay)($quote);
    $extra = StorePaymentAttempt::factory()->create(['order_id' => $paid->order_id, 'status' => 'ready']);
    $review = ($this->capture)($extra);
    expect($review->processing_state)->toBe('review');
    $this->travelTo($this->eventStart->addHours(9));
    expect(fn () => app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Event ended.', (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('returning the sole late rental capture closes its review order', function (): void {
    $quote = ($this->accepted)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);
    $attempt = ($this->attemptFor)($quote);
    $capture = ($this->capture)($attempt, ['paid_at' => $quote->expires_at->toIso8601String()]);
    expect($capture->processing_state)->toBe('review');
    ($this->fund)();
    Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 55001, 'status' => 'pending']])]);
    $refund = app(StoreRefunds::class)->request($capture->order, $this->host, (string) Str::uuid(), 'Return late payment.', $capture->id);
    ($this->refundFake)($refund);
    $refund = app(StoreRefunds::class)->reconcile($refund->fresh());
    expect($refund->status)->toBe('confirmed')->and($capture->order->fresh()->payment_state)->toBe('refunded');
});
