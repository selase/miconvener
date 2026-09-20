<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Feature;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantFeatureUsage;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'package_id' => null]);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->user->assignRole('Org Admin');
    $this->tenant->users()->attach($this->user->id);
});

function usagePage(User $user): TestResponse
{
    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    return test()->actingAs($user)->get("http://{$host}/settings/usage", ['HTTP_HOST' => $host]);
}

function usagePackage(string $slug, string $name = 'Pro'): Package
{
    return Package::create([
        'name' => $name, 'slug' => $slug, 'price' => 19, 'yearly_price' => 190,
        'interval' => 'month', 'billing_model' => 'flat_rate', 'description' => $name,
        'is_active' => true, 'is_free' => false, 'sort_order' => 2,
    ]);
}

test('a tenant with no plan sees the empty state on the Free plan', function () {
    usagePage($this->user)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/Usage')
            ->where('planName', 'Free')
            ->where('periodLabel', now()->format('F Y'))
            ->where('limits', [])
            ->where('seats', null)
            ->where('includedFeatures', []));
});

test('limit features report this period\'s usage against the plan limit', function () {
    $package = usagePackage('pro-limit-test');
    $feature = Feature::create([
        'name' => 'Monthly Queue Sessions', 'slug' => 'queue_sessions_quota',
        'type' => 'limit', 'description' => 'Number of queue sessions per month',
    ]);
    $package->features()->attach($feature->id, ['value' => '50']);
    $this->tenant->update(['package_id' => $package->id]);

    TenantFeatureUsage::create([
        'tenant_id' => $this->tenant->id,
        'feature_slug' => 'queue_sessions_quota',
        'used_count' => 20,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    usagePage($this->user)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('planName', 'Pro')
            ->has('limits', 1)
            ->where('limits.0.name', 'Monthly Queue Sessions')
            ->where('limits.0.used', 20)
            ->where('limits.0.limit', 50));
});

test('usage from an earlier period is not counted', function () {
    $package = usagePackage('pro-period-test');
    $feature = Feature::create(['name' => 'AI Tokens', 'slug' => 'ai_tokens_quota', 'type' => 'limit']);
    $package->features()->attach($feature->id, ['value' => '100']);
    $this->tenant->update(['package_id' => $package->id]);

    TenantFeatureUsage::create([
        'tenant_id' => $this->tenant->id,
        'feature_slug' => 'ai_tokens_quota',
        'used_count' => 95,
        'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subMonth()->endOfMonth(),
    ]);

    usagePage($this->user)
        ->assertInertia(fn ($page) => $page->where('limits.0.used', 0));
});

test('a limit feature with no numeric value on the plan is not shown as a meter', function () {
    $package = usagePackage('pro-unlimited-test');
    $feature = Feature::create(['name' => 'Events', 'slug' => 'events_quota', 'type' => 'limit']);
    $package->features()->attach($feature->id, ['value' => '0']);
    $this->tenant->update(['package_id' => $package->id]);

    usagePage($this->user)->assertInertia(fn ($page) => $page->where('limits', []));
});

test('team seats compare members against the plan\'s seat allowance', function () {
    $package = usagePackage('pro-seats-test');
    $feature = Feature::create(['name' => 'Team seats', 'slug' => 'team_seats', 'type' => 'limit']);
    $package->features()->attach($feature->id, ['value' => '5']);
    $this->tenant->update(['package_id' => $package->id]);

    usagePage($this->user)
        ->assertInertia(fn ($page) => $page
            ->where('seats.used', 1)
            ->where('seats.limit', 5));
});

test('only boolean features switched on for the plan are listed as included', function () {
    $package = usagePackage('pro-bool-test');
    $enabled = Feature::create([
        'name' => 'Live Queue Monitoring', 'slug' => 'live_queue_monitoring',
        'type' => 'boolean', 'description' => 'Monitor active provider and public queue activity',
    ]);
    $disabled = Feature::create(['name' => 'Custom Domain', 'slug' => 'custom_domain', 'type' => 'boolean']);
    $package->features()->attach($enabled->id, ['value' => 'true']);
    $package->features()->attach($disabled->id, ['value' => 'false']);
    $this->tenant->update(['package_id' => $package->id]);

    usagePage($this->user)
        ->assertInertia(fn ($page) => $page
            ->has('includedFeatures', 1)
            ->where('includedFeatures.0.name', 'Live Queue Monitoring')
            ->where('includedFeatures.0.description', 'Monitor active provider and public queue activity'));
});
