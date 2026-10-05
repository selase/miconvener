<?php

declare(strict_types=1);

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PlatformEarnings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * What MiConvener earned: commission credited to the ledger's platform
 * revenue account (net of refunds), plus what organisations paid us.
 */
beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create(['name' => 'Accra Summit']);
});

function earnCommission(Tenant $tenant, string $type, int $commission, string $reference, string $direction = LedgerEntry::DIRECTION_CREDIT): void
{
    $other = $direction === LedgerEntry::DIRECTION_CREDIT ? LedgerEntry::DIRECTION_DEBIT : LedgerEntry::DIRECTION_CREDIT;

    app(LedgerService::class)->postTransaction($tenant, null, $type, 'test', $reference, [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => $other, 'amount' => $commission],
        ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => $direction, 'amount' => $commission],
    ]);
}

function paidUs(Tenant $tenant, int $amount, array $meta, array $overrides = []): void
{
    Transaction::query()->create($overrides + [
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => uniqid('ref_'),
        'amount' => $amount,
        'currency' => 'ghs',
        'status' => 'success',
        'type' => 'charge',
        'meta' => $meta,
    ]);
}

function earningsSuperadmin(): User
{
    $superadmin = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());

    return $superadmin;
}

test('commission is counted by kind and refunds take it back', function (): void {
    earnCommission($this->tenant, LedgerTransaction::TYPE_TICKET_SALE, 5000, 'sale-1');
    earnCommission($this->tenant, LedgerTransaction::TYPE_TICKET_SALE, 3000, 'sale-2');
    earnCommission($this->tenant, LedgerTransaction::TYPE_CONTRIBUTION, 100, 'gift-1');
    earnCommission($this->tenant, 'marketplace_booking', 7150, 'booking-1');
    earnCommission($this->tenant, LedgerTransaction::TYPE_REFUND, 3000, 'refund-1', LedgerEntry::DIRECTION_DEBIT);

    $earnings = app(PlatformEarnings::class)->between(now()->subDay(), now()->addDay());
    $lines = collect($earnings['lines'])->keyBy('label');

    expect($lines['Ticket commission']['amount'])->toBe(8000)
        ->and($lines['Ticket commission']['count'])->toBe(2)
        ->and($lines['Contribution fees']['amount'])->toBe(100)
        ->and($lines['Marketplace commission and buyer fees']['amount'])->toBe(7150)
        ->and($lines['Refunded commission']['amount'])->toBe(-3000)
        ->and($earnings['commission_total'])->toBe(12250);
});

test('payments to MiConvener are grouped by what was bought', function (): void {
    paidUs($this->tenant, 24900, ['package_id' => 2, 'provider_plan_id' => 'starter_month']);
    paidUs($this->tenant, 24900, ['type' => 'plan_renewal']);
    paidUs($this->tenant, 15000, ['type' => 'tenant_addon', 'addon_type' => 'shop_verification']);
    paidUs($this->tenant, 9000, ['type' => 'tenant_addon', 'addon_type' => 'sms_pack']);

    $lines = collect(app(PlatformEarnings::class)->between(now()->subDay(), now()->addDay())['lines'])->keyBy('label');

    expect($lines['New plans and upgrades']['amount'])->toBe(24900)
        ->and($lines['Plan renewals']['amount'])->toBe(24900)
        ->and($lines['Marketplace promotions']['amount'])->toBe(15000)
        ->and($lines['Add-ons: sms pack']['amount'])->toBe(9000);
});

test('failed, test-bypass and foreign-currency payments are not counted as cedi earnings', function (): void {
    paidUs($this->tenant, 24900, ['type' => 'plan_renewal'], ['status' => 'failed']);
    paidUs($this->tenant, 24900, ['type' => 'plan_renewal'], ['provider' => 'dev_bypass']);
    paidUs($this->tenant, 5000, ['type' => 'plan_renewal'], ['currency' => 'usd']);

    $earnings = app(PlatformEarnings::class)->between(now()->subDay(), now()->addDay());

    expect($earnings['billing_total'])->toBe(0)
        ->and($earnings['other_currencies'])->toBe(1);
});

test('only the chosen period is counted, and organisations are ranked by what they paid', function (): void {
    $other = Tenant::factory()->create(['name' => 'Kumasi Expo']);
    earnCommission($this->tenant, LedgerTransaction::TYPE_TICKET_SALE, 5000, 'sale-1');
    paidUs($other, 49900, ['type' => 'plan_renewal']);
    $this->travelTo(CarbonImmutable::now()->subMonths(3));
    earnCommission($this->tenant, LedgerTransaction::TYPE_TICKET_SALE, 99999, 'old-sale');
    $this->travelBack();

    $earnings = app(PlatformEarnings::class)->between(now()->startOfMonth(), now()->addDay());

    expect($earnings['total'])->toBe(54900)
        ->and($earnings['by_tenant'][0]['name'])->toBe('Kumasi Expo')
        ->and($earnings['by_tenant'][1]['amount'])->toBe(5000);
});

test('the superadmin sees the earnings page; organisation admins cannot', function (): void {
    earnCommission($this->tenant, LedgerTransaction::TYPE_TICKET_SALE, 5000, 'sale-1');

    $this->actingAs(earningsSuperadmin())
        ->get(route('admin.billing.earnings.index', ['period' => 'this_month']))
        ->assertOk()
        ->assertSee('GHS 50.00')
        ->assertSee('Ticket commission')
        ->assertSee('Accra Summit');

    $member = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setActiveTenantForTest($member);
    $member->assignRole('Org Superadmin');

    $this->actingAs($member)->get(route('admin.billing.earnings.index'))->assertForbidden();
});
