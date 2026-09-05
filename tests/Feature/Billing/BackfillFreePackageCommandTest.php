<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

test('backfilling assigns every tenant without a package to Free and syncs its features', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);
    $tenant = Tenant::factory()->create(['package_id' => null]);

    Artisan::call('billing:backfill-free-package');

    $tenant->refresh();
    $free = Package::where('slug', 'free')->firstOrFail();
    expect((int) $tenant->package_id)->toBe((int) $free->id);

    $feature = TenantFeature::where('tenant_id', $tenant->id)
        ->where('feature_key', 'events_in_flight')
        ->firstOrFail();
    expect($feature->enabled)->toBeTrue();
    expect($feature->meta['value'])->toEqual(1);
});

test('backfilling does not touch a tenant that already has a package assigned', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);
    $growth = Package::where('slug', 'growth')->firstOrFail();
    $tenant = Tenant::factory()->create(['package_id' => $growth->id]);

    Artisan::call('billing:backfill-free-package');

    expect((int) $tenant->refresh()->package_id)->toBe((int) $growth->id);
});

test('backfilling fails gracefully when no free package exists', function () {
    $tenant = Tenant::factory()->create(['package_id' => null]);

    $exitCode = Artisan::call('billing:backfill-free-package');

    expect($exitCode)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('No free package found');
    expect($tenant->refresh()->package_id)->toBeNull();
});
