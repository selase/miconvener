<?php

declare(strict_types=1);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Finance\CommissionCredit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Commission the platform owes organisers back, and the screen where it is
 * paid out. Open to the superadmin and to platform finance staff.
 */
beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create(['name' => 'Accra Summit']);
    $this->credit = app(CommissionCredit::class);
});

function platformUser(string $role): User
{
    $user = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $user->assignRole(Role::query()->where('name', $role)->whereNull('tenant_id')->firstOrFail());

    return $user;
}

test('the superadmin and platform finance staff can open the page, and it lists who is owed', function (string $role): void {
    $this->credit->grant($this->tenant, 'GHS', 12_550);

    $this->actingAs(platformUser($role))
        ->get(route('admin.billing.commission-credits.index'))
        ->assertOk()
        ->assertSee('Accra Summit')
        ->assertSee('125.50');
})->with(['Superadmin', 'Platform Finance']);

test('an organisation admin and a user with no role or organisation cannot open it', function (): void {
    $member = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setActiveTenantForTest($member);
    $member->assignRole('Org Superadmin');

    $this->actingAs($member)->get(route('admin.billing.commission-credits.index'))->assertForbidden();

    // With no role and no organisation, the app turns them away before the page.
    $this->actingAs(User::factory()->create(['tenant_id' => null]))->get(route('admin.billing.commission-credits.index'))->assertRedirect();
});

test('platform finance staff get the permission and organisation roles never see the role', function (): void {
    $role = Role::query()->where('name', 'Platform Finance')->whereNull('tenant_id')->firstOrFail();

    expect($role->hasPermissionTo('manage commission credits'))->toBeTrue()
        ->and($role->isSystemRole())->toBeTrue()
        ->and(Role::query()->where('name', 'Superadmin')->firstOrFail()->hasPermissionTo('manage commission credits'))->toBeTrue()
        ->and(Role::query()->where('name', 'Org Superadmin')->firstOrFail()->hasPermissionTo('manage commission credits'))->toBeFalse();
});

test('recording a payout clears that much credit and books the cash leaving', function (): void {
    $this->credit->grant($this->tenant, 'GHS', 10_000);

    $this->actingAs(platformUser('Platform Finance'))
        ->post(route('admin.billing.commission-credits.settle', $this->tenant->uuid), ['currency' => 'GHS', 'amount' => '40.00'])
        ->assertSessionHas('success');

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(6_000)
        ->and(LedgerTransaction::where('tenant_id', $this->tenant->id)->where('transaction_type', LedgerTransaction::TYPE_PAYOUT)->count())->toBe(1);

    $ledger = collect(DB::connection('landlord')->table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_accounts.tenant_id', $this->tenant->id)
        ->get(['ledger_accounts.code', 'ledger_entries.direction', 'ledger_entries.amount']));
    expect($ledger->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)->where('direction', 'debit')->sum('amount'))->toBe(4_000)
        ->and($ledger->where('code', LedgerAccount::CODE_BANK)->where('direction', 'credit')->sum('amount'))->toBe(4_000);
});

test('more than is owed is refused and changes nothing', function (): void {
    $this->credit->grant($this->tenant, 'GHS', 1_000);

    $this->actingAs(platformUser('Superadmin'))
        ->post(route('admin.billing.commission-credits.settle', $this->tenant->uuid), ['currency' => 'GHS', 'amount' => '50.00'])
        ->assertSessionHas('error');

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(1_000)
        ->and(LedgerTransaction::where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('settling a currency with no credit is refused, and a zero or missing amount is invalid', function (): void {
    $this->credit->grant($this->tenant, 'GHS', 1_000);
    $user = platformUser('Superadmin');

    $this->actingAs($user)
        ->post(route('admin.billing.commission-credits.settle', $this->tenant->uuid), ['currency' => 'USD', 'amount' => '5'])
        ->assertSessionHas('error');

    $this->actingAs($user)
        ->post(route('admin.billing.commission-credits.settle', $this->tenant->uuid), ['currency' => 'GHS', 'amount' => '0'])
        ->assertSessionHasErrors('amount');
});

test('an organisation admin cannot record a payout', function (): void {
    $this->credit->grant($this->tenant, 'GHS', 1_000);
    $member = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setActiveTenantForTest($member);
    $member->assignRole('Org Superadmin');

    $this->actingAs($member)
        ->post(route('admin.billing.commission-credits.settle', $this->tenant->uuid), ['currency' => 'GHS', 'amount' => '5'])
        ->assertForbidden();

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(1_000);
});

test('the menu link shows for finance staff who are not superadmins', function (): void {
    $this->credit->grant($this->tenant, 'GHS', 1_000);

    $this->actingAs(platformUser('Platform Finance'))
        ->get(route('admin.billing.commission-credits.index'))
        ->assertSee(route('admin.billing.commission-credits.index'), false);
});
