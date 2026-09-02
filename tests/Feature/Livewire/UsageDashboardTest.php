<?php

declare(strict_types=1);

use App\Livewire\Tenant\UsageDashboard;
use App\Models\Feature;
use App\Models\Package;
use App\Models\TenantFeatureUsage;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->tenant = setActiveTenantForTest($user);
    URL::defaults(['subdomain' => $this->tenant->slug]);
});

it('renders the usage dashboard component', function () {
    Livewire::test(UsageDashboard::class)
        ->assertStatus(200);
});

it('shows the empty state when no limit features exist', function () {
    Livewire::test(UsageDashboard::class)
        ->assertSee('No usage data yet');
});

it('shows plan name and upgrade link', function () {
    $package = Package::create([
        'name' => 'Pro', 'slug' => 'pro-usage-test', 'price' => 19, 'yearly_price' => 190,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => 'Pro',
        'is_active' => true, 'is_free' => false, 'sort_order' => 2,
    ]);

    $this->tenant->update(['package_id' => $package->id]);

    Livewire::test(UsageDashboard::class)
        ->assertSee('Pro')
        ->assertSee('Upgrade Plan');
});

it('shows quota progress for limit-type features attached to package', function () {
    $package = Package::create([
        'name' => 'Pro', 'slug' => 'pro-limit-test', 'price' => 19, 'yearly_price' => 190,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => 'Pro',
        'is_active' => true, 'is_free' => false, 'sort_order' => 2,
    ]);

    $feature = Feature::create([
        'name' => 'Monthly Queue Sessions', 'slug' => 'queue_sessions_quota',
        'type' => 'limit', 'description' => 'Number of queue sessions per month',
    ]);

    $package->features()->attach($feature->id, ['value' => '50']);
    $this->tenant->update(['package_id' => $package->id]);

    // Simulate 20 queue sessions used
    TenantFeatureUsage::create([
        'tenant_id' => $this->tenant->id,
        'feature_slug' => 'queue_sessions_quota',
        'used_count' => 20,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    Livewire::test(UsageDashboard::class)
        ->assertSee('Monthly Queue Sessions')
        ->assertSee('20')
        ->assertSee('50');
});

it('shows near-limit badge when usage exceeds 90 percent', function () {
    $package = Package::create([
        'name' => 'Starter', 'slug' => 'starter-limit-test', 'price' => 9, 'yearly_price' => 90,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => 'Starter',
        'is_active' => true, 'is_free' => false, 'sort_order' => 1,
    ]);

    $feature = Feature::create([
        'name' => 'AI Tokens', 'slug' => 'ai_tokens_quota',
        'type' => 'limit', 'description' => 'AI tokens per month',
    ]);

    $package->features()->attach($feature->id, ['value' => '100']);
    $this->tenant->update(['package_id' => $package->id]);

    TenantFeatureUsage::create([
        'tenant_id' => $this->tenant->id,
        'feature_slug' => 'ai_tokens_quota',
        'used_count' => 95,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    Livewire::test(UsageDashboard::class)
        ->assertSee('Near limit');
});

it('shows enabled boolean features', function () {
    $package = Package::create([
        'name' => 'Pro', 'slug' => 'pro-bool-test', 'price' => 19, 'yearly_price' => 190,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => 'Pro',
        'is_active' => true, 'is_free' => false, 'sort_order' => 2,
    ]);

    $feature = Feature::create([
        'name' => 'Live Queue Monitoring', 'slug' => 'live_queue_monitoring',
        'type' => 'boolean', 'description' => 'Monitor active provider and public queue activity',
    ]);

    $package->features()->attach($feature->id, ['value' => 'true']);
    $this->tenant->update(['package_id' => $package->id]);

    Livewire::test(UsageDashboard::class)
        ->assertSee('Included Features')
        ->assertSee('Live Queue Monitoring');
});
