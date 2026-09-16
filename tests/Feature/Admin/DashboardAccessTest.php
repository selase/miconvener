<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The platform admin dashboard (Admin\DashboardController, /dashboard on the
 * landlord domain) reports cross-tenant figures: total transaction volume
 * across every tenant, active subscription counts, the most recently created
 * users platform-wide, and a "Top 5 Tenants by Users" list naming other
 * organizations. It was gated on 'access dashboard' -- a permission every
 * Org Superadmin and Org Admin is granted (by the real PermissionsSeeder) on
 * every tenant, unlike every sibling Admin\* controller, which correctly
 * requires the access-superadmin-dashboard gate. Any tenant admin who
 * visited the root domain's /dashboard (rather than their own tenant
 * subdomain) saw platform-wide revenue and other tenants' names -- found
 * live on the demo environment 2026-09-16.
 */
beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
});

/**
 * @return array{0: User, 1: Tenant}
 */
function tenantMemberForDashboardTest(string $role): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);

    return [$user, $tenant];
}

test('an org superadmin of one tenant cannot see the platform-wide dashboard', function (): void {
    [$user] = tenantMemberForDashboardTest('Org Superadmin');

    expect($user->can('access dashboard'))->toBeTrue(); // the permission this bug hinges on

    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});

test('an org admin cannot see the platform-wide dashboard either', function (): void {
    [$user] = tenantMemberForDashboardTest('Org Admin');

    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});

test('a user with no role at all cannot see the platform-wide dashboard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});

test('a real global superadmin still sees the platform-wide dashboard, with cross-tenant figures', function (): void {
    $superadmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superadmin->assignRole(Role::where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());

    Transaction::create([
        'tenant_id' => Tenant::factory()->create()->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => 'success',
        'provider' => 'stripe',
        'provider_transaction_id' => 'tx_dashboard_access_test',
        'type' => 'charge',
    ]);

    $response = $this->actingAs($superadmin)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('totalSuccessVolume', 5000);
    $response->assertViewHas('topTenantsByUsers');
});

test('an org superadmin cannot self-grant platform-admin access by naming a custom role "Superadmin"', function (): void {
    [$user, $tenant] = tenantMemberForDashboardTest('Org Superadmin');
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($user)->post("http://{$host}/roles", [
        'name' => 'Superadmin',
        'permissions' => [],
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors('name');
    expect(Role::where('tenant_id', $tenant->id)->where('name', 'Superadmin')->exists())->toBeFalse();
});
