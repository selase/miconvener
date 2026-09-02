<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    refreshTenantDatabases();
    $this->tenant = setActiveTenantForTest();
});

test('it records usage for a feature', function () {
    $this->tenant->recordUsage('ai_credits', 5);

    $this->assertDatabaseHas('tenant_feature_usages', [
        'tenant_id' => $this->tenant->id,
        'feature_slug' => 'ai_credits',
        'used_count' => 5,
    ], 'landlord');

    $this->tenant->recordUsage('ai_credits', 3);

    $this->assertDatabaseHas('tenant_feature_usages', [
        'tenant_id' => $this->tenant->id,
        'feature_slug' => 'ai_credits',
        'used_count' => 8,
    ], 'landlord');
});

test('it enforces usage limits', function () {
    // 1. Setup Feature and TenantFeature with Limit
    $feature = Feature::create(['name' => 'AI Credits', 'slug' => 'ai_credits', 'type' => 'limit']);

    // Manually attach to tenant (mocking package sync)
    $this->tenant->features()->create([
        'feature_key' => 'ai_credits',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 10], // Limit 10
    ]);

    // 2. Usage below limit
    $this->tenant->recordUsage('ai_credits', 5);
    expect($this->tenant->canUse('ai_credits', 1))->toBeTrue();
    expect($this->tenant->canUse('ai_credits', 5))->toBeTrue();

    // 3. Usage equals limit
    $this->tenant->recordUsage('ai_credits', 5); // Total 10
    expect($this->tenant->canUse('ai_credits', 1))->toBeFalse(); // 11 > 10

    // 4. Usage checked for cost
    $this->tenant->usage()->delete(); // Reset
    expect($this->tenant->canUse('ai_credits', 11))->toBeFalse(); // 11 > 10 immediately
});

test('middleware blocks request when limit exceeded', function () {
    // Setup Feature
    Feature::create(['name' => 'AI Credits', 'slug' => 'ai_credits', 'type' => 'limit']);
    $this->tenant->features()->create([
        'feature_key' => 'ai_credits',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 10],
    ]);

    // Define Route
    Route::get('/metered-action', function () {
        return 'success';
    })->middleware(['web', 'feature_limit:ai_credits,1']);

    // 1. Create User and Attach to Tenant
    $user = App\Models\User::factory()->create();
    $this->tenant->users()->attach($user);

    // 2. Request below limit
    $url = $this->tenant->url('/metered-action');
    $this->actingAs($user)
        ->get($url)
        ->assertOk();

    // 3. Record usage to limit
    $this->tenant->recordUsage('ai_credits', 10);

    // 4. Request blocked
    $this->actingAs($user)
        ->get($url)
        ->assertStatus(403);
});

test('artisan command resets usage', function () {
    // 1. Record usage
    $this->tenant->recordUsage('ai_credits', 10);

    $this->assertDatabaseHas('tenant_feature_usages', [
        'tenant_id' => $this->tenant->id,
        'feature_slug' => 'ai_credits',
        'used_count' => 10,
    ], 'landlord');

    // 2. Run Command
    $this->artisan('tenants:reset-usage')
        ->assertExitCode(0);

    // 3. Verify Empty
    // Note: resetUsage deletes the rows by default in our implementation
    $this->assertDatabaseMissing('tenant_feature_usages', [
        'tenant_id' => $this->tenant->id,
        'feature_slug' => 'ai_credits',
    ], 'landlord');
});
