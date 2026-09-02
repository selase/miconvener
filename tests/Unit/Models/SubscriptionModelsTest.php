<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Package;

test('it can create a feature', function () {
    $feature = Feature::create([
        'name' => 'AI Access',
        'slug' => 'ai-access',
        'type' => 'boolean',
    ]);

    expect($feature)->toBeInstanceOf(Feature::class);
    expect($feature->name)->toBe('AI Access');
    expect($feature->uuid)->not->toBeNull(); // HasUuid check
});

test('it can create a package', function () {
    $package = Package::create([
        'name' => 'Pro Plan',
        'slug' => 'pro-plan',
        'price' => 29.99,
        'interval' => 'month',
    ]);

    expect($package)->toBeInstanceOf(Package::class);
    expect($package->price)->toBe('29.99');
    expect($package->uuid)->not->toBeNull();
});

test('it can link packages and features with values', function () {
    $package = Package::create([
        'name' => 'Enterprise',
        'slug' => 'enterprise',
        'price' => 99.99,
    ]);

    $featureLimit = Feature::create([
        'name' => 'User Seats',
        'slug' => 'user-seats',
        'type' => 'limit',
    ]);

    $featureBool = Feature::create([
        'name' => 'Advanced Analytics',
        'slug' => 'adv-analytics',
        'type' => 'boolean',
    ]);

    // Attach with Pivot values
    $package->features()->attach([
        $featureLimit->id => ['value' => '10'],
        $featureBool->id => ['value' => 'true'],
    ]);

    expect($package->features)->toHaveCount(2);

    $limitPivot = $package->features->firstWhere('slug', 'user-seats')->pivot;
    expect($limitPivot->value)->toBe('10');

    $boolPivot = $package->features->firstWhere('slug', 'adv-analytics')->pivot;
    expect($boolPivot->value)->toBe('true');
});
