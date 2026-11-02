<?php

declare(strict_types=1);

use App\Libraries\RolePermissions;
use App\Models\Feature;
use App\Models\LedgerTransaction;
use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialQuote;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\StoreRefunds;
use App\Services\Marketplace\VenueCommercialQuotes;
use App\Services\Marketplace\VenuePaymentReconciliation;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueRequestRollout;
use App\Services\Marketplace\VenueSettlementRelease;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
    foreach (['manage venue refunds', 'reconcile venue payments', 'release venue settlements', 'manage store finance'] as $name) {
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['category' => 'venue']);
    }
    $this->sales = fn (): int => LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count();
});

describe('the venue-money permissions', function (): void {
    test('are default only for Superadmin and Org Superadmin', function (): void {
        $defaults = RolePermissions::defaults();
        foreach (['manage venue refunds', 'reconcile venue payments', 'release venue settlements'] as $permission) {
            expect($defaults['Superadmin'])->toContain($permission)->and($defaults['Org Superadmin'])->toContain($permission)
                ->and($defaults['Org Admin'])->not->toContain($permission)->and($defaults['Event Staff'])->not->toContain($permission);
        }
    });

    test('manage venue alone cannot refund, reconcile or release', function (): void {
        $quote = ($this->accepted)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);
        $paid = ($this->capture)(($this->attemptFor)($quote));
        $action = VenueBookingAction::factory()->create(['booking_id' => $this->booking->id, 'action' => VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT, 'evidence' => ['provider_reference' => 'x', 'amount_pesewas' => 100, 'currency' => 'GHS']]);
        $this->travelTo($this->eventStart->addHours(9));

        expect(fn () => app(StoreRefunds::class)->request($paid->order, $this->host, (string) Str::uuid(), 'Cancelled.'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(VenuePaymentReconciliation::class)->verify($action, $this->host, (string) Str::uuid()))->toThrow(AuthorizationException::class)
            ->and(fn () => app(VenueSettlementRelease::class)->complete($this->booking->fresh(), $this->host, 'Done.', (string) Str::uuid()))->toThrow(AuthorizationException::class);
    });

    test('store finance does not reach venue charges', function (): void {
        $quote = ($this->accepted)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);
        $paid = ($this->capture)(($this->attemptFor)($quote));
        $this->host->givePermissionTo('manage store finance');

        expect(fn () => app(StoreRefunds::class)->request($paid->order, $this->host, (string) Str::uuid(), 'Cancelled.'))->toThrow(AuthorizationException::class);
        $this->host->givePermissionTo('manage venue refunds');
        expect(app(StoreRefunds::class)->request($paid->order, $this->host, (string) Str::uuid(), 'Cancelled.')->amount_pesewas)->toBe(105000);
    });

    test('are checked in the owning organisation only', function (): void {
        $quote = ($this->accepted)(['payment_plan' => 'full', 'deposit_pesewas' => null, 'balance_due_at' => null]);
        $paid = ($this->capture)(($this->attemptFor)($quote));
        $other = Tenant::factory()->create();
        $outsider = User::factory()->create(['tenant_id' => $other->id]);
        setPermissionsTeamId($other->id);
        $outsider->givePermissionTo('manage venue refunds');
        setPermissionsTeamId($this->tenant->id);

        expect(fn () => app(StoreRefunds::class)->request($paid->order, $outsider, (string) Str::uuid(), 'Cancelled.'))->toThrow(AuthorizationException::class);
    });
});

describe('venue:enable-payments', function (): void {
    beforeEach(function (): void {
        $this->tenant->features()->where('feature_key', VenuePaymentRollout::FEATURE)->delete();
        Cache::forget('tenant_'.$this->tenant->id.'_feature_'.VenuePaymentRollout::FEATURE);
    });

    test('enables payments once every prerequisite holds, and turns them off again', function (): void {
        $this->artisan('venue:enable-payments', ['tenant' => $this->tenant->slug])->assertSuccessful();
        expect(app(VenuePaymentRollout::class)->enabled($this->tenant->fresh()))->toBeTrue();

        $this->artisan('venue:enable-payments', ['tenant' => $this->tenant->slug, '--off' => true])->assertSuccessful();
        expect(app(VenuePaymentRollout::class)->enabled($this->tenant->fresh()))->toBeFalse();
    });

    test('refuses and enables nothing when a prerequisite is missing', function (): void {
        $bare = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
        $this->artisan('venue:enable-requests', ['tenant' => $bare->slug])->assertSuccessful();

        $this->artisan('venue:enable-payments', ['tenant' => $bare->slug])->assertFailed();
        expect(app(VenuePaymentRollout::class)->enabled($bare->fresh()))->toBeFalse()
            ->and($bare->features()->where('feature_key', VenuePaymentRollout::FEATURE)->exists())->toBeFalse();
    });

    test('refuses an organisation on its own gateway and an unknown organisation', function (): void {
        $this->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

        $this->artisan('venue:enable-payments', ['tenant' => $this->tenant->slug])->assertFailed();
        $this->artisan('venue:enable-payments', ['tenant' => 'no-such-organisation'])->assertFailed();
    });
});
