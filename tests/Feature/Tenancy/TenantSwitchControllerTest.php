<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

uses(RefreshDatabase::class);

test('it lists tenants for user', function () {
    $user = User::factory()->create();
    $tenant1 = Tenant::create(['name' => 'Tenant 1', 'slug' => 'tenant-1', 'isolation_mode' => 'shared']);
    $tenant2 = Tenant::create(['name' => 'Tenant 2', 'slug' => 'tenant-2', 'isolation_mode' => 'shared']);

    $tenant1->users()->attach($user->id);
    $tenant2->users()->attach($user->id);

    $this->actingAs($user)->getJson(route('tenant.my-tenants'))
        ->assertStatus(200)
        ->assertJsonCount(2);
});

test('it switches tenant successfully', function () {
    $user = User::factory()->create();
    $tenant = Tenant::create(['name' => 'Switch Tenant', 'slug' => 'switch-tenant', 'isolation_mode' => 'shared']);
    $tenant->users()->attach($user->id);

    $this->actingAs($user)
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->postJson(route('tenant.switch'), [
            'tenant_id' => $tenant->id,
        ])
        ->assertStatus(200)
        ->assertJson(['message' => 'Tenant switched successfully']);

    expect(Session::get('active_tenant_id'))->toBe($tenant->id);

    expect(DB::connection('landlord')->table('tenant_switch_audit')
        ->where('user_id', $user->id)
        ->where('to_tenant_id', $tenant->id)
        ->exists())->toBeTrue();
});

test('it prevents switching to non member tenant', function () {
    $user = User::factory()->create();
    $tenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-tenant', 'isolation_mode' => 'shared']);
    // No attachment

    $this->actingAs($user)
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->postJson(route('tenant.switch'), [
            'tenant_id' => $tenant->id,
        ])
        ->assertStatus(403);
    expect(Session::get('active_tenant_id'))->toBeNull();
});
