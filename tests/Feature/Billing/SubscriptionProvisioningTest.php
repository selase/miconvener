<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\PlanChange;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionProvisioningService;

function makePackage(string $slug, int $sortOrder = 0, bool $isFree = false): Package
{
    return Package::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'price' => $isFree ? 0 : ($sortOrder * 19),
        'yearly_price' => $isFree ? 0 : ($sortOrder * 190),
        'interval' => 'month',
        'billing_model' => 'flat_rate',
        'description' => "{$slug} plan",
        'is_active' => true,
        'is_free' => $isFree,
        'sort_order' => $sortOrder,
        'paystack_plan_code' => $isFree ? null : "PLN_{$slug}_monthly",
    ]);
}

// ── provision() ──────────────────────────────────────────────────────────────

it('provisions a new subscription and updates tenant package', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $package = makePackage('pro', 1);
    $service = app(SubscriptionProvisioningService::class);

    $service->provision($tenant, [
        'package_id' => $package->id,
        'provider' => 'paystack',
        'provider_subscription_id' => 'SUB_001',
        'provider_plan_id' => 'PLN_pro_monthly',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
        'amount_paid' => 1900,
        'currency' => 'usd',
        'transaction_id' => 'TRX_001',
    ]);

    $tenant->refresh();
    expect((int) $tenant->package_id)->toBe((int) $package->id);
    expect(Subscription::where('tenant_id', $tenant->id)->exists())->toBeTrue();
    expect(PlanChange::where('tenant_id', $tenant->id)->where('type', 'upgrade')->exists())->toBeTrue();
});

it('provision logs a transaction record', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $package = makePackage('pro', 1);
    $service = app(SubscriptionProvisioningService::class);

    $service->provision($tenant, [
        'package_id' => $package->id,
        'provider' => 'paystack',
        'provider_subscription_id' => 'SUB_TXN_001',
        'provider_plan_id' => 'PLN_pro_monthly',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
        'amount_paid' => 1900,
        'currency' => 'usd',
        'transaction_id' => 'TRX_TXN_001',
    ]);

    expect(App\Models\Transaction::where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

// ── upgrade() ────────────────────────────────────────────────────────────────

it('upgrade immediately switches tenant to new package', function () {
    $user = User::factory()->create();
    $freePackage = makePackage('free', 0, true);
    $tenant = setActiveTenantForTest($user, ['package_id' => $freePackage->id]);
    $proPackage = makePackage('pro', 1);
    $service = app(SubscriptionProvisioningService::class);

    $service->upgrade($tenant, $proPackage);

    $tenant->refresh();
    expect((int) $tenant->package_id)->toBe((int) $proPackage->id);
});

it('upgrade logs a plan change record', function () {
    $user = User::factory()->create();
    $freePackage = makePackage('free', 0, true);
    $tenant = setActiveTenantForTest($user, ['package_id' => $freePackage->id]);
    $proPackage = makePackage('pro', 1);
    $service = app(SubscriptionProvisioningService::class);

    $service->upgrade($tenant, $proPackage);

    expect(PlanChange::where('tenant_id', $tenant->id)
        ->where('type', 'upgrade')
        ->where('to_package_id', $proPackage->id)
        ->exists()
    )->toBeTrue();
});

it('upgrade clears pending downgrade', function () {
    $user = User::factory()->create();
    $proPackage = makePackage('pro', 1);
    $bizPackage = makePackage('business', 2);
    $tenant = setActiveTenantForTest($user, ['package_id' => $bizPackage->id]);

    // Create subscription with pending downgrade
    Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'SUB_CLR',
        'provider_status' => 'active',
        'provider_plan' => 'PLN_business',
        'current_period_end' => now()->addMonth(),
        'pending_package_id' => $proPackage->id,
    ]);

    $entPackage = makePackage('enterprise', 3);
    $service = app(SubscriptionProvisioningService::class);
    $service->upgrade($tenant, $entPackage);

    $subscription = Subscription::where('tenant_id', $tenant->id)->latest()->first();
    expect($subscription->pending_package_id)->toBeNull();
});

// ── downgrade() ───────────────────────────────────────────────────────────────

it('downgrade schedules the change without switching immediately', function () {
    $user = User::factory()->create();
    $bizPackage = makePackage('business', 2);
    $tenant = setActiveTenantForTest($user, ['package_id' => $bizPackage->id]);
    $proPackage = makePackage('pro', 1);

    Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'SUB_DWN',
        'provider_status' => 'active',
        'provider_plan' => 'PLN_business',
        'current_period_end' => now()->addMonth(),
    ]);

    $service = app(SubscriptionProvisioningService::class);
    $service->downgrade($tenant, $proPackage);

    // Tenant package should NOT have changed yet
    $tenant->refresh();
    expect((int) $tenant->package_id)->toBe((int) $bizPackage->id);

    // Pending change should be set
    $subscription = Subscription::where('tenant_id', $tenant->id)->latest()->first();
    expect((int) $subscription->pending_package_id)->toBe((int) $proPackage->id);
});

it('downgrade logs a plan change record', function () {
    $user = User::factory()->create();
    $bizPackage = makePackage('business', 2);
    $tenant = setActiveTenantForTest($user, ['package_id' => $bizPackage->id]);
    $proPackage = makePackage('pro', 1);

    Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'SUB_LOG',
        'provider_status' => 'active',
        'provider_plan' => 'PLN_business',
        'current_period_end' => now()->addMonth(),
    ]);

    $service = app(SubscriptionProvisioningService::class);
    $service->downgrade($tenant, $proPackage);

    expect(PlanChange::where('tenant_id', $tenant->id)
        ->where('type', 'downgrade')
        ->where('to_package_id', $proPackage->id)
        ->exists()
    )->toBeTrue();
});

// ── cancelAtPeriodEnd() ───────────────────────────────────────────────────────

it('cancel at period end schedules free plan on subscription', function () {
    $user = User::factory()->create();
    $freePackage = makePackage('free', 0, true);
    $proPackage = makePackage('pro', 1);
    $tenant = setActiveTenantForTest($user, ['package_id' => $proPackage->id]);

    Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'SUB_CANCEL',
        'provider_status' => 'active',
        'provider_plan' => 'PLN_pro',
        'current_period_end' => now()->addMonth(),
    ]);

    $service = app(SubscriptionProvisioningService::class);
    $service->cancelAtPeriodEnd($tenant);

    $subscription = Subscription::where('tenant_id', $tenant->id)->latest()->first();
    expect((int) $subscription->pending_package_id)->toBe((int) $freePackage->id);

    // Tenant should still be on pro
    $tenant->refresh();
    expect((int) $tenant->package_id)->toBe((int) $proPackage->id);
});

// ── switchToFree() ────────────────────────────────────────────────────────────

it('switch to free immediately downgrades tenant', function () {
    $user = User::factory()->create();
    $freePackage = makePackage('free', 0, true);
    $proPackage = makePackage('pro', 1);
    $tenant = setActiveTenantForTest($user, ['package_id' => $proPackage->id]);

    Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'SUB_FREE',
        'provider_status' => 'active',
        'provider_plan' => 'PLN_pro',
        'current_period_end' => now()->subDay(),
    ]);

    $service = app(SubscriptionProvisioningService::class);
    $service->switchToFree($tenant);

    $tenant->refresh();
    expect((int) $tenant->package_id)->toBe((int) $freePackage->id);

    $subscription = Subscription::where('tenant_id', $tenant->id)->latest()->first();
    expect($subscription->provider_status)->toBe('cancelled');
    expect($subscription->pending_package_id)->toBeNull();
});

it('switch to free logs a plan change as cancel', function () {
    $user = User::factory()->create();
    $freePackage = makePackage('free', 0, true);
    $proPackage = makePackage('pro', 1);
    $tenant = setActiveTenantForTest($user, ['package_id' => $proPackage->id]);

    $service = app(SubscriptionProvisioningService::class);
    $service->switchToFree($tenant);

    expect(PlanChange::where('tenant_id', $tenant->id)
        ->where('type', 'cancel')
        ->where('to_package_id', $freePackage->id)
        ->exists()
    )->toBeTrue();
});
