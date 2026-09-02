<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
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
    $response->assertSee('Organization Name');
    $response->assertSee('acme');

    // 2. Update settings
    $response = $this->actingAs($user)
        ->post("http://{$subdomainHost}/settings", [
            'name' => 'Acme Corp Updated',
            'email' => 'support@acme.com',
            'phone_number' => '555-1234',
            'primary_color' => '#FF0000',
            'custom_domain' => 'app.acme.com',
        ], ['HTTP_HOST' => $subdomainHost]);

    $response->assertRedirect();

    $tenant->refresh();
    expect($tenant->name)->toBe('Acme Corp Updated');
    expect($tenant->email)->toBe('support@acme.com');
    expect($tenant->meta['primary_color'])->toBe('#FF0000');
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
