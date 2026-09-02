<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Support\Facades\Artisan;

it('creates all 4 packages', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    expect(Package::count())->toBe(4);
    expect(Package::where('slug', 'free')->exists())->toBeTrue();
    expect(Package::where('slug', 'pro')->exists())->toBeTrue();
    expect(Package::where('slug', 'business')->exists())->toBeTrue();
    expect(Package::where('slug', 'enterprise')->exists())->toBeTrue();
});

it('marks the free package with is_free flag', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    $freePackage = Package::where('slug', 'free')->first();

    expect($freePackage->is_free)->toBeTrue();
    expect($freePackage->isFree())->toBeTrue();
    expect(Package::where('slug', 'pro')->first()->isFree())->toBeFalse();
});

it('sets correct pricing for all packages', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    $pro = Package::where('slug', 'pro')->first();
    expect((float) $pro->price)->toBe(19.0);
    expect((float) $pro->yearly_price)->toBe(190.0);

    $business = Package::where('slug', 'business')->first();
    expect((float) $business->price)->toBe(49.0);
    expect((float) $business->yearly_price)->toBe(490.0);

    $enterprise = Package::where('slug', 'enterprise')->first();
    expect((float) $enterprise->price)->toBe(99.0);
    expect((float) $enterprise->yearly_price)->toBe(990.0);
});

it('creates all expected features', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    $expectedFeatures = [
        'meetings', 'meeting_recording', 'meeting_transcription',
        'meeting_minutes_ai', 'meeting_action_tracking', 'meeting_quota',
        'meeting_minutes_editor', 'secretary_markers', 'floor_control',
        'calendar_integrations', 'pm_integrations', 'team_communication',
        'custom_webhooks', 'compliance_audit_log', 'compliance_legal_hold',
        'analytics', 'sso', 'llm_token_quota', 'llm_byok',
    ];

    foreach ($expectedFeatures as $slug) {
        expect(Feature::where('slug', $slug)->exists())->toBeTrue("Feature [{$slug}] should exist");
    }
});

it('assigns fewer features to free plan than enterprise', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    $free = Package::where('slug', 'free')->with('features')->first();
    $enterprise = Package::where('slug', 'enterprise')->with('features')->first();

    expect($free->features->count())->toBeLessThan($enterprise->features->count());
});

it('assigns compliance features only to enterprise', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    $complianceSlugs = ['compliance_audit_log', 'compliance_legal_hold', 'compliance_data_export'];

    $free = Package::where('slug', 'free')->with('features')->first();
    $pro = Package::where('slug', 'pro')->with('features')->first();

    foreach ($complianceSlugs as $slug) {
        expect($free->features->pluck('slug')->contains($slug))->toBeFalse("Free should NOT have {$slug}");
        expect($pro->features->pluck('slug')->contains($slug))->toBeFalse("Pro should NOT have {$slug}");
    }

    $enterprise = Package::where('slug', 'enterprise')->with('features')->first();
    foreach ($complianceSlugs as $slug) {
        expect($enterprise->features->pluck('slug')->contains($slug))->toBeTrue("Enterprise should have {$slug}");
    }
});

it('assigns meeting_minutes_ai to pro and above', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    $free = Package::where('slug', 'free')->with('features')->first();
    expect($free->features->pluck('slug')->contains('meeting_minutes_ai'))->toBeFalse();

    foreach (['pro', 'business', 'enterprise'] as $slug) {
        $pkg = Package::where('slug', $slug)->with('features')->first();
        expect($pkg->features->pluck('slug')->contains('meeting_minutes_ai'))
            ->toBeTrue("Package [{$slug}] should have meeting_minutes_ai");
    }
});

it('is idempotent when run twice', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    expect(Package::count())->toBe(4);
    expect(Feature::count())->toBeGreaterThanOrEqual(33);
});

it('sets correct sort order for packages', function () {
    Artisan::call('db:seed', ['--class' => 'MasterFeaturePackageSeeder', '--no-interaction' => true]);

    $packages = Package::orderBy('sort_order')->pluck('slug')->toArray();

    expect($packages[0])->toBe('free');
    expect($packages[1])->toBe('pro');
    expect($packages[2])->toBe('business');
    expect($packages[3])->toBe('enterprise');
});
