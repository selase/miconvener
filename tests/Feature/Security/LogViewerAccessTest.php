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

test('the application superadmin can open the log viewer', function () {
    $superadmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superadmin->assignRole('Superadmin');

    $this->actingAs($superadmin)->get('/log-viewer')->assertOk();
});

test('a tenant user cannot open the log viewer by taking a privileged email address', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);

    /*
     * Production logs carry request data, errors and stack traces. Access was
     * granted by email match, but users.email is unique globally and tenant
     * admins set team members' addresses, so any unclaimed address on the list
     * was a tenant-controlled value. dev@wearepurpledot.com was unclaimed.
     */
    $impostor = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dev@wearepurpledot.com']);
    setPermissionsTeamId($tenant->id);
    $impostor->assignRole('Org Superadmin');

    $this->actingAs($impostor)->get('/log-viewer')->assertForbidden();
});

test('a guest cannot open the log viewer', function () {
    $this->get('/log-viewer')->assertForbidden();
});
