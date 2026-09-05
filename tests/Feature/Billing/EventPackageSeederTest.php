<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Support\Facades\Artisan;

test('EventPackageSeeder creates exactly four packages with the correct feature values', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);

    expect(Package::count())->toBe(4);

    $free = Package::where('slug', 'free')->firstOrFail();
    expect($free->is_free)->toBeTrue();
    expect((float) $free->price)->toEqual(0.0);

    $eventsInFlight = $free->features()->where('slug', 'events_in_flight')->first();
    expect($eventsInFlight->pivot->value)->toEqual(1);

    $growth = Package::where('slug', 'growth')->firstOrFail();
    expect((float) $growth->default_platform_fee_percentage)->toEqual(2.0);
    $customDomains = $growth->features()->where('slug', 'custom-domains')->first();
    expect($customDomains->pivot->value)->toEqual(true);

    $enterprise = Package::where('slug', 'enterprise')->firstOrFail();
    $eventsUnlimited = $enterprise->features()->where('slug', 'events_in_flight')->first();
    expect($eventsUnlimited->pivot->value)->toEqual(-1);
    expect($enterprise->default_platform_fee_percentage)->toBeNull();
});

test('EventPackageSeeder creates all eleven catalog features', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);

    // Note: a pre-existing, unrelated landlord migration
    // (2026_01_21_153736_seed_llm_features.php) always seeds two baseline
    // features (llm_byok, llm_token_quota) on every fresh migration, so
    // Feature::count() is not a reliable total here. Assert against the
    // eleven catalog slugs specifically instead.
    $catalogSlugs = [
        'events_in_flight',
        'event_registrations',
        'team_seats',
        'email_credits',
        'sms_credits',
        'remove_platform_branding',
        'custom-domains',
        'csv_settlement_export',
        'white_label',
        'sso',
        'api_access',
    ];

    expect(Feature::whereIn('slug', $catalogSlugs)->count())->toBe(11);
    expect(Feature::where('slug', 'events_in_flight')->where('type', 'limit')->exists())->toBeTrue();
    expect(Feature::where('slug', 'custom-domains')->where('type', 'boolean')->exists())->toBeTrue();
});

test('running the full DatabaseSeeder produces the event-tier packages, not the SaaS-generic ones', function () {
    Artisan::call('db:seed');

    expect(Package::where('slug', 'free')->exists())->toBeTrue();
    expect(Package::where('slug', 'growth')->exists())->toBeTrue();
    // The old PackageSeeder read config('product-page.plans') and produced
    // packages named after whatever that config held (typically 'starter',
    // 'pro', 'enterprise') — 'pro' must no longer appear once EventPackageSeeder
    // has replaced PackageSeeder's entry.
    expect(Package::where('slug', 'pro')->exists())->toBeFalse();
});
