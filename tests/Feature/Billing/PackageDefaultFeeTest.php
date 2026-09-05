<?php

declare(strict_types=1);

use App\Models\Package;

test('a package stores a nullable default platform fee percentage', function () {
    $package = Package::factory()->create(['default_platform_fee_percentage' => 3.5]);

    expect($package->fresh()->default_platform_fee_percentage)->toEqual(3.5);

    $custom = Package::factory()->create(['default_platform_fee_percentage' => null]);
    expect($custom->fresh()->default_platform_fee_percentage)->toBeNull();
});
