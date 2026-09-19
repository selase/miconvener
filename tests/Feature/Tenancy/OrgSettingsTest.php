<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('org admin can access and update tenant settings via subdomain', function () {
    Storage::fake('public');

    // Create a tenant
    $tenant = Tenant::factory()->create([
        'slug' => 'acme',
        'isolation_mode' => 'shared',
    ]);

    // Create an Org Admin and associate with tenant
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Admin');

    $tenant->users()->attach($user->id);

    // Get the base domain
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $subdomainHost = "acme.{$baseDomain}";

    // 1. Access settings page
    $response = $this->actingAs($user)
        ->get("http://{$subdomainHost}/settings", ['HTTP_HOST' => $subdomainHost]);

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Settings/Index')
        ->where('tenant.name', $tenant->name)
    );

    // 2. Update settings
    $response = $this->actingAs($user)
        ->post("http://{$subdomainHost}/settings", [
            'name' => 'Acme Corp Updated',
            'email' => 'support@acme.com',
            'phone_number' => '555-1234',
            'primary_color' => '#FF0000',
        ], ['HTTP_HOST' => $subdomainHost]);

    $response->assertRedirect();

    $tenant->refresh();
    expect($tenant->name)->toBe('Acme Corp Updated');
    expect($tenant->email)->toBe('support@acme.com');
    expect($tenant->meta['primary_color'])->toBe('#FF0000');
});

test('starter tenants cannot see, set, or verify a custom domain', function () {
    $this->seed(EventPackageSeeder::class);
    $tenant = Tenant::factory()->create([
        'slug' => 'starter-settings',
        'package_id' => App\Models\Package::where('slug', 'starter')->firstOrFail()->id,
    ]);
    $tenant->syncFeaturesFromPackage();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $host = 'starter-settings.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($user)
        ->get("http://{$host}/settings", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/Index')
            ->where('org.can_use_custom_domain', false));

    $this->actingAs($user)
        ->from("http://{$host}/settings")
        ->post("http://{$host}/settings", [
            'name' => $tenant->name,
            'email' => $tenant->email,
            'phone_number' => '233200000000',
            'custom_domain' => 'events.example.com',
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('custom_domain');

    expect($tenant->fresh()->custom_domain)->toBeNull();

    $this->actingAs($user)
        ->post("http://{$host}/settings/verify-domain", [], ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('growth tenants can configure a custom domain', function () {
    $this->seed(EventPackageSeeder::class);
    $tenant = Tenant::factory()->create([
        'slug' => 'growth-settings',
        'package_id' => App\Models\Package::where('slug', 'growth')->firstOrFail()->id,
    ]);
    $tenant->syncFeaturesFromPackage();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $host = 'growth-settings.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($user)
        ->get("http://{$host}/settings", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('org.can_use_custom_domain', true));

    $this->actingAs($user)
        ->post("http://{$host}/settings", [
            'name' => $tenant->name,
            'email' => $tenant->email,
            'phone_number' => '233200000000',
            'custom_domain' => 'events.example.com',
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasNoErrors();

    expect($tenant->fresh()->custom_domain)->toBe('events.example.com');
});

test('user without manage organization settings permission cannot access settings', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(); // No roles assigned
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $subdomainHost = "acme.{$baseDomain}";

    $response = $this->actingAs($user)
        ->get("http://{$subdomainHost}/settings", ['HTTP_HOST' => $subdomainHost]);

    $response->assertStatus(403);
});
