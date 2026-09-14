<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * A tenant set up the way MiConvener's own packages set one up: paid_tickets
 * on or off, and no `commerce` row at all — that flag came from the starter
 * kit and no MiConvener package grants it.
 *
 * @return array{0: Tenant, 1: User, 2: string}
 */
function paymentsAccessTenant(bool $paidTickets): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'paid_tickets', 'enabled' => $paidTickets]);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user, 'acme.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('a tenant whose plan sells paid tickets can reach payment settings and finance', function () {
    /*
     * These pages were gated on a `commerce` flag no MiConvener package grants,
     * so on production every tenant — and the application superadmin — got 403
     * and the Finance link was missing from the sidebar, while the same tenants
     * were selling paid tickets.
     */
    [$tenant, $user, $host] = paymentsAccessTenant(paidTickets: true);

    $this->actingAs($user)->get("http://{$host}/settings/payments", ['HTTP_HOST' => $host])->assertOk();

    $this->actingAs($user)->get("http://{$host}/finance", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.features.paid_tickets', true));
});

test('a tenant whose plan cannot sell paid tickets is kept out of payment settings and finance', function () {
    [$tenant, $user, $host] = paymentsAccessTenant(paidTickets: false);

    $this->actingAs($user)->get("http://{$host}/settings/payments", ['HTTP_HOST' => $host])->assertForbidden();
    $this->actingAs($user)->get("http://{$host}/finance", ['HTTP_HOST' => $host])->assertForbidden();
});

test('the sidebar shows Finance exactly when the plan sells paid tickets', function () {
    [$tenant, $user, $host] = paymentsAccessTenant(paidTickets: true);

    $this->actingAs($user)->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('tenant.features.paid_tickets', true));

    $tenant->features()->where('feature_key', 'paid_tickets')->update(['enabled' => false]);

    $this->actingAs($user)->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('tenant.features.paid_tickets', false));
});
