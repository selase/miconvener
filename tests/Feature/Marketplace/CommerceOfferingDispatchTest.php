<?php

declare(strict_types=1);

use App\Contracts\CommerceOffering;
use App\Models\Feature;
use App\Models\StoreOrder;
use App\Models\StorePaymentObservation;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialDecision;
use App\Services\Marketplace\CommerceInventoryLocks;
use App\Services\Marketplace\CommerceOfferingRegistry;
use App\Services\Marketplace\CommerceOrderLocks;
use App\Services\Marketplace\PurchaseOffering;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\VenueOffering;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueRequestRollout;
use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    refreshTenantDatabases();
    $this->registry = app(CommerceOfferingRegistry::class);
    $this->session = fn (string $email = 'buyer@planner.test'): Store => tap(new Store('dispatch', new ArraySessionHandler(120)), fn (Store $s) => $s->put(proofFor($email)));
    $this->enableVenuePayments = function (Tenant $tenant, bool $requests = true, bool $payments = true): void {
        foreach ([VenueRequestRollout::FEATURE => $requests, VenuePaymentRollout::FEATURE => $payments] as $slug => $on) {
            Feature::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'type' => 'boolean', 'description' => 'test']);
            $tenant->features()->updateOrCreate(['feature_key' => $slug], ['enabled' => $on, 'meta' => ['source' => 'admin_override']]);
            Cache::forget('tenant_'.$tenant->id.'_feature_'.$slug);
        }
    };
    $this->payable = function (array $chargeAttributes = []): VenueBookingCharge {
        $charge = VenueBookingCharge::factory()->create($chargeAttributes);
        ($this->enableVenuePayments)($charge->booking->tenant);
        $quote = $charge->quote;
        VenueFinancialDecision::factory()->accepted($quote)->create();

        return $charge;
    };
});

describe('the registry', function (): void {
    test('a purchase order is served by the purchase offering', function (): void {
        $offering = $this->registry->for(StoreOrder::factory()->create());

        expect($offering)->toBeInstanceOf(PurchaseOffering::class)->toBeInstanceOf(CommerceOffering::class);
    });

    test('a reservation order with its venue charge is served by the venue offering', function (): void {
        $charge = VenueBookingCharge::factory()->create();

        expect($this->registry->for($charge->order))->toBeInstanceOf(VenueOffering::class);
    });

    test('the persisted order type decides, never the loaded copy', function (): void {
        $purchase = StoreOrder::factory()->create();
        $reservation = VenueBookingCharge::factory()->create()->order;
        $purchase->offering_type = 'reservation';
        $reservation->offering_type = 'purchase';

        expect($this->registry->for($purchase))->toBeInstanceOf(PurchaseOffering::class)
            ->and($this->registry->for($reservation))->toBeInstanceOf(VenueOffering::class);
    });

    test('an unknown type, an unsaved order and a reservation without its charge all fail closed', function (): void {
        expect(fn () => $this->registry->forType('subscription'))->toThrow(LogicException::class)
            ->and(fn () => $this->registry->for(new StoreOrder(['offering_type' => 'purchase'])))->toThrow(LogicException::class)
            ->and(fn () => $this->registry->for(StoreOrder::factory()->reservation()->create()))->toThrow(LogicException::class);
    });
});

describe('the lock entry points', function (): void {
    test('inventory locks serve purchases only and refuse a reservation order', function (): void {
        $purchase = StoreOrder::factory()->create();
        $reservation = VenueBookingCharge::factory()->create()->order;

        DB::connection('landlord')->transaction(function () use ($purchase, $reservation): void {
            expect(app(CommerceInventoryLocks::class)->order($purchase)->id)->toBe($purchase->id)
                ->and(fn () => app(CommerceInventoryLocks::class)->order($reservation))->toThrow(LogicException::class);
        });
    });

    test('a purchase takes tenant then order and a reservation takes tenant, listing, booking, charge, order', function (): void {
        $purchase = StoreOrder::factory()->create();
        $charge = VenueBookingCharge::factory()->create();
        $locked = function (StoreOrder $order): array {
            $db = DB::connection('landlord');
            $db->flushQueryLog();
            $db->enableQueryLog();
            $db->transaction(fn () => app(CommerceOrderLocks::class)->order($order));
            $db->disableQueryLog();

            return collect($db->getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/ for (no key )?update/', $sql) === 1)
                ->map(fn (string $sql) => (preg_match('/from "(\w+)"/', $sql, $m) ? $m[1] : $sql).(str_contains($sql, 'for no key update') ? ':no-key' : ':update'))->values()->all();
        };

        expect($locked($purchase))->toBe(['tenants:no-key', 'store_orders:update'])
            ->and($locked($charge->order))->toBe(['tenants:no-key', 'store_listings:update', 'venue_bookings:update', 'venue_booking_charges:update', 'store_orders:update']);
    });

    test('the money lock returns the locked reservation order', function (): void {
        $charge = VenueBookingCharge::factory()->create();

        DB::connection('landlord')->transaction(function () use ($charge): void {
            expect(app(CommerceOrderLocks::class)->order($charge->order)->id)->toBe($charge->order_id);
        });
    });
});

describe('the shared checkout', function (): void {
    test('refuses a reservation order while venue payments are off, without touching stock', function (): void {
        $charge = VenueBookingCharge::factory()->create();
        VenueFinancialDecision::factory()->accepted($charge->quote)->create();

        expect(fn () => app(StorePayments::class)->start($charge->order, ($this->session)(), (string) Str::uuid(), $charge->quote->terms_hash))->toThrow(ValidationException::class)
            ->and(DB::connection('landlord')->table('store_payment_attempts')->count())->toBe(0)
            ->and(DB::connection('landlord')->table('store_stock_allocations')->count())->toBe(0);
    });
});

describe('the venue offering', function (): void {
    test('releases a checkout only for the accepted contract, its verified buyer and an open hold', function (): void {
        $charge = ($this->payable)();
        $hash = $charge->quote->terms_hash;

        expect(fn () => $this->registry->for($charge->order)->assertCheckout($charge->order, ($this->session)(), $hash))->not->toThrow(ValidationException::class);
        foreach ([[($this->session)('other@planner.test'), $hash], [($this->session)(), hash('sha256', 'other terms')], [($this->session)(), null]] as [$session, $offered]) {
            expect(fn () => $this->registry->for($charge->order)->assertCheckout($charge->order, $session, $offered))->toThrow(ValidationException::class);
        }
    });

    test('refuses while the venue payment feature is off or the requests feature is off', function (): void {
        $charge = ($this->payable)();
        $check = fn () => $this->registry->for($charge->order)->assertCheckout($charge->order, ($this->session)(), $charge->quote->terms_hash);

        ($this->enableVenuePayments)($charge->booking->tenant, true, false);
        expect($check)->toThrow(ValidationException::class);
        ($this->enableVenuePayments)($charge->booking->tenant, false, true);
        expect($check)->toThrow(ValidationException::class);
    });

    test('refuses an unaccepted quote, a withdrawn quote, a closed booking and a lapsed initial window', function (): void {
        $unaccepted = VenueBookingCharge::factory()->create();
        ($this->enableVenuePayments)($unaccepted->booking->tenant);
        $withdrawn = VenueBookingCharge::factory()->create();
        ($this->enableVenuePayments)($withdrawn->booking->tenant);
        VenueFinancialDecision::factory()->withdrawn($withdrawn->quote)->create();
        $closed = ($this->payable)();
        $closed->booking->update(['status' => VenueBooking::STATUS_CANCELLED]);
        $lapsed = ($this->payable)();

        $cases = [$unaccepted, $withdrawn, $closed];
        foreach ($cases as $case) {
            expect(fn () => $this->registry->for($case->order)->assertCheckout($case->order, ($this->session)(), $case->quote->terms_hash))->toThrow(ValidationException::class);
        }
        $this->travelTo(CarbonImmutable::parse('2031-05-02 00:00:00'));
        expect(fn () => $this->registry->for($lapsed->order)->assertCheckout($lapsed->order, ($this->session)(), $lapsed->quote->terms_hash))->toThrow(ValidationException::class);
    });

    test('never lets a legacy review charge be paid through checkout', function (): void {
        $legacy = VenueBookingCharge::factory()->legacyReview()->create();
        ($this->enableVenuePayments)($legacy->booking->tenant);

        expect(fn () => $this->registry->for($legacy->order)->assertCheckout($legacy->order, ($this->session)(), null))->toThrow(ValidationException::class);
    });

    test('lets the balance and security be paid only once the booking is confirmed', function (): void {
        $initial = ($this->payable)();
        $balance = VenueBookingCharge::factory()->forQuote($initial->quote, 'rental_balance')->create();
        $security = VenueBookingCharge::factory()->forQuote($initial->quote, 'security')->create();
        $attempt = fn (VenueBookingCharge $charge) => fn () => $this->registry->for($charge->order)->assertCheckout($charge->order, ($this->session)(), $charge->quote->terms_hash);

        expect($attempt($balance))->toThrow(ValidationException::class)
            ->and($attempt($security))->toThrow(ValidationException::class);
        $initial->booking->update(['status' => VenueBooking::STATUS_CONFIRMED]);
        expect($attempt($balance))->not->toThrow(ValidationException::class)
            ->and($attempt($security))->not->toThrow(ValidationException::class);
    });

    test('starts a new initial attempt only while the dates are still free', function (): void {
        $charge = ($this->payable)();
        $offering = $this->registry->for($charge->order);

        expect(fn () => $offering->assertNewAttempt($charge->order))->not->toThrow(ValidationException::class);
        VenueBooking::factory()->create([
            'tenant_id' => $charge->tenant_id, 'shop_id' => $charge->shop_id, 'store_listing_id' => $charge->booking->store_listing_id,
            'status' => VenueBooking::STATUS_CONFIRMED, 'starts_at' => $charge->booking->starts_at, 'ends_at' => $charge->booking->ends_at,
        ]);
        expect(fn () => $offering->assertNewAttempt($charge->order))->toThrow(ValidationException::class);
    });

    test('reports why a received capture cannot take the booking', function (): void {
        $charge = ($this->payable)();
        $offering = $this->registry->for($charge->order);
        $capture = fn (string $paidAt = '2031-04-30 12:00:00') => new StorePaymentObservation(['paid_at' => $paidAt]);

        expect($offering->captureIssues($charge->order, $capture()))->toBe([])
            ->and($offering->captureIssues($charge->order, $capture('2031-05-01 00:00:01')))->toContain('capture_after_expiry');

        VenueBooking::factory()->create([
            'tenant_id' => $charge->tenant_id, 'shop_id' => $charge->shop_id, 'store_listing_id' => $charge->booking->store_listing_id,
            'status' => VenueBooking::STATUS_CONFIRMED, 'starts_at' => $charge->booking->starts_at, 'ends_at' => $charge->booking->ends_at,
        ]);
        expect($offering->captureIssues($charge->order, $capture()))->toBe(['calendar_conflict']);
    });

    test('reports an unaccepted contract and a closed booking on a capture', function (): void {
        $unaccepted = VenueBookingCharge::factory()->create();
        $closed = ($this->payable)();
        $closed->booking->update(['status' => VenueBooking::STATUS_CANCELLED]);
        $capture = new StorePaymentObservation(['paid_at' => '2031-04-30 12:00:00']);

        expect($this->registry->for($unaccepted->order)->captureIssues($unaccepted->order, $capture))->toContain('contract_not_accepted')
            ->and($this->registry->for($closed->order)->captureIssues($closed->order, $capture))->toContain('booking_closed');
    });

    test('allows a rental refund before settlement and refuses to apply a capture that was never verified', function (): void {
        $charge = ($this->payable)();
        $offering = $this->registry->for($charge->order);

        expect(fn () => $offering->assertRefund($charge->order))->not->toThrow(ValidationException::class)
            ->and(fn () => $offering->applyCapture($charge->order, new App\Models\StorePaymentAttempt, new StorePaymentObservation))->toThrow(Exception::class);
    });

    test('describes a reservation receipt without lines for goods', function (): void {
        $charge = ($this->payable)();
        $context = $this->registry->for($charge->order)->receiptContext($charge->order);

        expect($context)->toMatchArray(['currency' => 'GHS', 'total_pesewas' => $charge->amount_pesewas])
            ->and($context['lines'])->toHaveCount(1)
            ->and($context['lines'][0]['title'])->toContain('Venue')
            ->and($context['number'])->toBeString();
    });
});

describe('the venue payment switch', function (): void {
    test('is off for every organisation until its feature row is on, and needs requests too', function (): void {
        $tenant = VenueBookingCharge::factory()->create()->booking->tenant;
        $rollout = app(VenuePaymentRollout::class);

        expect($rollout->enabled($tenant))->toBeFalse();
        ($this->enableVenuePayments)($tenant, true, true);
        expect($rollout->enabled($tenant->fresh()))->toBeTrue();
        ($this->enableVenuePayments)($tenant, false, true);
        expect($rollout->enabled($tenant->fresh()))->toBeFalse();
    });
});
