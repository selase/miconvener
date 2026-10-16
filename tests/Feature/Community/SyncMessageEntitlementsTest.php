<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Package;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

test('message entitlement sync is narrow idempotent and supports a read-only preview', function () {
    $package = Package::factory()->create(['slug' => 'growth', 'price' => 12345, 'default_platform_fee_percentage' => '7.25']);
    $tenant = Tenant::factory()->create(['package_id' => $package->id]);
    $tenant->features()->create(['feature_key' => 'community_message_scheduling', 'enabled' => false, 'meta' => ['source' => 'admin_override']]);
    $before = Feature::query()->count();
    Artisan::call('community:sync-message-entitlements', ['--dry-run' => true]);
    expect(Feature::query()->count())->toBe($before)->and($tenant->featureEnabled('community_group_messaging'))->toBeFalse();
    Artisan::call('community:sync-message-entitlements');
    Artisan::call('community:sync-message-entitlements');
    expect($tenant->featureEnabled('community_group_messaging'))->toBeTrue()->and($tenant->featureEnabled('community_message_scheduling'))->toBeFalse()
        ->and($tenant->featureEnabled('community_messages'))->toBeFalse()
        ->and((int) $package->fresh()->price)->toBe(12345)->and((string) $package->fresh()->default_platform_fee_percentage)->toBe('7.25');
    expect($package->features()->whereIn('slug', ['community_message_scheduling', 'community_group_messaging'])->count())->toBe(2);
});

test('sync preserves custom package grants and unknown plans remain disabled by default', function () {
    $custom = Package::factory()->create(['slug' => 'custom']);
    $feature = Feature::factory()->create(['slug' => 'community_group_messaging', 'type' => 'boolean']);
    $custom->features()->attach($feature->id, ['value' => true]);
    $tenant = Tenant::factory()->create(['package_id' => $custom->id]);
    Artisan::call('community:sync-message-entitlements');
    expect($tenant->featureEnabled('community_group_messaging'))->toBeTrue()->and($tenant->featureEnabled('community_message_scheduling'))->toBeFalse();
});
