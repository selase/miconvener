<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Tenant;
use App\Services\Tenancy\StorageAllowances;

it('gives a future standard organisation its approved default before any feature sync', function (?string $slug, int $bytes): void {
    $package = $slug === null ? null : Package::factory()->create(['slug' => $slug]);
    $tenant = Tenant::factory()->create(['package_id' => $package?->id]);
    expect($tenant->featureLimitValue('storage_bytes'))->toBe($bytes);
})->with([[null, 104857600], ['free', 104857600], ['starter', 1073741824], ['growth', 5368709120], ['enterprise', 21474836480]]);

it('preserves old unlimited and finite limits through default installation and plan changes', function (): void {
    $free = Package::factory()->create(['slug' => 'free', 'price' => 123, 'default_platform_fee_percentage' => 7]);
    $growth = Package::factory()->create(['slug' => 'growth']);
    $unlimited = Tenant::factory()->create(['package_id' => $free->id]);
    $finite = Tenant::factory()->create(['package_id' => $free->id]);
    $finite->features()->create(['feature_key' => 'storage_bytes', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 12345]]);
    $policy = app(StorageAllowances::class);
    $policy->preserveExisting();
    $policy->installDefaults();
    $policy->preserveExisting();
    foreach ([$unlimited, $finite] as $tenant) {
        $tenant->refresh()->update(['package_id' => $growth->id]);
        $tenant->syncFeaturesFromPackage();
    }
    expect($unlimited->fresh()->featureLimitValue('storage_bytes'))->toBeNull()
        ->and($finite->fresh()->featureLimitValue('storage_bytes'))->toBe(12345)
        ->and($free->fresh()->price)->toBe('123.00')
        ->and($free->fresh()->default_platform_fee_percentage)->toBe('7.00');
    $future = Tenant::factory()->create(['package_id' => $growth->id]);
    $future->syncFeaturesFromPackage();
    expect($future->featureLimitValue('storage_bytes'))->toBe(5368709120);
});

it('honours a new explicit tenant limit and a disabled limit without changing other features', function (): void {
    $tenant = Tenant::factory()->create(['package_id' => null]);
    $row = $tenant->features()->create(['feature_key' => 'storage_bytes', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 1234]]);
    $tenant->features()->create(['feature_key' => 'team_seats', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 3]]);
    expect($tenant->featureLimitValue('storage_bytes'))->toBe(1234)->and($tenant->featureLimitValue('team_seats'))->toBe(3);
    $row->update(['enabled' => false]);
    expect($tenant->featureLimitValue('storage_bytes'))->toBeNull();
});

it('does not let ordinary tenant mass assignment manufacture a retained allowance', function (): void {
    expect((new Tenant)->isFillable('storage_limit_snapshot'))->toBeFalse();
});

it('installs missing pivots only and preserves a negotiated package storage value', function (): void {
    $package = Package::factory()->create(['slug' => 'starter']);
    $policy = app(StorageAllowances::class);
    $policy->installDefaults();
    $feature = App\Models\Feature::query()->where('slug', 'storage_bytes')->firstOrFail();
    $package->features()->updateExistingPivot($feature->id, ['value' => 777]);
    $policy->installDefaults();
    expect((int) $package->features()->where('features.id', $feature->id)->first()->pivot->value)->toBe(777);
    $this->seed(Database\Seeders\EventPackageSeeder::class);
    expect((int) $package->features()->where('features.id', $feature->id)->first()->pivot->value)->toBe(777);
    foreach (['free', 'growth', 'enterprise'] as $slug) {
        $tier = Package::query()->where('slug', $slug)->firstOrFail();
        expect((int) $tier->features()->where('slug', 'storage_bytes')->first()->pivot->value)->toBe(StorageAllowances::DEFAULTS[$slug]);
    }
});

it('rejects an invalid retained allowance in the database', function (): void {
    $tenant = Tenant::factory()->create();
    expect(fn () => $tenant->forceFill(['storage_limit_snapshot' => -2])->saveQuietly())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('retains disabled legacy storage and leaves unrelated package entitlements alone', function (): void {
    $package = Package::factory()->create(['slug' => 'free']);
    $seats = App\Models\Feature::factory()->create(['slug' => 'team_seats', 'type' => 'limit']);
    $package->features()->attach($seats->id, ['value' => 17]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id]);
    $tenant->features()->create(['feature_key' => 'storage_bytes', 'enabled' => false, 'meta' => ['type' => 'limit', 'value' => 123]]);
    $policy = app(StorageAllowances::class);
    $policy->preserveExisting();
    $policy->installDefaults();
    $tenant->refresh()->syncFeaturesFromPackage();
    expect($tenant->fresh()->featureLimitValue('storage_bytes'))->toBeNull()
        ->and((int) $package->features()->where('features.id', $seats->id)->first()->pivot->value)->toBe(17);
});

it('keeps unknown future custom packages uncapped unless they have explicit storage terms', function (): void {
    App\Models\Feature::query()->where('slug', 'storage_bytes')->delete();
    $package = Package::factory()->create(['slug' => 'negotiated']);
    $tenant = Tenant::factory()->create(['package_id' => $package->id]);
    expect($tenant->featureLimitValue('storage_bytes'))->toBeNull();
    $feature = App\Models\Feature::factory()->create(['slug' => 'storage_bytes', 'type' => 'limit']);
    $package->features()->attach($feature->id, ['value' => 123]);
    expect($tenant->fresh()->featureLimitValue('storage_bytes'))->toBe(123);
});

it('refuses a destructive rollback of retained customer terms', function (): void {
    $migration = require database_path('migrations/landlord/2026_11_02_154431_add_preserved_storage_limit_to_tenants.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class);
    expect(Illuminate\Support\Facades\Schema::connection('landlord')->hasColumn('tenants', 'storage_limit_snapshot'))->toBeTrue();
});

it('migrates an existing cohort before installing defaults without rewriting their timestamps', function (): void {
    $package = Package::factory()->create(['slug' => 'free']);
    $unlimited = Tenant::factory()->create(['package_id' => $package->id, 'updated_at' => now()->subDays(7)]);
    $finite = Tenant::factory()->create(['package_id' => $package->id]);
    $finite->features()->create(['feature_key' => 'storage_bytes', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 6543]]);
    $timestamp = $unlimited->fresh()->updated_at->toISOString();
    Illuminate\Support\Facades\Schema::connection('landlord')->table('tenants', fn ($table) => $table->dropColumn('storage_limit_snapshot'));
    $migration = require database_path('migrations/landlord/2026_11_02_154431_add_preserved_storage_limit_to_tenants.php');
    $migration->up();
    expect($unlimited->fresh()->storage_limit_snapshot)->toBe(-1)
        ->and($unlimited->fresh()->updated_at->toISOString())->toBe($timestamp)
        ->and($finite->fresh()->storage_limit_snapshot)->toBe(6543)
        ->and(Tenant::factory()->create(['package_id' => $package->id])->featureLimitValue('storage_bytes'))->toBe(104857600);
});
