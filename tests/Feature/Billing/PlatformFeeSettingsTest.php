<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a platform superadmin can set a tenant platform fee percentage from the settings screen', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);
    $user = User::factory()->create();
    setPermissionsTeamId(null);
    $user->assignRole('Superadmin');
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/settings/payments/platform-fee", [
        'platform_fee_percentage' => 7.5,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect((float) $tenant->fresh()->platform_fee_percentage)->toBe(7.5);
});

test('a non-platform-superadmin tenant user cannot set the platform fee percentage', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'someone-else@example.com']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/settings/payments/platform-fee", [
        'platform_fee_percentage' => 7.5,
    ], ['HTTP_HOST' => $host]);

    $response->assertForbidden();
});

test('a tenant user cannot reach the platform fee by taking a privileged email address', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);
    $tenant->update(['platform_fee_percentage' => 2.0]);

    /*
     * users.email is unique globally rather than per tenant, and a tenant
     * admin sets team members' addresses, so any unclaimed address is a
     * tenant-controlled value. Authorization must not read this column.
     */
    $impostor = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dev@wearepurpledot.com']);
    setPermissionsTeamId($tenant->id);
    $impostor->assignRole('Org Superadmin');
    $tenant->users()->attach($impostor->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($impostor)->post("http://{$host}/settings/payments/platform-fee", [
        'platform_fee_percentage' => 0,
    ], ['HTTP_HOST' => $host])->assertForbidden();

    expect((float) $tenant->fresh()->platform_fee_percentage)->toEqual(2.0);
});

test('a platform superadmin can set the commission cap alongside the percentage', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);

    $user = User::factory()->create();
    setPermissionsTeamId(null);
    $user->assignRole('Superadmin');
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->post("http://{$host}/settings/payments/platform-fee", [
        'platform_fee_percentage' => 1.75,
        'platform_fee_cap_amount' => 2500,
    ], ['HTTP_HOST' => $host])->assertRedirect();

    expect((float) $tenant->fresh()->platform_fee_percentage)->toEqual(1.75)
        ->and($tenant->fresh()->platform_fee_cap_amount)->toBe(2500);
});
