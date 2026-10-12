<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Platform finance staff are not superadmins: they read the money, release
 * payouts, bill organisations and manage commission accounts, and nothing else.
 */
beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
});

function financeUser(string $role = 'Platform Finance'): User
{
    $user = User::factory()->create(['tenant_id' => null, 'password' => bcrypt('password')]);
    setPermissionsTeamId(null);
    $user->assignRole(Role::query()->where('name', $role)->whereNull('tenant_id')->firstOrFail());

    return $user;
}

test('platform finance can open every finance page', function (string $routeName): void {
    $this->actingAs(financeUser())->get(route($routeName))->assertOk();
})->with([
    'admin.finance.overview',
    'admin.billing.earnings.index',
    'admin.billing.payouts.index',
    'admin.billing.transactions.index',
    'admin.billing.subscriptions.index',
    'admin.billing.rate-cards.index',
    'admin.billing.invoices.index',
    'admin.billing.invoices.create',
    'admin.billing.commission-credits.index',
    'admin.billing.commission-destinations.index',
]);

test('platform finance cannot reach what changes what an organisation owes or runs the platform', function (string $routeName): void {
    $this->actingAs(financeUser())->get(route($routeName))->assertForbidden();
})->with(['packages.index', 'admin.billing.analytics.usage', 'admin.operations.index', 'health.tenants']);

test('platform finance cannot switch an organisation to a complimentary plan', function (): void {
    $tenant = Tenant::factory()->create();

    $this->actingAs(financeUser())->post(route('admin.billing.subscriptions.toggle-complimentary', $tenant->uuid))->assertForbidden();
});

test('the superadmin still opens everything', function (string $routeName): void {
    $this->actingAs(financeUser('Superadmin'))->get(route($routeName))->assertOk();
})->with(['admin.finance.overview', 'admin.billing.earnings.index', 'admin.billing.payouts.index', 'admin.billing.invoices.index', 'admin.billing.commission-destinations.index']);

test('an organisation admin cannot open any finance page', function (string $routeName): void {
    $tenant = Tenant::factory()->create();
    $member = User::factory()->create(['tenant_id' => $tenant->id]);
    setActiveTenantForTest($member);
    $member->assignRole('Org Superadmin');

    $this->actingAs($member)->get(route($routeName))->assertForbidden();
})->with(['admin.finance.overview', 'admin.billing.earnings.index', 'admin.billing.payouts.index', 'admin.billing.invoices.index']);

test('a role with only the view permission can read but not release payouts or bill', function (): void {
    $role = Role::query()->create(['name' => 'Finance Viewer', 'guard_name' => 'web', 'tenant_id' => null, 'slug' => 'finance_viewer', 'uuid' => (string) Str::uuid()]);
    $role->givePermissionTo(Permission::findByName('view platform finance'));
    $user = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $user->assignRole($role);

    $this->actingAs($user)->get(route('admin.billing.payouts.index'))->assertOk();
    $this->actingAs($user)->post(route('admin.billing.payouts.release', 'anything'), ['otp' => '123456'])->assertForbidden();
    $this->actingAs($user)->get(route('admin.billing.invoices.create'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.billing.commission-credits.index'))->assertForbidden();
});

test('finance staff landing on the generic dashboard are sent to the finance overview', function (): void {
    $this->actingAs(financeUser())->get(route('dashboard'))->assertRedirect(route('admin.finance.overview'));
});

test('signing in as platform finance lands on the finance overview, not a 403', function (): void {
    $user = financeUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('admin.finance.overview'));
});

test('the menu shows finance staff the finance pages and none of the superadmin-only ones', function (): void {
    $this->actingAs(financeUser())->get(route('admin.finance.overview'))
        ->assertOk()
        ->assertSee('Finance overview')
        ->assertSee('Commission destinations')
        ->assertSee('Payouts &amp; refunds', false)
        ->assertDontSee('Subscription Plans')
        ->assertDontSee('Usage Analytics')
        ->assertDontSee('Operations');
});

test('the overview shows what is waiting and what is owed', function (): void {
    $tenant = Tenant::factory()->create();
    app(App\Services\Finance\CommissionCredit::class)->grant($tenant, 'GHS', 12_550);

    $this->actingAs(financeUser())->get(route('admin.finance.overview'))
        ->assertOk()
        ->assertSee('GHS 125.50')
        ->assertSee('Earned this month');
});

test('the platform finance role holds exactly the finance permissions', function (): void {
    $role = Role::query()->where('name', 'Platform Finance')->whereNull('tenant_id')->firstOrFail();

    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe([
        'manage commission credits',
        'manage commission destinations',
        'manage invoices',
        'release payouts',
        'view platform finance',
    ]);
});
