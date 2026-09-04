<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('global superadmin can view the design system showcase', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);

    $globalAdmin = User::factory()->create();
    setPermissionsTeamId(null);
    $globalAdmin->assignRole('Superadmin');
    $tenant->users()->attach($globalAdmin->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $subdomainHost = "acme.{$baseDomain}";

    $this->actingAs($globalAdmin)
        ->get("http://{$subdomainHost}/design-system", ['HTTP_HOST' => $subdomainHost])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/DesignSystem/Index'));
});

test('a regular tenant user cannot view the design system showcase', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $subdomainHost = "acme.{$baseDomain}";

    $this->actingAs($user)
        ->get("http://{$subdomainHost}/design-system", ['HTTP_HOST' => $subdomainHost])
        ->assertForbidden();
});
