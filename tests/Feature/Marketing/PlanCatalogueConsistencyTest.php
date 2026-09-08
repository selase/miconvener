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

test('every plan the registration form offers is seeded and self-serve', function (): void {
    // The form skips plans without a numeric price -- that is how Enterprise,
    // whose limits are negotiated, stays out of a self-serve signup. Everything
    // that survives the filter must be a package the controller can accept.
    $selectable = collect(config('product-page.plans'))
        ->filter(fn (array $plan): bool => is_numeric($plan['monthly_price'] ?? null));

    expect($selectable)->not->toBeEmpty();
    expect($selectable->pluck('slug'))->toContain('free');

    foreach ($selectable as $plan) {
        $package = Package::query()->where('slug', $plan['slug'])->first();

        expect($package)->not->toBeNull("Plan {$plan['slug']} is offered but not seeded");
    }
});

test('the free plan advertises the limits the seeder enforces', function (): void {
    // Marketing copy and the seeded ceiling drifted apart once before: the page
    // promised 100 registrations while the tier was never enforced at all.
    $free = collect(config('product-page.plans'))->firstWhere('slug', 'free');
    $package = Package::query()->where('slug', 'free')->firstOrFail();

    $registrationLimit = $package->features()
        ->where('slug', 'event_registrations')
        ->first()
        ->pivot
        ->value;

    expect((int) $registrationLimit)->toBe(50);
    expect($free['features'])->toContain('50 registrations per month');

    // Free events only -- both advertised and switched off in the catalogue.
    $paidTickets = $package->features()->where('slug', 'paid_tickets')->first();
    expect($paidTickets)->not->toBeNull();
    expect(filter_var($paidTickets->pivot->value, FILTER_VALIDATE_BOOLEAN))->toBeFalse();
    expect($free['features'])->toContain('Free events only — no ticket sales');
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
