<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function idempotencyFixture(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared', 'package_id' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

    return [$tenant, $event, $registration];
}

test('the same payment reference posts once however often it arrives', function () {
    [$tenant, $event, $registration] = idempotencyFixture('idem-1');
    $ledger = app(LedgerService::class);

    $first = $ledger->recordTicketSale($event, $registration, 10000, 200, 'DUPLICATE-REF', 195);
    $second = $ledger->recordTicketSale($event, $registration, 10000, 200, 'DUPLICATE-REF', 195);

    // The redelivery gets the transaction that already exists, not a new one
    // and not an exception a webhook would have to handle.
    expect($second->id)->toBe($first->id)
        ->and(LedgerTransaction::where('event_id', $event->id)->count())->toBe(1)
        ->and(EventLedgerEntry::where('registration_id', $registration->id)->count())->toBe(1);
});

test('a duplicate payout reference does not debit the organizer twice', function () {
    [$tenant, $event] = idempotencyFixture('idem-2');
    $ledger = app(LedgerService::class);

    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 9605,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'PAYOUT-DUP-1',
    ]);

    $ledger->recordPayout($event, 9605, 'PAYOUT-DUP-1', $payout);
    $ledger->recordPayout($event, 9605, 'PAYOUT-DUP-1', $payout);

    expect(LedgerTransaction::where('event_id', $event->id)->where('reference', 'PAYOUT-DUP-1')->count())->toBe(1)
        ->and(EventLedgerEntry::where('payout_id', $payout->id)->count())->toBe(1);
});

test('different references on the same event still post separately', function () {
    [$tenant, $event, $registration] = idempotencyFixture('idem-3');
    $ledger = app(LedgerService::class);

    $ledger->recordTicketSale($event, $registration, 10000, 200, 'REF-A', 195);
    $ledger->recordTicketSale($event, $registration, 10000, 200, 'REF-B', 195);

    // Idempotency must key on the reference, not collapse an event's sales.
    expect(LedgerTransaction::where('event_id', $event->id)->count())->toBe(2);
});

test('a sale and a payout may share a reference without colliding', function () {
    [$tenant, $event, $registration] = idempotencyFixture('idem-4');
    $ledger = app(LedgerService::class);

    $ledger->recordTicketSale($event, $registration, 10000, 200, 'SHARED-REF', 195);
    $ledger->recordPayout($event, 9605, 'SHARED-REF');

    // The uniqueness is per transaction type, so a payout is not mistaken for
    // an already-posted sale.
    expect(LedgerTransaction::where('event_id', $event->id)->count())->toBe(2);
});
