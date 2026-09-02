<?php

declare(strict_types=1);

use App\Livewire\Tenant\SubscriptionManager;
use App\Models\Package;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    setActiveTenantForTest($user);
});

it('defaults to monthly interval', function () {
    Livewire::test(SubscriptionManager::class)
        ->assertSet('interval', 'month');
});

it('can switch to yearly interval', function () {
    Livewire::test(SubscriptionManager::class)
        ->call('setInterval', 'year')
        ->assertSet('interval', 'year')
        ->call('setInterval', 'month')
        ->assertSet('interval', 'month');
});

it('ignores invalid interval values', function () {
    Livewire::test(SubscriptionManager::class)
        ->call('setInterval', 'weekly')
        ->assertSet('interval', 'month');
});

it('shows a confirmation modal when requesting a downgrade', function () {
    $pro = Package::create([
        'name' => 'Pro', 'slug' => 'pro-livewire', 'price' => 19, 'yearly_price' => 190,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => 'Pro',
        'is_active' => true, 'is_free' => false, 'sort_order' => 2,
    ]);

    $starter = Package::create([
        'name' => 'Starter', 'slug' => 'starter-livewire', 'price' => 9, 'yearly_price' => 90,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => 'Starter',
        'is_active' => true, 'is_free' => false, 'sort_order' => 1,
    ]);

    // Set the tenant's current package to Pro
    $tenant = app(App\Services\Tenancy\TenantContext::class)->getTenant();
    $tenant->update(['package_id' => $pro->id]);

    Livewire::test(SubscriptionManager::class)
        ->call('requestPlanChange', 'starter-livewire')
        ->assertSet('confirmingPlanSlug', 'starter-livewire')
        ->assertSet('confirmationType', 'downgrade');
});

it('shows a cancel confirmation when requesting the free plan', function () {
    $free = Package::create([
        'name' => 'Free', 'slug' => 'free-livewire', 'price' => 0, 'yearly_price' => 0,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => 'Free',
        'is_active' => true, 'is_free' => true, 'sort_order' => 0,
    ]);

    $pro = Package::create([
        'name' => 'Pro', 'slug' => 'pro-livewire-2', 'price' => 19, 'yearly_price' => 190,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => 'Pro',
        'is_active' => true, 'is_free' => false, 'sort_order' => 2,
    ]);

    $tenant = app(App\Services\Tenancy\TenantContext::class)->getTenant();
    $tenant->update(['package_id' => $pro->id]);

    Livewire::test(SubscriptionManager::class)
        ->call('requestPlanChange', 'free-livewire')
        ->assertSet('confirmationType', 'cancel');
});

it('clears confirmation on dismiss', function () {
    Livewire::test(SubscriptionManager::class)
        ->set('confirmingPlanSlug', 'some-plan')
        ->set('confirmationType', 'downgrade')
        ->call('dismissConfirmation')
        ->assertSet('confirmingPlanSlug', '')
        ->assertSet('confirmationType', '');
});
