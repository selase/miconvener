<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Libraries\Helper;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Showing your own logo instead of MiConvener's is white-labelling, which is an
 * Enterprise capability. Everyone else runs on the platform's mark.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->seed(EventPackageSeeder::class);
});

function tenantOnPlan(string $slug, string $planSlug): array
{
    $tenant = Tenant::factory()->create([
        'slug' => $slug,
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', $planSlug)->firstOrFail()->id,
        'logo' => 'tenant/logo/theirs.png',
    ]);
    $tenant->syncFeaturesFromPackage();

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    app(TenantContext::class)->setTenant($tenant);

    return [$tenant, $user, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('an enterprise tenant shows its own logo', function () {
    [$tenant] = tenantOnPlan('logo-enterprise', 'enterprise');

    expect($tenant->canUseOwnLogo())->toBeTrue();
    expect(Helper::getTenantLogoUrl())->toContain('theirs.png');
});

test('a growth tenant falls back to the platform mark even with a logo on file', function () {
    [$tenant] = tenantOnPlan('logo-growth', 'growth');

    expect($tenant->canUseOwnLogo())->toBeFalse();

    // The file is kept, just not shown -- upgrading restores it rather than
    // asking them to upload it again.
    expect($tenant->logo)->toBe('tenant/logo/theirs.png');
    expect(Helper::getTenantLogoUrl())->toContain('brand/mark');
});

test('a free tenant cannot upload a logo at all', function () {
    Storage::fake('public');
    [$tenant, $user, $host] = tenantOnPlan('logo-free', 'free');

    $this->actingAs($user)
        ->from("http://{$host}/settings")
        ->post("http://{$host}/settings", [
            'name' => $tenant->name,
            'email' => 'org@example.com',
            'phone_number' => '233200000000',
            'logo' => UploadedFile::fake()->create('mine.png', 40, 'image/png'),
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('logo');

    expect($tenant->fresh()->logo)->toBe('tenant/logo/theirs.png');
});

test('an enterprise tenant can upload a logo', function () {
    Storage::fake('public');
    [$tenant, $user, $host] = tenantOnPlan('logo-ent-upload', 'enterprise');

    $this->actingAs($user)
        ->from("http://{$host}/settings")
        ->post("http://{$host}/settings", [
            'name' => $tenant->name,
            'email' => 'org@example.com',
            'phone_number' => '233200000000',
            'logo' => UploadedFile::fake()->create('mine.png', 40, 'image/png'),
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasNoErrors();

    expect($tenant->fresh()->logo)->not->toBe('tenant/logo/theirs.png');
});

test('a tenant whose features were never synced is not stripped of its logo', function () {
    // Absence of a feature row means the plan was never synced, not that
    // white-labelling was withdrawn -- failing closed here would silently
    // rebrand a paying customer.
    $tenant = Tenant::factory()->create([
        'slug' => 'logo-unsynced',
        'isolation_mode' => 'shared',
        'logo' => 'tenant/logo/theirs.png',
    ]);
    app(TenantContext::class)->setTenant($tenant);

    expect(TenantFeature::where('tenant_id', $tenant->id)->count())->toBe(0);
    expect($tenant->canUseOwnLogo())->toBeTrue();
});
