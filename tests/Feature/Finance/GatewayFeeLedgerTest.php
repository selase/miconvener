<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * What the platform still owes the organizer on this event, in minor units.
 */
function payableBalance(Event $event): int
{
    $account = LedgerAccount::on('landlord')
        ->where('event_id', $event->id)
        ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
        ->firstOrFail();

    $credits = LedgerEntry::on('landlord')
        ->where('account_id', $account->id)
        ->where('direction', LedgerEntry::DIRECTION_CREDIT)
        ->sum('amount');

    $debits = LedgerEntry::on('landlord')
        ->where('account_id', $account->id)
        ->where('direction', LedgerEntry::DIRECTION_DEBIT)
        ->sum('amount');

    return (int) $credits - (int) $debits;
}

function saleFixture(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared', 'package_id' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

    return [$event, $registration];
}

test('a ticket sale credits the organizer only the cash that actually arrives', function () {
    [$event, $registration] = saleFixture('gw-fee-1');

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'TEST-SALE-1', 195);

    // Gross 10000, Paystack keeps 195, the platform keeps 200.
    expect(payableBalance($event))->toBe(9605);
});

test('a payout with a passed-through transfer fee clears the payable to zero', function () {
    [$event, $registration] = saleFixture('gw-fee-2');

    $ledger = app(LedgerService::class);
    $ledger->recordTicketSale($event, $registration, 10000, 200, 'TEST-SALE-2', 195);
    $ledger->recordPayout($event, 9605, 'TEST-PAYOUT-2', null, 100, false);

    expect(payableBalance($event))->toBe(0);
});

test('a payout whose transfer fee the platform absorbs still clears the payable', function () {
    [$event, $registration] = saleFixture('gw-fee-3');

    $ledger = app(LedgerService::class);
    $ledger->recordTicketSale($event, $registration, 10000, 200, 'TEST-SALE-3', 195);
    $ledger->recordPayout($event, 9605, 'TEST-PAYOUT-3', null, 100, true);

    expect(payableBalance($event))->toBe(0);

    $feeAccount = LedgerAccount::on('landlord')
        ->where('event_id', $event->id)
        ->where('code', LedgerAccount::CODE_GATEWAY_FEES)
        ->firstOrFail();

    expect((int) LedgerEntry::on('landlord')
        ->where('account_id', $feeAccount->id)
        ->where('direction', LedgerEntry::DIRECTION_DEBIT)
        ->sum('amount'))->toBe(100);
});

test('a sale booked without a known gateway fee behaves as it always did', function () {
    [$event, $registration] = saleFixture('gw-fee-4');

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'TEST-SALE-4');

    expect(payableBalance($event))->toBe(9800);
});

test('a gateway fee larger than the sale is rejected at the boundary', function () {
    [$event, $registration] = saleFixture('gw-fee-5');

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'TEST-SALE-5', 10000);
})->throws(InvalidArgumentException::class);
