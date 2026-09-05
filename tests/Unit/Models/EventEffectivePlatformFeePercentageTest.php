<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Package;
use App\Models\Tenant;

test('effectivePlatformFeePercentage falls back to the tenant\'s package default', function () {
    $package = Package::factory()->create(['default_platform_fee_percentage' => 2.0]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_percentage' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => null]);

    expect($event->effectivePlatformFeePercentage())->toEqual(2.0);
});

test('a tenant manual override still wins over the package default', function () {
    $package = Package::factory()->create(['default_platform_fee_percentage' => 2.0]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_percentage' => 4.25]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => null]);

    expect($event->effectivePlatformFeePercentage())->toEqual(4.25);
});

test('an event-level override still wins over everything', function () {
    $package = Package::factory()->create(['default_platform_fee_percentage' => 2.0]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_percentage' => 4.25]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 1.0]);

    expect($event->effectivePlatformFeePercentage())->toEqual(1.0);
});

test('falls back to the global config default when no package default is set', function () {
    config(['services.platform.default_fee_percentage' => 6.5]);
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => null]);

    expect($event->effectivePlatformFeePercentage())->toEqual(6.5);
});
