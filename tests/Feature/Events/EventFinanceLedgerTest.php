<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function ledgerFinanceHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('finance stats and available balance are computed from the ledger, reflecting charges, refunds and payouts', function () {
    [$tenant, $user] = ledgerFinanceHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    EventLedgerEntry::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'gross_amount' => 10_000, 'gateway_fee_amount' => 150, 'commission_amount' => 500, 'net_amount' => 9_350]);
    EventLedgerEntry::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'gross_amount' => 20_000, 'gateway_fee_amount' => 300, 'commission_amount' => 1_000, 'net_amount' => 18_700]);
    EventLedgerEntry::factory()->refund()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'gross_amount' => 10_000, 'gateway_fee_amount' => 150, 'commission_amount' => 500, 'net_amount' => -9_350]);

    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    EventPayout::factory()->paid()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'amount' => 5_000]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/finance", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('stats.collected', 30_000); // sum of the two charges' gross
    $response->assertJsonPath('stats.net_collected', 18_700); // 9,350 + 18,700 - 9,350 (refunded charge's net reversed)
    $response->assertJsonPath('stats.available_balance', 13_700); // 18,700 net collected - 5,000 already paid out
});

test('a payout cannot be scheduled for more than the available balance', function () {
    [$tenant, $user] = ledgerFinanceHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventLedgerEntry::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'net_amount' => 9_350]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts", [
        'payout_account_id' => $account->id,
        'amount' => 20_000,
    ], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    expect(EventPayout::where('event_id', $event->id)->count())->toBe(0);
});

test('a payout within the available balance is scheduled and reduces the balance for the next request', function () {
    [$tenant, $user] = ledgerFinanceHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventLedgerEntry::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'net_amount' => 9_350]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $first = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts", [
        'payout_account_id' => $account->id,
        'amount' => 6_000,
    ], ['HTTP_HOST' => $host]);
    $first->assertCreated();

    $second = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts", [
        'payout_account_id' => $account->id,
        'amount' => 6_000,
    ], ['HTTP_HOST' => $host]);
    $second->assertStatus(422); // only 3,350 remains after the first 6,000 payout
});
