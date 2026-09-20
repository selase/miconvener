<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantFeature;
use Database\Seeders\EventPackageSeeder;

/**
 * A tenant's entitlements live in their own feature rows, copied from the plan
 * whenever the plan changes. Two things have to stay true through that copy:
 * a decision an administrator made by hand is not undone by it, and a row
 * never loses the limit it was carrying.
 */
beforeEach(function () {
    refreshTenantDatabases();
    $this->seed(EventPackageSeeder::class);
});

function tenantOn(string $plan): Tenant
{
    $tenant = Tenant::factory()->create([
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', $plan)->firstOrFail()->id,
    ]);
    $tenant->syncFeaturesFromPackage();

    return $tenant;
}

test('a plan change does not revoke what an administrator granted by hand', function () {
    $tenant = tenantOn('growth');

    expect($tenant->planAllows('white_label'))->toBeFalse();

    // A comp: granted outside the plan, as the superadmin console does it.
    $tenant->features()->where('feature_key', 'white_label')->update([
        'enabled' => true,
        'meta' => ['type' => 'boolean', 'value' => '1', 'source' => 'admin_override'],
    ]);

    expect($tenant->fresh()->planAllows('white_label'))->toBeTrue();

    // Anything that touches the subscription re-syncs; the comp must survive.
    $tenant->update(['package_id' => Package::where('slug', 'starter')->firstOrFail()->id]);
    $tenant->syncFeaturesFromPackage();

    expect($tenant->fresh()->planAllows('white_label'))->toBeTrue();
});

test('an administrator withholding a feature is not undone by an upgrade', function () {
    $tenant = tenantOn('enterprise');

    $tenant->features()->where('feature_key', 'white_label')->update([
        'enabled' => false,
        'meta' => ['type' => 'boolean', 'value' => '0', 'source' => 'admin_override'],
    ]);

    $tenant->syncFeaturesFromPackage();

    expect($tenant->fresh()->planAllows('white_label'))->toBeFalse();
});

test('an overridden row still carries the plan its limits come from', function () {
    $tenant = tenantOn('growth');

    $tenant->features()->where('feature_key', 'event_registrations')->update([
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => '500', 'source' => 'admin_override'],
    ]);

    $tenant->syncFeaturesFromPackage();

    $row = $tenant->fresh()->features()->where('feature_key', 'event_registrations')->firstOrFail();

    // The administrator decides whether; the plan still decides how much, so a
    // limit is never left null -- a null limit caps nothing at all.
    expect($row->meta['source'])->toBe('admin_override');
    expect($row->meta['type'])->toBe('limit');
    expect($row->meta['value'])->not->toBeNull();
});

test('a feature the plan does not mention is left alone entirely', function () {
    $tenant = tenantOn('growth');

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'llm_byok',
        'enabled' => true,
        'meta' => ['type' => 'boolean', 'source' => 'admin_override'],
    ]);

    $tenant->syncFeaturesFromPackage();

    expect($tenant->fresh()->featureEnabled('llm_byok'))->toBeTrue();
});

test('an ordinary package feature is still refreshed from the plan', function () {
    $tenant = tenantOn('enterprise');

    expect($tenant->planAllows('white_label'))->toBeTrue();

    // No override here, so a downgrade must take it away.
    $tenant->update(['package_id' => Package::where('slug', 'free')->firstOrFail()->id]);
    $tenant->syncFeaturesFromPackage();

    expect($tenant->fresh()->planAllows('white_label'))->toBeFalse();
});
