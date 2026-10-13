<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantFeature;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('dues are off for a tenant until enabled', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);

    expect($tenant->duesEnabled())->toBeFalse();
});

test('dues:enable switches dues on as an admin override that a feature sync keeps', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);

    $this->artisan('dues:enable', ['tenant' => 'union'])->assertSuccessful();
    expect($tenant->fresh()->duesEnabled())->toBeTrue();

    $row = TenantFeature::where('tenant_id', $tenant->id)->where('feature_key', 'member_dues')->firstOrFail();
    expect($row->meta['source'] ?? null)->toBe('admin_override');

    $this->artisan('tenants:sync-features')->assertSuccessful();
    expect($tenant->fresh()->duesEnabled())->toBeTrue();
});

test('dues:enable --off switches dues back off', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);
    $this->artisan('dues:enable', ['tenant' => 'union'])->assertSuccessful();

    $this->artisan('dues:enable', ['tenant' => 'union', '--off' => true])->assertSuccessful();

    expect($tenant->fresh()->duesEnabled())->toBeFalse();
});

test('dues:enable fails clearly for an unknown tenant', function () {
    $this->artisan('dues:enable', ['tenant' => 'nope'])->assertFailed();
});

test('org superadmin and org admin roles hold manage members, other roles do not', function () {
    [$tenant, $user] = eventHost('acme');

    expect($user->can('manage members'))->toBeTrue();
    expect(config('services.dues.platform_fee_percentage'))->toBe(1.0);
});

test('an enabled member_dues row shows as a shared tenant feature', function () {
    [$tenant, $user] = eventHost('acme');
    $this->artisan('dues:enable', ['tenant' => 'acme'])->assertSuccessful();

    $host = eventSubdomainHost('acme');

    $this->actingAs($user)
        ->get("http://{$host}/settings/webhooks", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.features.member_dues', true)
            ->where('auth.can.manage_members', true));
});
