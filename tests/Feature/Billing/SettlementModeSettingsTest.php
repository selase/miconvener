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

function settlementModeHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('the payments settings page shows the current settlement mode', function () {
    [$tenant, $user] = settlementModeHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/settings/payments", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertSee('platform_default', false);
});

test('a host can switch a tenant to own_gateway settlement mode', function () {
    [$tenant, $user] = settlementModeHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/settings/payments/settlement-mode", [
        'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect($tenant->fresh()->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
});

test('switching to platform_default is blocked when the platform has no settlement credentials configured', function () {
    config(['services.settlement.paystack.secret_key' => null]);
    [$tenant, $user] = settlementModeHost();
    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/settings/payments/settlement-mode", [
        'settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($tenant->fresh()->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
});
