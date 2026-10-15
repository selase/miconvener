<?php

declare(strict_types=1);

use App\Models\Package;
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

    expect($tenant->communityEnabled())->toBeFalse();
});

test('community:enable switches dues on as an admin override that a feature sync keeps', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union', 'package_id' => Package::factory()->create()->id]);

    $this->artisan('community:enable', ['tenant' => 'union'])->assertSuccessful();
    expect($tenant->fresh()->communityEnabled())->toBeTrue();

    $row = TenantFeature::where('tenant_id', $tenant->id)->where('feature_key', 'community')->firstOrFail();
    expect($row->meta['source'] ?? null)->toBe('admin_override');

    $this->artisan('tenants:sync-features')->assertSuccessful();
    expect($tenant->fresh()->communityEnabled())->toBeTrue();
});

test('community:enable --off switches dues back off', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);
    $this->artisan('community:enable', ['tenant' => 'union'])->assertSuccessful();

    $this->artisan('community:enable', ['tenant' => 'union', '--off' => true])->assertSuccessful();

    expect($tenant->fresh()->communityEnabled())->toBeFalse();
});

test('community:enable fails clearly for an unknown tenant', function () {
    $this->artisan('community:enable', ['tenant' => 'nope'])->assertFailed();
});

test('org superadmin and org admin roles hold manage members, event staff do not', function () {
    [$tenant, $owner] = eventHost('acme');

    foreach (['Org Admin' => true, 'Event Staff' => false] as $role => $expected) {
        $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);

        expect($user->can('manage members'))->toBe($expected);
    }

    expect($owner->can('manage members'))->toBeTrue();
});

test('the dues platform fee defaults to one percent', function () {
    expect(config('services.dues.platform_fee_percentage'))->toBe(1.0);
});

test('an enabled member_dues row shows as a shared tenant feature', function () {
    [$tenant, $user] = eventHost('acme');
    $this->artisan('community:enable', ['tenant' => 'acme'])->assertSuccessful();

    $host = eventSubdomainHost('acme');

    $this->actingAs($user)
        ->get("http://{$host}/settings/webhooks", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.features.community', true)
            ->where('auth.can.manage_members', true));
});

test('an existing member_dues switch becomes a community switch', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);
    $migration = require database_path('migrations/landlord/2026_10_15_100100_rename_member_dues_feature_rows.php');
    $migration->down();
    TenantFeature::query()->where('tenant_id', $tenant->id)->delete();
    $tenant->features()->create(['feature_key' => 'member_dues', 'enabled' => true, 'meta' => ['source' => 'admin_override']]);

    $migration->up();

    $row = TenantFeature::query()->where('tenant_id', $tenant->id)->where('feature_key', 'community')->firstOrFail();
    expect($row->enabled)->toBeTrue()
        ->and($row->meta['source'] ?? null)->toBe('admin_override')
        ->and(TenantFeature::query()->where('feature_key', 'member_dues')->exists())->toBeFalse()
        ->and($tenant->fresh()->communityEnabled())->toBeTrue();
});

test('a tenant holds dues money once it has a dues ledger account or a dues payout', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);
    expect($tenant->hasDuesMoney())->toBeFalse();

    app(App\Services\Finance\LedgerService::class)->ensureAccountsExist($tenant, null, 'GHS', App\Enum\LedgerCategoryEnum::Dues);

    expect($tenant->fresh()->hasDuesMoney())->toBeTrue();
});

test('finance shows for a tenant with community on, even without ticket money', function () {
    [$tenant, $user] = eventHost('acme');
    $tenant->features()->create(['feature_key' => 'paid_tickets', 'enabled' => false]);
    expect($tenant->fresh()->handlesTicketMoney())->toBeFalse();
    $this->artisan('community:enable', ['tenant' => 'acme'])->assertSuccessful();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.dashboard', ['subdomain' => 'acme']))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('tenant.features.finance', true)->where('tenant.features.community', true));

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.finance.index', ['subdomain' => 'acme']))
        ->assertOk();
});

test('payouts stay in the menu for a tenant with dues money after Community is switched off', function () {
    [$tenant, $user] = eventHost('acme');
    app(App\Services\Finance\LedgerService::class)->ensureAccountsExist($tenant, null, 'GHS', App\Enum\LedgerCategoryEnum::Dues);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.dashboard', ['subdomain' => 'acme']))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('tenant.features.community', false)->where('tenant.features.dues_payouts', true));
});
