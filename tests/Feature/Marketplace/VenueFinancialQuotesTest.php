<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\LedgerTransaction;
use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialDecision;
use App\Models\VenueFinancialQuote;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\VenueCharges;
use App\Services\Marketplace\VenueCommercialQuotes;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueRequestRollout;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    refreshTenantDatabases();
    config(['services.settlement.paystack.secret_key' => 'sk_test_venue_quotes']);
    $this->tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->listing = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'is_bookable' => true]);
    $this->fingerprint = hash('sha256', app(GivingGateways::class)->platformIdentity());
    $this->agreement = StoreCommercialAgreement::factory()->reservation()->create([
        'shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id, 'percentage' => '3.00', 'cap_pesewas' => null, 'account_fingerprint' => $this->fingerprint,
    ]);
    $this->enable = function (bool $requests = true, bool $payments = true): void {
        foreach ([VenueRequestRollout::FEATURE => $requests, VenuePaymentRollout::FEATURE => $payments] as $slug => $on) {
            Feature::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'type' => 'boolean', 'description' => 'test']);
            $this->tenant->features()->updateOrCreate(['feature_key' => $slug], ['enabled' => $on, 'meta' => ['source' => 'admin_override']]);
            Cache::forget('tenant_'.$this->tenant->id.'_feature_'.$slug);
        }
    };
    ($this->enable)();

    Permission::query()->firstOrCreate(['name' => 'manage venue', 'guard_name' => 'web'], ['category' => 'venue']);
    $this->host = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->host->givePermissionTo('manage venue');
    app(TenantContext::class)->setTenant($this->tenant);

    $this->eventStart = CarbonImmutable::now()->addDays(30)->setTime(9, 0);
    $this->heldBooking = function (array $overrides = []): VenueBooking {
        $holdUntil = CarbonImmutable::now()->addDays(3)->setMicroseconds(0);
        $booking = VenueBooking::factory()->create($overrides + [
            'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id,
            'status' => VenueBooking::STATUS_APPROVED_HOLD, 'approval_flow_version' => 1, 'hold_expires_at' => $holdUntil,
            'starts_at' => $this->eventStart, 'ends_at' => $this->eventStart->addHours(8), 'planner_email' => 'buyer@planner.test',
        ]);
        VenueBookingAction::factory()->create(['booking_id' => $booking->id, 'shop_id' => $booking->shop_id, 'tenant_id' => $booking->tenant_id, 'action' => VenueBookingAction::ACTION_APPROVED, 'hold_expires_at' => $booking->hold_expires_at]);

        return $booking;
    };
    $this->booking = ($this->heldBooking)();
    $this->input = fn (array $overrides = []): array => $overrides + [
        'rental_pesewas' => 100000, 'extras_pesewas' => 5000, 'security_pesewas' => 20000,
        'payment_plan' => 'deposit', 'deposit_pesewas' => 30000,
        'balance_due_at' => $this->eventStart->subDays(7)->toIso8601String(), 'security_due_at' => $this->eventStart->subDays(2)->toIso8601String(),
        'cancellation_terms' => 'Full refund of any instalment up to 14 days before the event.', 'support_contact' => 'bookings@host.example',
    ];
    $this->quotes = app(VenueCommercialQuotes::class);
    $this->issue = fn (array $overrides = [], ?VenueBooking $booking = null, ?string $key = null): VenueFinancialQuote => $this->quotes->issue($booking ?? $this->booking, $this->host, ($this->input)($overrides), $key ?? (string) Str::uuid());
    $this->session = fn (string $email = 'buyer@planner.test'): Store => tap(new Store('quotes', new ArraySessionHandler(120)), fn (Store $s) => $s->put(proofFor($email)));
    $this->accept = fn (VenueFinancialQuote $quote, ?string $hash = null, ?string $email = null, ?string $key = null): VenueFinancialDecision => $this->quotes->accept($quote, ($this->session)($email ?? 'buyer@planner.test'), $hash ?? $quote->terms_hash, $key ?? (string) Str::uuid());
});

describe('issuing a final quote', function (): void {
    test('records the exact amounts, the fee split, the schedule and the cited hold, and holds nothing', function (): void {
        $quote = ($this->issue)();

        expect($quote->fresh())
            ->rental_pesewas->toBe(100000)->extras_pesewas->toBe(5000)->security_pesewas->toBe(20000)
            ->initial_pesewas->toBe(30000)->balance_pesewas->toBe(75000)
            ->contract_fee_pesewas->toBe(3150)->initial_fee_pesewas->toBe(900)->balance_fee_pesewas->toBe(2250)
            ->currency->toBe('GHS')->version->toBe(1)->agreement_id->toBe($this->agreement->id)
            ->and($quote->expires_at->equalTo($this->booking->hold_expires_at))->toBeTrue()
            ->and($quote->holdAction->action)->toBe(VenueBookingAction::ACTION_APPROVED)
            ->and($quote->issued_by)->toBe((int) $this->host->id)
            ->and($quote->terms_hash)->toHaveLength(64)
            ->and(StoreOrder::query()->count())->toBe(0)
            ->and(VenueFinancialDecision::query()->count())->toBe(0)
            ->and(StorePaymentAttempt::query()->count())->toBe(0);
    });

    test('never changes the approved request snapshot or its indicative price', function (): void {
        $before = $this->booking->fresh()->only(['total_amount_pesewas', 'rental_amount_pesewas', 'contract_terms_snapshot', 'status']);

        ($this->issue)();

        expect($this->booking->fresh()->only(['total_amount_pesewas', 'rental_amount_pesewas', 'contract_terms_snapshot', 'status']))->toEqual($before);
    });

    test('prices a price-on-request booking from the host final amounts', function (): void {
        $booking = ($this->heldBooking)(['total_amount_pesewas' => 0, 'rental_amount_pesewas' => 0, 'contract_terms_snapshot' => ['is_price_on_request' => true, 'price_is_indicative' => true]]);

        expect(($this->issue)([], $booking)->rental_pesewas)->toBe(100000);
    });

    test('full payment pays everything at once with no balance and no balance date', function (): void {
        $quote = ($this->issue)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);

        expect($quote->fresh())->initial_pesewas->toBe(105000)->balance_pesewas->toBe(0)->initial_fee_pesewas->toBe(3150)->balance_fee_pesewas->toBe(0)
            ->and($quote->balance_due_at)->toBeNull();
    });

    test('a deposit equal to the rental total is full payment', function (): void {
        $quote = ($this->issue)(['deposit_pesewas' => 105000, 'balance_due_at' => null]);

        expect($quote->fresh())->balance_pesewas->toBe(0)->and($quote->balance_due_at)->toBeNull();
    });

    test('security is separate, earns no commission and needs its own due date', function (): void {
        $withSecurity = ($this->issue)();
        $without = ($this->issue)(['security_pesewas' => 0, 'security_due_at' => null], ($this->heldBooking)());

        expect($withSecurity->contract_fee_pesewas)->toBe($without->contract_fee_pesewas)->toBe(3150)
            ->and($without->security_due_at)->toBeNull()
            ->and($withSecurity->security_due_at)->not->toBeNull();
    });

    test('commission rounding and the cap stay exact across both instalments', function (int $total, int $deposit, string $percentage, ?int $cap, int $contractFee, int $initialFee, int $balanceFee): void {
        $this->agreement->tenant->update([]);
        StoreCommercialAgreement::factory()->reservation()->create([
            'shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id, 'version' => 2, 'percentage' => $percentage, 'cap_pesewas' => $cap, 'account_fingerprint' => $this->fingerprint,
        ]);

        $quote = ($this->issue)(['rental_pesewas' => $total, 'extras_pesewas' => 0, 'security_pesewas' => 0, 'security_due_at' => null, 'deposit_pesewas' => $deposit, 'payment_plan' => $deposit === $total ? 'full' : 'deposit', 'balance_due_at' => $deposit === $total ? null : $this->eventStart->subDays(7)->toIso8601String()]);

        expect([$quote->contract_fee_pesewas, $quote->initial_fee_pesewas, $quote->balance_fee_pesewas])->toBe([$contractFee, $initialFee, $balanceFee])
            ->and($quote->initial_fee_pesewas + $quote->balance_fee_pesewas)->toBe($quote->contract_fee_pesewas);
    })->with([
        'plain split' => [105000, 30000, '3.00', null, 3150, 900, 2250],
        'rounding' => [10001, 3333, '3.00', null, 300, 100, 200],
        'cap applies once, below the first charge fee' => [1000000, 100000, '3.00', 5000, 5000, 3000, 2000],
        'cap smaller than the first instalment fee' => [1000000, 500000, '3.00', 5000, 5000, 5000, 0],
        'waiver' => [105000, 30000, '0.00', null, 0, 0, 0],
        'one pesewa' => [1, 1, '3.00', null, 0, 0, 0],
    ]);

    test('uses the reservation agreement and never the merchandise one', function (): void {
        StoreCommercialAgreement::factory()->create(['shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id, 'version' => 2, 'percentage' => '9.00', 'account_fingerprint' => $this->fingerprint]);

        expect(($this->issue)()->contract_fee_pesewas)->toBe(3150);
    });
});

describe('what a quote refuses', function (): void {
    test('amounts, deposit and deadlines outside their bounds', function (array $overrides): void {
        expect(fn () => ($this->issue)($overrides))->toThrow(ValidationException::class)
            ->and(VenueFinancialQuote::query()->count())->toBe(0);
    })->with([
        'negative rental' => [['rental_pesewas' => -1]],
        'negative security' => [['security_pesewas' => -1]],
        'absurd total' => [['rental_pesewas' => 9007199254740991, 'extras_pesewas' => 1]],
        'deposit of nothing' => [['deposit_pesewas' => 0]],
        'deposit above the rental' => [['deposit_pesewas' => 105001]],
        'deposit plan without an amount' => [['deposit_pesewas' => null]],
        'balance without a due date' => [['balance_due_at' => null]],
        'balance due in the past' => [['balance_due_at' => '2020-01-01T00:00:00+00:00']],
        'balance due after the event starts' => [['balance_due_at' => '2099-01-01T00:00:00+00:00']],
        'security without a due date' => [['security_due_at' => null]],
        'security due after the event starts' => [['security_due_at' => '2099-01-01T00:00:00+00:00']],
        'no cancellation terms' => [['cancellation_terms' => '  ']],
        'no support contact' => [['support_contact' => '']],
        'unknown payment plan' => [['payment_plan' => 'instalments']],
    ]);

    test('a host without venue authority, another organisation host, or an outsider', function (): void {
        $plain = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherTenant = Tenant::factory()->create();
        $foreignHost = User::factory()->create(['tenant_id' => $otherTenant->id]);
        setPermissionsTeamId($otherTenant->id);
        $foreignHost->givePermissionTo('manage venue');
        setPermissionsTeamId($this->tenant->id);

        foreach ([$plain, $foreignHost] as $actor) {
            expect(fn () => $this->quotes->issue($this->booking, $actor, ($this->input)(), (string) Str::uuid()))->toThrow(AuthorizationException::class);
        }
        expect(VenueFinancialQuote::query()->count())->toBe(0);
    });

    test('a booking that is not a live approved hold', function (): void {
        $pending = ($this->heldBooking)(['status' => VenueBooking::STATUS_PENDING_HOST_APPROVAL]);
        $legacy = ($this->heldBooking)(['approval_flow_version' => null]);
        $expired = ($this->heldBooking)(['hold_expires_at' => now()->subMinute()]);
        $declined = ($this->heldBooking)(['status' => VenueBooking::STATUS_DECLINED]);

        foreach ([$pending, $legacy, $expired, $declined] as $booking) {
            expect(fn () => ($this->issue)([], $booking))->toThrow(ValidationException::class);
        }
    });

    test('when venue payments or requests are off, or the agreement is missing or charges a buyer fee', function (): void {
        ($this->enable)(true, false);
        expect(fn () => ($this->issue)())->toThrow(ValidationException::class);
        ($this->enable)(false, true);
        expect(fn () => ($this->issue)())->toThrow(ValidationException::class);
        ($this->enable)();

        $bare = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
        $bareShop = Shop::factory()->create(['tenant_id' => $bare->id, 'is_active' => true]);
        $bareListing = StoreListing::factory()->create(['shop_id' => $bareShop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED]);
        $bareHost = User::factory()->create(['tenant_id' => $bare->id]);
        foreach ([VenueRequestRollout::FEATURE, VenuePaymentRollout::FEATURE] as $slug) {
            $bare->features()->updateOrCreate(['feature_key' => $slug], ['enabled' => true, 'meta' => ['source' => 'admin_override']]);
        }
        setPermissionsTeamId($bare->id);
        $bareHost->givePermissionTo('manage venue');
        app(TenantContext::class)->setTenant($bare);
        $unagreed = VenueBooking::factory()->create(['tenant_id' => $bare->id, 'shop_id' => $bareShop->id, 'store_listing_id' => $bareListing->id, 'status' => VenueBooking::STATUS_APPROVED_HOLD, 'approval_flow_version' => 1, 'hold_expires_at' => now()->addDays(3), 'starts_at' => $this->eventStart, 'ends_at' => $this->eventStart->addHours(8)]);
        VenueBookingAction::factory()->create(['booking_id' => $unagreed->id, 'shop_id' => $unagreed->shop_id, 'tenant_id' => $unagreed->tenant_id, 'action' => 'approved', 'hold_expires_at' => $unagreed->hold_expires_at]);
        expect(fn () => $this->quotes->issue($unagreed, $bareHost, ($this->input)(), (string) Str::uuid()))->toThrow(ValidationException::class);

        app(TenantContext::class)->setTenant($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        StoreCommercialAgreement::factory()->reservation()->create(['shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id, 'version' => 2, 'fee_bearer' => 'attendee', 'account_fingerprint' => $this->fingerprint]);
        expect(fn () => ($this->issue)())->toThrow(ValidationException::class);
    });
});

describe('replacing and withdrawing', function (): void {
    test('a new version replaces the old one before acceptance, and only the latest can be accepted', function (): void {
        $first = ($this->issue)();
        $second = ($this->issue)(['rental_pesewas' => 90000]);

        expect($second->version)->toBe(2)
            ->and(VenueFinancialDecision::query()->where('quote_id', $first->id)->sole())->decision->toBe('withdrawn')->reason->toContain('version 2')
            ->and(fn () => ($this->accept)($first))->toThrow(ValidationException::class);
        expect(($this->accept)($second)->decision)->toBe('accepted');
    });

    test('the host can withdraw a quote with a reason, and a replay returns the same withdrawal', function (): void {
        $quote = ($this->issue)();
        $key = (string) Str::uuid();

        $first = $this->quotes->withdraw($quote, $this->host, 'Dates changed.', $key);
        $again = $this->quotes->withdraw($quote, $this->host, 'Dates changed.', $key);

        expect($again->id)->toBe($first->id)
            ->and(VenueFinancialDecision::query()->count())->toBe(1)
            ->and(fn () => $this->quotes->withdraw($quote, $this->host, '', (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => ($this->accept)($quote))->toThrow(ValidationException::class);
    });

    test('nothing can be replaced or withdrawn after the buyer accepted', function (): void {
        $quote = ($this->issue)();
        ($this->accept)($quote);

        expect(fn () => $this->quotes->withdraw($quote, $this->host, 'Too late.', (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => ($this->issue)(['rental_pesewas' => 1]))->toThrow(ValidationException::class);
    });

    test('only an owning host may withdraw', function (): void {
        $quote = ($this->issue)();
        $plain = User::factory()->create(['tenant_id' => $this->tenant->id]);

        expect(fn () => $this->quotes->withdraw($quote, $plain, 'Nope.', (string) Str::uuid()))->toThrow(AuthorizationException::class);
    });
});

describe('idempotent keys', function (): void {
    test('repeating an issue with the same key and content returns the same quote', function (): void {
        $key = (string) Str::uuid();

        $first = ($this->issue)([], null, $key);
        $again = ($this->issue)([], null, $key);

        expect($again->id)->toBe($first->id)->and(VenueFinancialQuote::query()->count())->toBe(1);
    });

    test('the same key with different content is refused rather than becoming a second quote', function (): void {
        $key = (string) Str::uuid();
        ($this->issue)([], null, $key);

        expect(fn () => ($this->issue)(['rental_pesewas' => 1], null, $key))->toThrow(ValidationException::class)
            ->and(VenueFinancialQuote::query()->count())->toBe(1);
    });

    test('accepting twice with one key returns the one decision and one set of orders', function (): void {
        $quote = ($this->issue)();
        $key = (string) Str::uuid();

        $first = ($this->accept)($quote, null, null, $key);
        $again = ($this->accept)($quote, null, null, $key);

        expect($again->id)->toBe($first->id)
            ->and(VenueFinancialDecision::query()->count())->toBe(1)
            ->and(VenueBookingCharge::query()->count())->toBe(3)
            ->and(fn () => ($this->accept)($quote))->toThrow(ValidationException::class);
    });
});

describe('accepting a quote', function (): void {
    test('needs the verified email that made the request, the exact terms and the live quote', function (): void {
        $quote = ($this->issue)();

        expect(fn () => ($this->accept)($quote, null, 'someone@else.test'))->toThrow(ValidationException::class)
            ->and(fn () => ($this->accept)($quote, hash('sha256', 'forged amounts')))->toThrow(ValidationException::class)
            ->and(fn () => $this->quotes->accept($quote, new Store('empty', new ArraySessionHandler(120)), $quote->terms_hash, (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(VenueFinancialDecision::query()->count())->toBe(0)
            ->and(StoreOrder::query()->count())->toBe(0);
    });

    test('is refused once the quote or the hold has run out, or when the dates were taken', function (): void {
        $expiring = ($this->issue)();
        $taken = ($this->issue)([], ($this->heldBooking)(['starts_at' => $this->eventStart->addDays(2), 'ends_at' => $this->eventStart->addDays(2)->addHours(8)]));
        VenueBooking::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id, 'status' => VenueBooking::STATUS_CONFIRMED, 'starts_at' => $this->eventStart->addDays(2), 'ends_at' => $this->eventStart->addDays(2)->addHours(8)]);

        expect(fn () => ($this->accept)($taken))->toThrow(ValidationException::class);
        $this->travel(4)->days();
        expect(fn () => ($this->accept)($expiring))->toThrow(ValidationException::class);
    });

    test('creates one unpaid reservation order per due charge, for exactly the quoted amounts and fees', function (): void {
        $quote = ($this->issue)();

        ($this->accept)($quote);

        $charges = VenueBookingCharge::query()->where('quote_id', $quote->id)->get()->keyBy('purpose');
        expect($charges->keys()->sort()->values()->all())->toBe(['rental_balance', 'rental_initial', 'security'])
            ->and($charges['rental_initial'])->amount_pesewas->toBe(30000)->fee_pesewas->toBe(900)
            ->and($charges['rental_balance'])->amount_pesewas->toBe(75000)->fee_pesewas->toBe(2250)
            ->and($charges['security'])->amount_pesewas->toBe(20000)->fee_pesewas->toBe(0)
            ->and($charges['rental_balance']->due_at->equalTo($quote->balance_due_at))->toBeTrue()
            ->and($charges['security']->due_at->equalTo($quote->security_due_at))->toBeTrue();
        foreach ($charges as $charge) {
            expect($charge->order)->offering_type->toBe('reservation')->state->toBe('quoted')->payment_state->toBe('awaiting')
                ->principal_pesewas->toBe($charge->amount_pesewas)->platform_fee_pesewas->toBe($charge->fee_pesewas)->charged_pesewas->toBe($charge->amount_pesewas)
                ->fee_bearer->toBe('organizer')->buyer_email->toBe('buyer@planner.test')->agreement_id->toBe($this->agreement->id)
                ->and($charge->order->terms_snapshot['refund_gateway_fee_bearer'])->toBe($this->agreement->refund_gateway_fee_bearer)
                ->and($charge->order->lines)->toHaveCount(1);
        }
    });

    test('moves no money, confirms nothing, issues no receipt and posts no ledger entry', function (): void {
        ($this->accept)(($this->issue)());

        expect($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD)
            ->and(StorePaymentAttempt::query()->count())->toBe(0)
            ->and(DB::connection('landlord')->table('store_receipts')->count())->toBe(0)
            ->and(LedgerTransaction::query()->count())->toBe(0)
            ->and(StoreOrder::query()->whereNotNull('paid_at')->count())->toBe(0);
    });

    test('a later rate change never reprices an accepted contract, only a new quote', function (): void {
        $quote = ($this->issue)();
        ($this->accept)($quote);
        StoreCommercialAgreement::factory()->reservation()->create(['shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id, 'version' => 2, 'percentage' => '10.00', 'account_fingerprint' => $this->fingerprint]);

        expect((int) VenueBookingCharge::query()->where('quote_id', $quote->id)->sum('fee_pesewas'))->toBe(3150)
            ->and(($this->issue)([], ($this->heldBooking)())->contract_fee_pesewas)->toBe(10500);
    });

    test('a contract with nothing to pay confirms the booking on acceptance, with no order, attempt or ledger entry', function (): void {
        $quote = ($this->issue)(['rental_pesewas' => 0, 'extras_pesewas' => 0, 'security_pesewas' => 0, 'payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null, 'security_due_at' => null]);

        ($this->accept)($quote);

        expect($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
            ->and(StoreOrder::query()->count())->toBe(0)
            ->and(VenueBookingCharge::query()->count())->toBe(0)
            ->and(LedgerTransaction::query()->count())->toBe(0);
    });

    test('a rental of nothing with a security amount confirms the booking and leaves only the security to pay', function (): void {
        $quote = ($this->issue)(['rental_pesewas' => 0, 'extras_pesewas' => 0, 'payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);

        ($this->accept)($quote);

        expect($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
            ->and(VenueBookingCharge::query()->pluck('purpose')->all())->toBe(['security']);
    });

    test('a zero-price acceptance still rechecks that the dates are free', function (): void {
        $quote = ($this->issue)(['rental_pesewas' => 0, 'extras_pesewas' => 0, 'security_pesewas' => 0, 'payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null, 'security_due_at' => null]);
        VenueBooking::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id, 'status' => VenueBooking::STATUS_CONFIRMED, 'starts_at' => $this->booking->starts_at, 'ends_at' => $this->booking->ends_at]);

        expect(fn () => ($this->accept)($quote))->toThrow(ValidationException::class)
            ->and($this->booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD);
    });
});

describe('charges', function (): void {
    test('an order exists once per purpose, only for an accepted quote, with a server-set amount', function (): void {
        $quote = ($this->issue)();
        $charges = app(VenueCharges::class);

        expect(fn () => $charges->order($quote, 'rental_initial'))->toThrow(ValidationException::class);
        ($this->accept)($quote);
        $first = $charges->order($quote, 'rental_initial');

        expect($charges->order($quote, 'rental_initial')->id)->toBe($first->id)
            ->and(fn () => $charges->order($quote, 'deposit'))->toThrow(InvalidArgumentException::class)
            ->and(VenueBookingCharge::query()->where('quote_id', $quote->id)->where('purpose', 'rental_initial')->count())->toBe(1);
    });

    test('a purpose the contract does not need creates nothing', function (): void {
        $quote = ($this->issue)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);
        ($this->accept)($quote);

        expect(fn () => app(VenueCharges::class)->order($quote, 'rental_balance'))->toThrow(ValidationException::class)
            ->and(VenueBookingCharge::query()->where('quote_id', $quote->id)->count())->toBe(2);
    });
});

it('refuses acceptance at the exact quote expiry instant', function (): void {
    $quote = ($this->issue)();

    $this->travelTo($quote->expires_at);

    expect(fn () => ($this->accept)($quote))->toThrow(ValidationException::class);
    expect(VenueFinancialDecision::query()->where('quote_id', $quote->id)->count())->toBe(0);
});

it('refuses acceptance at the exact hold deadline', function (): void {
    $quote = ($this->issue)();
    $quote->forceFill(['expires_at' => $quote->hold_expires_at]);

    $this->travelTo($this->booking->fresh()->hold_expires_at);

    expect(fn () => ($this->accept)($quote))->toThrow(ValidationException::class);
});

it('validates acceptance against the persisted quote, not the supplied model', function (): void {
    $quote = ($this->issue)();
    $forged = hash('sha256', 'forged');
    $tampered = $quote->replicate();
    $tampered->id = $quote->id;
    $tampered->terms_hash = $forged;

    expect(fn () => ($this->accept)($tampered, $forged))->toThrow(ValidationException::class);
    expect(VenueFinancialDecision::query()->where('quote_id', $quote->id)->count())->toBe(0);
    expect(($this->accept)($quote)->decision)->toBe(VenueFinancialDecision::ACCEPTED);
});
