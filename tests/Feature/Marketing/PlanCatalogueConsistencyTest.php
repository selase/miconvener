<?php

declare(strict_types=1);

use App\Models\Package;
use Database\Seeders\EventPackageSeeder;

/**
 * The public pricing table and the registration form both render plans from
 * config('product-page.plans'), but registration validates the submitted slug
 * with `exists:packages,slug` against the seeded catalogue. If the two drift
 * apart, the form happily offers a plan that can never be accepted, and the
 * signup fails validation with no explanation the visitor can act on.
 */
beforeEach(function (): void {
    $this->seed(EventPackageSeeder::class);
});

test('every advertised plan slug exists as a seeded package', function (): void {
    $advertised = collect(config('product-page.plans'))->pluck('slug');
    $seeded = Package::query()->pluck('slug');

    expect($advertised)->not->toBeEmpty();

    foreach ($advertised as $slug) {
        expect($seeded)->toContain($slug);
    }
});

test('every plan the registration form offers is a paid package', function (): void {
    // The form skips plans without a positive numeric price, and the controller
    // rejects free packages outright. Anything selectable must satisfy both.
    $selectable = collect(config('product-page.plans'))
        ->filter(fn (array $plan): bool => is_numeric($plan['monthly_price'] ?? null) && $plan['monthly_price'] > 0);

    expect($selectable)->not->toBeEmpty();

    foreach ($selectable as $plan) {
        $package = Package::query()->where('slug', $plan['slug'])->first();

        expect($package)->not->toBeNull("Plan {$plan['slug']} is offered but not seeded");
        expect($package->isFree())->toBeFalse("Plan {$plan['slug']} is offered but is a free package");
    }
});

test('advertised prices match the seeded package prices', function (): void {
    foreach (config('product-page.plans') as $plan) {
        if (! is_numeric($plan['monthly_price'] ?? null)) {
            continue;
        }

        $package = Package::query()->where('slug', $plan['slug'])->firstOrFail();

        expect((float) $package->price)->toBe((float) $plan['monthly_price']);
        expect((float) $package->yearly_price)->toBe((float) $plan['yearly_price']);
    }
});
