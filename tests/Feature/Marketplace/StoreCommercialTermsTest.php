<?php

declare(strict_types=1);

use App\Models\StoreCommercialAgreement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\FeeTerms;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\StoreCommercialTerms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_store_only']);
});

it('refuses to infer commission from events or legacy marketplace defaults', function (): void {
    app(StoreCommercialTerms::class)->resolve(Tenant::factory()->create());
})->throws(ValidationException::class);

it('resolves an explicit zero agreement without silently replacing its waiver', function (): void {
    $agreement = StoreCommercialAgreement::factory()->create([
        'percentage' => '0.00', 'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity()),
    ]);
    $agreement->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    expect(app(StoreCommercialTerms::class)->resolve($agreement->tenant)->id)->toBe($agreement->id)
        ->and(app(StoreCommercialTerms::class)->resolve($agreement->tenant)->percentage)->toBe('0.00');
});

it('refuses own gateway mode and a rotated original platform account', function (bool $own): void {
    $agreement = StoreCommercialAgreement::factory()->create(['account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
    $agreement->tenant->update(['settlement_mode' => $own ? Tenant::SETTLEMENT_MODE_OWN_GATEWAY : Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    if (! $own) {
        config(['services.settlement.paystack.secret_key' => 'sk_test_changed']);
    }
    app(StoreCommercialTerms::class)->resolve($agreement->tenant->fresh());
})->with([true, false])->throws(ValidationException::class);

it('does not let a tenant owner approve their own fee by changing email', function (): void {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    makeTenantOwner($actor, $tenant);
    $actor->update(['email' => 'platform-owner@example.test']);
    app(StoreCommercialTerms::class)->approve($tenant, $actor, []);
})->throws(AuthorizationException::class);

it('calculates shared fees without an event and preserves cap waiver and rounding', function (int $principal, float $percentage, ?int $cap, string $bearer, int $fee, int $charged, int $gateway): void {
    $split = app(FeeCalculator::class)->forTerms($principal, new FeeTerms($percentage, $cap, $bearer, 1.95));
    expect($split->platformFee)->toBe($fee)->and($split->chargedAmount)->toBe($charged)
        ->and($split->gatewayFeeEstimate)->toBe($gateway)->and($split->organizerNet)->toBe($charged - $fee - $gateway);
})->with([
    [10000, 2.0, null, 'organizer', 200, 10000, 195],
    [10000, 2.0, null, 'attendee', 200, 10200, 199],
    [2525, 2.0, null, 'organizer', 51, 2525, 49],
    [150000, 2.0, 2000, 'organizer', 2000, 150000, 2925],
    [10000, 0.0, null, 'organizer', 0, 10000, 195],
    [0, 2.0, null, 'organizer', 0, 0, 0],
]);

it('rejects unsafe totals and malformed fee terms', function (int $principal, float $rate, ?int $cap, string $bearer): void {
    app(FeeCalculator::class)->forTerms($principal, new FeeTerms($rate, $cap, $bearer, 1.95));
})->with([
    [-1, 2.0, null, 'organizer'], [PHP_INT_MAX, 2.0, null, 'organizer'],
    [100, NAN, null, 'organizer'], [100, 101.0, null, 'organizer'],
    [100, 2.0, -1, 'organizer'], [100, 2.0, null, 'seller-input'],
])->throws(InvalidArgumentException::class);

it('lets only a global superadmin approve a new immutable policy version through the console', function (): void {
    $agreement = StoreCommercialAgreement::factory()->create(['account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
    $seller = $agreement->tenant;
    $seller->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $root = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $root->assignRole('Superadmin');
    $data = $agreement->only(['percentage', 'cap_pesewas', 'fee_bearer', 'gateway_estimate_rate', 'refund_gateway_fee_bearer', 'collection_terms', 'refund_terms', 'support_contact', 'receipt_terms', 'payout_policy']);
    $data['percentage'] = '0.00';
    $this->actingAs($root)->get(route('admin.store-agreements.index', $seller))->assertOk()->assertSee('Commerce agreement');
    $this->actingAs($root)->post(route('admin.store-agreements.store', $seller), $data)->assertRedirect();
    $new = app(StoreCommercialTerms::class)->resolve($seller);
    expect($new->version)->toBe(2)->and($new->percentage)->toBe('0.00')->and($agreement->fresh()->percentage)->toBe('2.00');
    $data['refund_gateway_fee_bearer'] = null;
    $this->actingAs($root)->post(route('admin.store-agreements.store', $seller), $data)->assertSessionHasErrors('refund_gateway_fee_bearer');
});

it('resolves each scope independently and defaults to the purchase agreement', function (): void {
    $purchase = StoreCommercialAgreement::factory()->create(['account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
    $reservation = StoreCommercialAgreement::factory()->reservation()->create([
        'shop_id' => $purchase->shop_id, 'tenant_id' => $purchase->tenant_id, 'version' => 2,
        'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity()),
    ]);
    $purchase->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $terms = app(StoreCommercialTerms::class);

    expect($terms->resolve($purchase->tenant)->id)->toBe($purchase->id)
        ->and($terms->resolve($purchase->tenant, 'purchase')->id)->toBe($purchase->id)
        ->and($terms->resolve($purchase->tenant, 'reservation')->id)->toBe($reservation->id);
});

it('does not let a venue reservation agreement stand in for missing merchandise terms', function (): void {
    $reservation = StoreCommercialAgreement::factory()->reservation()->create(['account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
    $reservation->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);

    expect(fn () => app(StoreCommercialTerms::class)->resolve($reservation->tenant))->toThrow(ValidationException::class)
        ->and(app(StoreCommercialTerms::class)->resolve($reservation->tenant, 'reservation')->id)->toBe($reservation->id);
});

it('approves a venue agreement as a new version without touching the active purchase agreement', function (): void {
    $purchase = StoreCommercialAgreement::factory()->create(['percentage' => '2.00', 'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
    $tenant = $purchase->tenant;
    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $admin = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole('Superadmin');
    $input = ['percentage' => '3.00', 'cap_pesewas' => null, 'fee_bearer' => 'organizer', 'gateway_estimate_rate' => '1.95', 'refund_gateway_fee_bearer' => 'seller',
        'collection_terms' => 'Access to the booked venue.', 'refund_terms' => 'Per the accepted quote.', 'support_contact' => 'support@example.test', 'receipt_terms' => 'Payment receipt.',
        'payout_policy' => ['holdback_pesewas' => 0, 'manual_release' => true]];

    $venue = app(StoreCommercialTerms::class)->approve($tenant->fresh(), $admin, $input, 'reservation');

    expect($venue->offering_scope)->toBe('reservation')->and($venue->version)->toBe(2)->and($venue->percentage)->toBe('3.00')
        ->and(app(StoreCommercialTerms::class)->resolve($tenant->fresh())->id)->toBe($purchase->id)
        ->and(app(StoreCommercialTerms::class)->resolve($tenant->fresh())->percentage)->toBe('2.00');
});

it('refuses an unknown agreement scope', function (): void {
    app(StoreCommercialTerms::class)->resolve(Tenant::factory()->create(), 'subscription');
})->throws(InvalidArgumentException::class);
