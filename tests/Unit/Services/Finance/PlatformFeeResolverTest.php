<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Package;
use App\Models\Tenant;
use App\Services\Finance\PlatformFeeResolver;

test('the cap falls back to the package default', function () {
    $package = Package::factory()->create(['default_platform_fee_cap_amount' => 2000]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_cap_amount' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_cap_amount' => null]);

    expect(app(PlatformFeeResolver::class)->capAmountFor($event))->toBe(2000);
});

test('an event cap override wins over the tenant and the package', function () {
    $package = Package::factory()->create(['default_platform_fee_cap_amount' => 2000]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_cap_amount' => 5000]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_cap_amount' => 750]);

    expect(app(PlatformFeeResolver::class)->capAmountFor($event))->toBe(750);
});

test('a zero cap is honoured rather than treated as absent', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_cap_amount' => 0]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_cap_amount' => null]);

    expect(app(PlatformFeeResolver::class)->capAmountFor($event))->toBe(0);
});

test('the bearer defaults to the organizer', function () {
    config(['services.platform.default_fee_bearer' => 'organizer']);
    $tenant = Tenant::factory()->create(['package_id' => null, 'fee_bearer' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'fee_bearer' => null]);

    expect(app(PlatformFeeResolver::class)->bearerFor($event))->toBe('organizer');
});

test('an event can move the fee onto the attendee', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'fee_bearer' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'fee_bearer' => 'attendee']);

    expect(app(PlatformFeeResolver::class)->bearerFor($event))->toBe('attendee');
});

test('an unrecognised bearer is treated as organizer rather than trusted', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'fee_bearer' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'fee_bearer' => 'somebody_else']);

    expect(app(PlatformFeeResolver::class)->bearerFor($event))->toBe('organizer');
});

test('percentageFor delegates to the model so both paths agree', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => 1.75]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => null]);

    expect(app(PlatformFeeResolver::class)->percentageFor($event))
        ->toEqual($event->effectivePlatformFeePercentage());
});

test('a charity waiver is expressed as a zero percentage', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => 2.0]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 0.0]);

    expect(app(PlatformFeeResolver::class)->percentageFor($event))->toEqual(0.0);
});
