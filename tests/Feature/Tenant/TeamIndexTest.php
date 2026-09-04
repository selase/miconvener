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

test('org admin can view the team page for their tenant', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Admin');
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $subdomainHost = "acme.{$baseDomain}";

    $this->actingAs($user)
        ->get("http://{$subdomainHost}/users", ['HTTP_HOST' => $subdomainHost])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Team/Index')
            ->has('users.data', 1)
            ->where('users.data.0.email', $user->email)
            ->where('users.data.0.first_name', $user->first_name)
            ->where('users.data.0.last_name', $user->last_name)
            ->where('users.data.0.role_id', $user->roles->first()?->id)
            ->has('roles')
            ->has('statuses')
        );
});

test('user without read user permission cannot view the team page', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $subdomainHost = "acme.{$baseDomain}";

    $this->actingAs($user)
        ->get("http://{$subdomainHost}/users", ['HTTP_HOST' => $subdomainHost])
        ->assertForbidden();
});
