<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\EventPayout;
use App\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function categoryMigration(): object
{
    return require database_path('migrations/landlord/2026_10_13_100000_add_category_to_ledger_and_payouts.php');
}

function landlordDb(): Connection
{
    return DB::connection('landlord');
}

function legacyAccount(string $tenantId, ?string $eventId, string $code, string $type): string
{
    $id = (string) Str::uuid();

    landlordDb()->table('ledger_accounts')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'code' => $code,
        'name' => "Account {$code}",
        'type' => $type,
        'currency' => 'GHS',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @param  array<int, array{0: string, 1: string, 2: int}>  $lines  [account id, direction, amount]
 */
function legacyTransaction(string $tenantId, ?string $eventId, string $type, string $reference, array $lines): void
{
    $id = (string) Str::uuid();

    landlordDb()->table('ledger_transactions')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'reference' => $reference,
        'description' => $reference,
        'transaction_type' => $type,
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($lines as [$accountId, $direction, $amount]) {
        landlordDb()->table('ledger_entries')->insert([
            'id' => (string) Str::uuid(),
            'transaction_id' => $id,
            'account_id' => $accountId,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => 'GHS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function entriesSum(string $category, ?string $eventId, string $code, string $direction): int
{
    return (int) landlordDb()->table('ledger_entries as e')
        ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
        ->where('a.category', $category)
        ->where('a.code', $code)
        ->when($eventId !== null, fn ($query) => $query->where('a.event_id', $eventId), fn ($query) => $query->whereNull('a.event_id'))
        ->where('e.direction', $direction)
        ->sum('e.amount');
}

function categoryOf(string $reference): string
{
    return (string) landlordDb()->table('ledger_transactions')->where('reference', $reference)->value('category');
}

test('it labels every existing row, splits shared accounts, and loses no money', function (): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    // Named explicitly: the guard that infers it from the event arrives in Task 4.
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'category' => LedgerCategoryEnum::Event]);

    $migration = categoryMigration();
    $migration->down();

    // Ticket money and a marketplace booking for the same event shared these accounts.
    $clearing = legacyAccount($tenant->id, $event->id, '1000', 'asset');
    $payable = legacyAccount($tenant->id, $event->id, '2000', 'liability');
    $revenue = legacyAccount($tenant->id, $event->id, '4000', 'revenue');
    legacyTransaction($tenant->id, $event->id, 'ticket_sale', 'T1', [[$clearing, 'debit', 1000], [$payable, 'credit', 900], [$revenue, 'credit', 100]]);
    legacyTransaction($tenant->id, $event->id, 'marketplace_booking', 'M1', [[$clearing, 'debit', 500], [$payable, 'credit', 450], [$revenue, 'credit', 50]]);

    // A seller's marketplace money with no event, and a commission credit payout
    // that used the very same event-less payable account.
    $sellerClearing = legacyAccount($tenant->id, null, '1000', 'asset');
    $sellerPayable = legacyAccount($tenant->id, null, '2000', 'liability');
    $sellerRevenue = legacyAccount($tenant->id, null, '4000', 'revenue');
    $bank = legacyAccount($tenant->id, null, '1010', 'asset');
    legacyTransaction($tenant->id, null, 'marketplace_booking', 'M2', [[$sellerClearing, 'debit', 300], [$sellerPayable, 'credit', 270], [$sellerRevenue, 'credit', 30]]);
    legacyTransaction($tenant->id, null, 'payout', 'C1', [[$sellerPayable, 'debit', 100], [$bank, 'credit', 100]]);

    $entriesBefore = landlordDb()->table('ledger_entries')->count();
    $amountBefore = (int) landlordDb()->table('ledger_entries')->sum('amount');

    $migration->up();

    expect(categoryOf('T1'))->toBe('event')
        ->and(categoryOf('M1'))->toBe('marketplace')
        ->and(categoryOf('M2'))->toBe('marketplace')
        ->and(categoryOf('C1'))->toBe('commission_credit');

    // The event's own accounts now hold only its ticket money.
    expect(entriesSum('event', $event->id, '1000', 'debit'))->toBe(1000)
        ->and(entriesSum('event', $event->id, '2000', 'credit'))->toBe(900)
        ->and(entriesSum('event', $event->id, '4000', 'credit'))->toBe(100);

    // The marketplace booking for that event moved to marketplace accounts of its own.
    expect(entriesSum('marketplace', $event->id, '1000', 'debit'))->toBe(500)
        ->and(entriesSum('marketplace', $event->id, '2000', 'credit'))->toBe(450)
        ->and(entriesSum('marketplace', $event->id, '4000', 'credit'))->toBe(50);

    // The event-less payable account split in two.
    expect(entriesSum('marketplace', null, '2000', 'credit'))->toBe(270)
        ->and(entriesSum('marketplace', null, '2000', 'debit'))->toBe(0)
        ->and(entriesSum('commission_credit', null, '2000', 'debit'))->toBe(100)
        ->and(entriesSum('commission_credit', null, '1010', 'credit'))->toBe(100);

    // No entry was lost or changed, and the empty originals were removed.
    expect(landlordDb()->table('ledger_entries')->count())->toBe($entriesBefore)
        ->and((int) landlordDb()->table('ledger_entries')->sum('amount'))->toBe($amountBefore)
        ->and(landlordDb()->table('ledger_accounts')->where('tenant_id', $tenant->id)->whereNull('event_id')->where('category', 'commission_credit')->count())->toBe(2);

    expect(landlordDb()->table('event_payouts')->where('id', $payout->id)->value('category'))->toBe('event');

    foreach (['event_payouts', 'ledger_accounts', 'ledger_transactions'] as $table) {
        expect(landlordDb()->table($table)->whereNull('category')->count())->toBe(0);
    }
});

test('it refuses to guess when a row fits no rule', function (): void {
    $tenant = Tenant::factory()->create();
    $migration = categoryMigration();
    $migration->down();

    $account = legacyAccount($tenant->id, null, '2000', 'liability');
    $other = legacyAccount($tenant->id, null, '1000', 'asset');
    // A ticket sale with no event cannot be classified.
    legacyTransaction($tenant->id, null, 'ticket_sale', 'ORPHAN', [[$other, 'debit', 100], [$account, 'credit', 100]]);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'ledger_transactions');
});

function insertTransactionRow(string $category, ?string $eventId, string $tenantId): void
{
    landlordDb()->transaction(function () use ($category, $eventId, $tenantId): void {
        landlordDb()->table('ledger_transactions')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'reference' => (string) Str::uuid(),
            'description' => 'pairing check',
            'transaction_type' => 'adjustment',
            'category' => $category,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

test('the database rejects a category that does not fit its event', function (string $category, bool $withEvent, bool $accepted): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $attempt = fn () => insertTransactionRow($category, $withEvent ? $event->id : null, $tenant->id);

    if ($accepted) {
        $attempt();
        expect(landlordDb()->table('ledger_transactions')->where('category', $category)->count())->toBe(1);

        return;
    }

    expect($attempt)->toThrow(QueryException::class);
})->with([
    'event with an event' => ['event', true, true],
    'event without an event' => ['event', false, false],
    'dues with an event' => ['dues', true, false],
    'dues without an event' => ['dues', false, true],
    'commission credit with an event' => ['commission_credit', true, false],
    'marketplace with an event' => ['marketplace', true, true],
    'marketplace without an event' => ['marketplace', false, true],
    'an unknown category' => ['mystery', false, false],
]);
