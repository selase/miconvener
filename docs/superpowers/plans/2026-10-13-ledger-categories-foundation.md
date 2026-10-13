# Accounting Categories Foundation (Member Dues, Milestone 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every payout, ledger account and ledger transaction carries an accounting category (`event`, `dues`, `commission_credit`, `marketplace`), enforced by the database, so payouts that are not tied to an event can exist and be accounted for.

**Architecture:** A string-backed enum names the categories. One migration adds a `category` column to `event_payouts`, `ledger_accounts` and `ledger_transactions`, backfills existing rows (splitting accounts that marketplace postings share with other money), then adds `NOT NULL` and a check constraint tying category to event. `LedgerService` takes a category and defaults to `event` when an event is given. Event-scoped readers filter on `event`. Nothing visible to organisers changes; no dues screens exist yet.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL (landlord connection), Pest 5, Pint, Larastan.

**Spec:** `docs/superpowers/specs/2026-10-13-member-dues-design.md` (sections 4 and 12, milestone 1). Milestones 2 and 3 get their own plans after this lands.

## Global Constraints

- PostgreSQL only; all ledger and payout tables are on the `landlord` connection.
- Categories and their event rule: `event` requires an event; `dues` and `commission_credit` forbid one; `marketplace` allows either.
- Keep the names `event_payouts` and `EventPayout`. Do not rename tables, models or columns.
- The migration backfills in this order and fails loudly (a `RuntimeException`) if any row fits none of the rules: marketplace transactions, then event-less payout transactions (commission credit), then everything with an event.
- No balance is lost: the sum of all ledger entry amounts and the entry count are identical before and after the migration.
- Migration file: `database/migrations/landlord/2026_10_13_100000_add_category_to_ledger_and_payouts.php`, anonymous class, `Schema::connection('landlord')`.
- Enum cases are TitleCase. Code style is Pint; CI runs PHPStan, Pint, ESLint, JS tests and the Pest suite (`php artisan test --parallel --processes=4`; never more than 4 processes).
- Commit messages end with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Shell setup for every command below:
  `export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; PHP="$HOME/Library/Application Support/Herd/bin/php"` and run PHP tools as `"$PHP" ...`.

## Review Focus

1. **A marketplace posting tied to an event shares accounts with that event's ticket money.** After the migration the event's accounts must hold only ticket money, and the event payout must not be fed by marketplace money. Tested in Task 2 (split) and Task 5 (payout feed).
2. **One event-less account used by both marketplace and commission-credit entries.** It must become two accounts, each with only its own entries. Tested in Task 2.
3. **A row the migration cannot classify.** It must stop the migration with a clear message, not guess. Tested in Task 2 (Laravel runs the migration in a transaction, so nothing is half-applied).
4. **A caller that posts with no event and no category.** It must get a clear `InvalidArgumentException`, not a database error. Tested in Task 3.
5. **A wrong event/category pairing, through Eloquent or raw SQL.** It must be rejected. Tested in Task 2 (raw SQL) and Task 4 (Eloquent).

---

### Task 1: The category enum

**Files:**
- Create: `app/Enum/LedgerCategoryEnum.php`
- Test: `tests/Unit/LedgerCategoryEnumTest.php`

**Interfaces:**
- Produces: `App\Enum\LedgerCategoryEnum` (string-backed) with cases `Event`, `Dues`, `CommissionCredit`, `Marketplace`; methods `label(): string`, `eventRule(): string` (`'required'|'forbidden'|'optional'`), `allowsEvent(bool $hasEvent): bool`, `static values(): array<int, string>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;

test('the categories and their stored values', function (): void {
    expect(LedgerCategoryEnum::values())->toBe(['event', 'dues', 'commission_credit', 'marketplace']);
});

test('each category says whether it needs an event', function (): void {
    expect(LedgerCategoryEnum::Event->eventRule())->toBe('required')
        ->and(LedgerCategoryEnum::Dues->eventRule())->toBe('forbidden')
        ->and(LedgerCategoryEnum::CommissionCredit->eventRule())->toBe('forbidden')
        ->and(LedgerCategoryEnum::Marketplace->eventRule())->toBe('optional');
});

test('a category accepts an event only when its rule allows', function (): void {
    expect(LedgerCategoryEnum::Event->allowsEvent(true))->toBeTrue()
        ->and(LedgerCategoryEnum::Event->allowsEvent(false))->toBeFalse()
        ->and(LedgerCategoryEnum::Dues->allowsEvent(true))->toBeFalse()
        ->and(LedgerCategoryEnum::Dues->allowsEvent(false))->toBeTrue()
        ->and(LedgerCategoryEnum::CommissionCredit->allowsEvent(true))->toBeFalse()
        ->and(LedgerCategoryEnum::Marketplace->allowsEvent(true))->toBeTrue()
        ->and(LedgerCategoryEnum::Marketplace->allowsEvent(false))->toBeTrue();
});

test('each category has a human label', function (): void {
    expect(LedgerCategoryEnum::Event->label())->toBe('Event sales')
        ->and(LedgerCategoryEnum::Dues->label())->toBe('Member dues')
        ->and(LedgerCategoryEnum::CommissionCredit->label())->toBe('Commission credit')
        ->and(LedgerCategoryEnum::Marketplace->label())->toBe('Marketplace');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `"$PHP" artisan test --compact tests/Unit/LedgerCategoryEnumTest.php`
Expected: FAIL (`Class "App\Enum\LedgerCategoryEnum" not found`).

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a payout, a ledger account or a ledger transaction is for. Every payout
 * belongs to exactly one category so the books can show each stream of money
 * apart. A new money stream must be added here, with a decision about whether
 * it needs an event, before it can pay out.
 */
enum LedgerCategoryEnum: string
{
    /** Ticket sales and contributions of one event. */
    case Event = 'event';

    /** Membership dues. */
    case Dues = 'dues';

    /** Commission the platform owes an organisation back. */
    case CommissionCredit = 'commission_credit';

    /** A business's balance from Event Store bookings and quotes. */
    case Marketplace = 'marketplace';

    public function label(): string
    {
        return match ($this) {
            self::Event => 'Event sales',
            self::Dues => 'Member dues',
            self::CommissionCredit => 'Commission credit',
            self::Marketplace => 'Marketplace',
        };
    }

    /**
     * @return 'required'|'forbidden'|'optional'
     */
    public function eventRule(): string
    {
        return match ($this) {
            self::Event => 'required',
            self::Dues, self::CommissionCredit => 'forbidden',
            self::Marketplace => 'optional',
        };
    }

    public function allowsEvent(bool $hasEvent): bool
    {
        return match ($this->eventRule()) {
            'required' => $hasEvent,
            'forbidden' => ! $hasEvent,
            default => true,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
```

- [ ] **Step 4: Run the test to see it pass**

Run: `"$PHP" artisan test --compact tests/Unit/LedgerCategoryEnumTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Enum/LedgerCategoryEnum.php tests/Unit/LedgerCategoryEnumTest.php
git commit -m "feat(finance): add the ledger category enum

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Schema, backfill and model casts

**Files:**
- Create: `app/Services/Finance/LedgerCategoryBackfill.php`
- Create: `database/migrations/landlord/2026_10_13_100000_add_category_to_ledger_and_payouts.php`
- Modify: `app/Models/LedgerAccount.php` (fillable, casts)
- Modify: `app/Models/LedgerTransaction.php` (fillable, casts)
- Modify: `app/Models/EventPayout.php` (fillable, casts)
- Test: `tests/Feature/Finance/LedgerCategoryMigrationTest.php`

**Interfaces:**
- Consumes: `LedgerCategoryEnum` (Task 1).
- Produces: `category` column (string 32, NOT NULL, indexed) on `event_payouts`, `ledger_accounts`, `ledger_transactions`; check constraint `{table}_category_check`; `event_payouts.event_id` nullable; `LedgerCategoryBackfill::onLandlord(): self` and `run(): void`; the three models cast `category` to `LedgerCategoryEnum` and list it in `$fillable`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/LedgerCategoryMigrationTest.php`:

```php
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
```

- [ ] **Step 2: Run it to see it fail**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/LedgerCategoryMigrationTest.php`
Expected: FAIL (`Failed opening required ... 2026_10_13_100000_add_category_to_ledger_and_payouts.php`).

- [ ] **Step 3: Write the backfill**

Create `app/Services/Finance/LedgerCategoryBackfill.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gives every payout, ledger account and ledger transaction that already
 * exists an accounting category. Run once, by the migration that adds the
 * column, while the column is still nullable.
 *
 * Marketplace postings used to share accounts with other money (an event's
 * ticket sales, or a commission credit payout), so those accounts are split:
 * the marketplace entries move to accounts of their own.
 */
final class LedgerCategoryBackfill
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public static function onLandlord(): self
    {
        return new self(DB::connection('landlord'));
    }

    public function run(): void
    {
        $this->labelTransactions();
        $this->splitMarketplaceAccounts();
        $this->labelAccounts();
        $this->labelPayouts();
        $this->assertNothingLeftUnlabelled();
        $this->assertEntriesMatchTheirTransactions();
    }

    private function labelTransactions(): void
    {
        $this->db->update("UPDATE ledger_transactions SET category = 'marketplace' WHERE category IS NULL AND transaction_type = 'marketplace_booking'");
        $this->db->update("UPDATE ledger_transactions SET category = 'commission_credit' WHERE category IS NULL AND event_id IS NULL AND transaction_type = 'payout'");
        $this->db->update("UPDATE ledger_transactions SET category = 'event' WHERE category IS NULL AND event_id IS NOT NULL");
    }

    private function splitMarketplaceAccounts(): void
    {
        $this->db->statement(<<<'SQL'
            INSERT INTO ledger_accounts (id, tenant_id, event_id, code, name, type, currency, is_active, category, created_at, updated_at)
            SELECT gen_random_uuid(), a.tenant_id, a.event_id, a.code, a.name, a.type, a.currency, a.is_active, 'marketplace', now(), now()
            FROM ledger_accounts a
            WHERE a.category IS NULL
              AND a.id IN (
                  SELECT e.account_id
                  FROM ledger_entries e
                  JOIN ledger_transactions t ON t.id = e.transaction_id
                  WHERE t.category = 'marketplace'
              )
              AND NOT EXISTS (
                  SELECT 1 FROM ledger_accounts m
                  WHERE m.category = 'marketplace'
                    AND m.tenant_id = a.tenant_id
                    AND m.event_id IS NOT DISTINCT FROM a.event_id
                    AND m.code = a.code
                    AND m.currency = a.currency
              )
            SQL);

        $this->db->statement(<<<'SQL'
            UPDATE ledger_entries e
            SET account_id = m.id
            FROM ledger_transactions t, ledger_accounts a, ledger_accounts m
            WHERE t.id = e.transaction_id
              AND t.category = 'marketplace'
              AND a.id = e.account_id
              AND a.category IS NULL
              AND m.category = 'marketplace'
              AND m.tenant_id = a.tenant_id
              AND m.event_id IS NOT DISTINCT FROM a.event_id
              AND m.code = a.code
              AND m.currency = a.currency
            SQL);
    }

    private function labelAccounts(): void
    {
        // What is left of an event-less account after the split and has no
        // entries is a leftover of the split.
        $this->db->statement('DELETE FROM ledger_accounts a WHERE a.category IS NULL AND a.event_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_entries e WHERE e.account_id = a.id)');
        $this->db->update("UPDATE ledger_accounts SET category = 'commission_credit' WHERE category IS NULL AND event_id IS NULL");
        $this->db->update("UPDATE ledger_accounts SET category = 'event' WHERE category IS NULL AND event_id IS NOT NULL");
    }

    private function labelPayouts(): void
    {
        $this->db->update("UPDATE event_payouts SET category = 'event' WHERE category IS NULL AND event_id IS NOT NULL");
    }

    private function assertNothingLeftUnlabelled(): void
    {
        $unlabelled = [];

        foreach (['ledger_transactions', 'ledger_accounts', 'event_payouts'] as $table) {
            $count = (int) $this->db->table($table)->whereNull('category')->count();

            if ($count > 0) {
                $unlabelled[] = "{$count} row(s) in {$table}";
            }
        }

        if ($unlabelled !== []) {
            throw new RuntimeException('Could not give a ledger category to '.implode(' and ', $unlabelled).'. Classify them by hand before migrating.');
        }
    }

    private function assertEntriesMatchTheirTransactions(): void
    {
        $mismatched = (int) $this->db->table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->join('ledger_transactions as t', 't.id', '=', 'e.transaction_id')
            ->whereColumn('a.category', '<>', 't.category')
            ->count();

        if ($mismatched > 0) {
            throw new RuntimeException("{$mismatched} ledger entr(ies) sit in an account of a different category from their transaction. Resolve them by hand before migrating.");
        }
    }
}
```

- [ ] **Step 4: Write the migration**

Create `database/migrations/landlord/2026_10_13_100000_add_category_to_ledger_and_payouts.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Finance\LedgerCategoryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = ['event_payouts', 'ledger_accounts', 'ledger_transactions'];

    /**
     * Every payout, ledger account and ledger transaction gets an accounting
     * category, so money that is not tied to an event (member dues) can be
     * paid out and still be accounted for. The check constraint ties the
     * category to the event: `event` needs one, `dues` and `commission_credit`
     * have none, `marketplace` may or may not.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::connection('landlord')->table($table, function (Blueprint $blueprint): void {
                $blueprint->string('category', 32)->nullable();
            });
        }

        LedgerCategoryBackfill::onLandlord()->run();

        $db = DB::connection('landlord');

        foreach (self::TABLES as $table) {
            $db->statement("ALTER TABLE {$table} ALTER COLUMN category SET NOT NULL");
            $db->statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_category_check CHECK (
                category IN ('event', 'dues', 'commission_credit', 'marketplace')
                AND (category <> 'event' OR event_id IS NOT NULL)
                AND (category NOT IN ('dues', 'commission_credit') OR event_id IS NULL)
            )");

            Schema::connection('landlord')->table($table, function (Blueprint $blueprint): void {
                $blueprint->index('category');
            });
        }

        $db->statement('ALTER TABLE event_payouts ALTER COLUMN event_id DROP NOT NULL');
    }

    /**
     * Schema only. Accounts split out for marketplace postings stay as they are,
     * and this refuses to run while any payout has no event.
     */
    public function down(): void
    {
        $db = DB::connection('landlord');

        $db->statement('ALTER TABLE event_payouts ALTER COLUMN event_id SET NOT NULL');

        foreach (self::TABLES as $table) {
            Schema::connection('landlord')->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['category']);
            });

            $db->statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_category_check");

            Schema::connection('landlord')->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('category');
            });
        }
    }
};
```

- [ ] **Step 5: Add the casts**

In `app/Models/LedgerAccount.php`, add `use App\Enum\LedgerCategoryEnum;`, add `'category'` to `$fillable` (after `'event_id'`), and change `$casts` to:

```php
    protected $casts = [
        'is_active' => 'boolean',
        'category' => LedgerCategoryEnum::class,
    ];
```

In `app/Models/LedgerTransaction.php`, add `use App\Enum\LedgerCategoryEnum;`, add `'category'` to `$fillable` (after `'event_id'`), and change `$casts` to:

```php
    protected $casts = [
        'posted_at' => 'datetime',
        'category' => LedgerCategoryEnum::class,
    ];
```

In `app/Models/EventPayout.php`, add `use App\Enum\LedgerCategoryEnum;`, add `'category'` to `$fillable` (after `'event_id'`), and add to `$casts`: `'category' => LedgerCategoryEnum::class,`.

- [ ] **Step 6: Run the test to see it pass**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/LedgerCategoryMigrationTest.php`
Expected: PASS (10 tests).

Do not run the rest of the suite yet. From this task until Task 4 is done, any test or code that creates a ledger account, ledger transaction or payout without a category fails with `null value in column "category"`, because the column is now required. That is expected and is what Tasks 3 and 4 fix. Run only the migration test here, and run the whole suite for the first time at the end of Task 4.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Finance/LedgerCategoryBackfill.php database/migrations/landlord/2026_10_13_100000_add_category_to_ledger_and_payouts.php app/Models/LedgerAccount.php app/Models/LedgerTransaction.php app/Models/EventPayout.php tests/Feature/Finance/LedgerCategoryMigrationTest.php
git commit -m "feat(finance): add accounting categories to payouts and the ledger

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: LedgerService takes a category

**Files:**
- Modify: `app/Services/Finance/LedgerService.php` (`ensureAccountsExist`, `postTransaction`, new `resolveCategory`)
- Modify: `app/Services/Finance/CommissionCredit.php` (`settle`)
- Modify: `app/Services/Marketplace/MarketplaceLedger.php` (`post`)
- Modify: `tests/Feature/Admin/PlatformEarningsTest.php` (`earnCommission` helper)
- Test: `tests/Feature/Finance/LedgerCategoryServiceTest.php`

**Interfaces:**
- Consumes: `LedgerCategoryEnum`, the `category` columns and casts (Tasks 1 and 2).
- Produces: `LedgerService::ensureAccountsExist(Tenant $tenant, ?Event $event = null, string $currency = 'GHS', ?LedgerCategoryEnum $category = null): array`; `LedgerService::postTransaction(Tenant $tenant, ?Event $event, string $transactionType, string $description, string $reference, array $entries, ?string $userId = null, string $currency = 'GHS', ?LedgerCategoryEnum $category = null): LedgerTransaction`. With an event and no category the category is `Event`. Without an event a category is required. A category that does not fit the event rule throws `InvalidArgumentException`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/LedgerCategoryServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Services\Finance\CommissionCredit;
use App\Services\Finance\LedgerService;

/**
 * @return array<int, array{code: string, direction: string, amount: int}>
 */
function balancedEntries(int $gross = 1000): array
{
    return [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $gross],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $gross],
    ];
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'GHS']);
    $this->ledger = app(LedgerService::class);
});

test('a transaction with an event is an event transaction on event accounts', function (): void {
    $transaction = $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'REF-1', balancedEntries());

    expect($transaction->category)->toBe(LedgerCategoryEnum::Event);

    $categories = LedgerAccount::query()->withoutGlobalScopes()->where('event_id', $this->event->id)->pluck('category')->unique()->all();
    expect($categories)->toHaveCount(1)->and($categories[0])->toBe(LedgerCategoryEnum::Event);
});

test('a transaction with no event and no category is refused with a clear message', function (): void {
    expect(fn () => $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'x', 'REF-2', balancedEntries()))
        ->toThrow(InvalidArgumentException::class, 'A ledger category is required when there is no event.');
});

test('a category that does not fit the event is refused', function (): void {
    expect(fn () => $this->ledger->postTransaction($this->tenant, $this->event, 'adjustment', 'x', 'REF-3', balancedEntries(), null, 'GHS', LedgerCategoryEnum::Dues))
        ->toThrow(InvalidArgumentException::class, 'The dues category cannot be posted with an event.');

    expect(fn () => $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'x', 'REF-4', balancedEntries(), null, 'GHS', LedgerCategoryEnum::Event))
        ->toThrow(InvalidArgumentException::class, 'The event category cannot be posted without an event.');
});

test('dues money posts to event-less dues accounts', function (): void {
    $transaction = $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'Dues', 'REF-5', balancedEntries(), null, 'GHS', LedgerCategoryEnum::Dues);

    expect($transaction->category)->toBe(LedgerCategoryEnum::Dues)
        ->and($transaction->event_id)->toBeNull();

    expect(LedgerAccount::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->whereNull('event_id')->where('category', LedgerCategoryEnum::Dues->value)->count())->toBe(6);
});

test('marketplace money tied to an event stays out of that event\'s accounts', function (): void {
    $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'T-1', balancedEntries(1000));
    $this->ledger->postTransaction($this->tenant, $this->event, 'marketplace_booking', 'Venue', 'M-1', balancedEntries(500), null, 'GHS', LedgerCategoryEnum::Marketplace);

    $trial = collect($this->ledger->getTrialBalance($this->event)['accounts'])->keyBy('code');

    expect((int) $trial[LedgerAccount::CODE_ORGANIZER_PAYABLE]['credits'])->toBe(1000)
        ->and((int) $trial[LedgerAccount::CODE_GATEWAY_CLEARING]['debits'])->toBe(1000);
});

test('commission credit payouts post under their own category', function (): void {
    app(CommissionCredit::class)->grant($this->tenant, 'GHS', 500);

    app(CommissionCredit::class)->settle($this->tenant, 'GHS', 200);

    $transaction = LedgerTransaction::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('transaction_type', LedgerTransaction::TYPE_PAYOUT)->firstOrFail();
    expect($transaction->category)->toBe(LedgerCategoryEnum::CommissionCredit);
});

test('an existing event trial balance is unchanged by the category', function (): void {
    $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'T-2', balancedEntries(1000));

    $trial = $this->ledger->getTrialBalance($this->event);

    expect($trial['is_balanced'])->toBeTrue();
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/LedgerCategoryServiceTest.php`
Expected: FAIL (the new parameter does not exist; `null value in column "category"`).

- [ ] **Step 3: Update `LedgerService`**

At the top of `app/Services/Finance/LedgerService.php`, add `use App\Enum\LedgerCategoryEnum;`.

Replace the `ensureAccountsExist` signature and its account lookup. The method currently begins `public function ensureAccountsExist(Tenant $tenant, ?Event $event = null, string $currency = 'GHS'): array` and builds `$accounts` in a `foreach`. Change the signature to:

```php
    public function ensureAccountsExist(Tenant $tenant, ?Event $event = null, string $currency = 'GHS', ?LedgerCategoryEnum $category = null): array
```

Add this line as the first statement of the method body, before `$definitions`:

```php
        $category = $this->resolveCategory($event, $category);
```

In the `firstOrCreate` array of lookup keys, add the category so it reads:

```php
            $account = LedgerAccount::on('landlord')->firstOrCreate([
                'tenant_id' => $tenant->id,
                'event_id' => $eventId,
                'category' => $category->value,
                'code' => $code,
                'currency' => $currency,
            ], [
                'name' => $def['name'],
                'type' => $def['type'],
                'is_active' => true,
            ]);
```

Add this private method directly after `ensureAccountsExist`:

```php
    /**
     * The category a posting belongs to: an explicit one, or `event` when an
     * event is given. Money with no event must say what it is.
     */
    private function resolveCategory(?Event $event, ?LedgerCategoryEnum $category): LedgerCategoryEnum
    {
        $category ??= $event !== null ? LedgerCategoryEnum::Event : null;

        if ($category === null) {
            throw new InvalidArgumentException('A ledger category is required when there is no event.');
        }

        if (! $category->allowsEvent($event !== null)) {
            throw new InvalidArgumentException(sprintf('The %s category cannot be posted %s an event.', $category->value, $event !== null ? 'with' : 'without'));
        }

        return $category;
    }
```

Change the `postTransaction` signature to add the final parameter:

```php
    public function postTransaction(
        Tenant $tenant,
        ?Event $event,
        string $transactionType,
        string $description,
        string $reference,
        array $entries,
        ?string $userId = null,
        string $currency = 'GHS',
        ?LedgerCategoryEnum $category = null
    ): LedgerTransaction {
```

At the start of its body (before the `if (empty($entries))` check) add:

```php
        $category = $this->resolveCategory($event, $category);
```

Replace the call `$accounts = $this->ensureAccountsExist($tenant, $event, $currency);` inside `postTransaction` with:

```php
        $accounts = $this->ensureAccountsExist($tenant, $event, $currency, $category);
```

The closure that creates the transaction captures variables with `use (...)`; add `$category` to that `use` list, and add `'category' => $category,` to the `LedgerTransaction::create([...])` array (after `'event_id' => $event?->id,`).

- [ ] **Step 4: Update the two callers**

In `app/Services/Finance/CommissionCredit.php`, add `use App\Enum\LedgerCategoryEnum;` and add the category as the last argument of the `postTransaction` call in `settle()`:

```php
            null,
            mb_strtoupper($currency),
            LedgerCategoryEnum::CommissionCredit,
        );
```

In `app/Services/Marketplace/MarketplaceLedger.php`, add `use App\Enum\LedgerCategoryEnum;` and add a named argument to its `postTransaction` call (after `entries: $entries,`):

```php
                entries: $entries,
                category: LedgerCategoryEnum::Marketplace,
```

- [ ] **Step 5: Fix the one existing test helper that posts without an event**

In `tests/Feature/Admin/PlatformEarningsTest.php`, add `use App\Enum\LedgerCategoryEnum;` and change the helper's call so it names a category (the helper stands in for earnings, with no event):

```php
    app(LedgerService::class)->postTransaction($tenant, null, $type, 'test', $reference, [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => $other, 'amount' => $commission],
        ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => $direction, 'amount' => $commission],
    ], null, 'GHS', LedgerCategoryEnum::Marketplace);
```

- [ ] **Step 6: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/LedgerCategoryServiceTest.php`
Expected: PASS (7 tests).

Then find any other caller that posts with no event and fix it the same way:
`grep -rn "postTransaction(" app tests | grep -v "LedgerService.php"`
Expected: only the callers handled above plus `tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php` (which passes an event, so it needs no change).

Run the broader ledger and finance tests:
`"$PHP" artisan test --compact tests/Feature/Finance tests/Feature/Payments tests/Feature/Admin/PlatformEarningsTest.php tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php`
Expected: PASS, except any test that creates an `EventPayout` (fixed in Task 4).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Finance/LedgerService.php app/Services/Finance/CommissionCredit.php app/Services/Marketplace/MarketplaceLedger.php tests/Feature/Admin/PlatformEarningsTest.php tests/Feature/Finance/LedgerCategoryServiceTest.php
git commit -m "feat(finance): post ledger entries under an accounting category

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Payouts must carry a category

**Files:**
- Modify: `app/Models/EventPayout.php` (a `booted` creating guard)
- Modify: `app/Http/Controllers/Tenant/EventFinanceController.php` (the payout create at about line 115)
- Modify: `app/Console/Commands/ReconcilePayoutSchedulesCommand.php` (the payout create at about line 155)
- Modify: `tests/Feature/Events/EventFinanceTest.php` and `tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php` (one assertion each)
- Test: `tests/Feature/Finance/PayoutCategoryTest.php`

**Interfaces:**
- Consumes: `LedgerCategoryEnum`, the casts (Tasks 1 and 2).
- Produces: `EventPayout` rows always have a category. With an event and no category it becomes `Event`. With no event and no category, creating throws `LogicException('A payout without an event needs an accounting category.')`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/PayoutCategoryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\EventPayout;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->account = TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function payoutAttributes(Tenant $tenant, TenantPayoutAccount $account, array $overrides = []): array
{
    return $overrides + [
        'tenant_id' => $tenant->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_SCHEDULED,
    ];
}

test('a payout for an event is an event payout without anyone saying so', function (): void {
    $payout = EventPayout::create(payoutAttributes($this->tenant, $this->account, ['event_id' => $this->event->id]));

    expect($payout->category)->toBe(LedgerCategoryEnum::Event);
});

test('a payout through an event relation is an event payout', function (): void {
    $payout = $this->event->payouts()->create(payoutAttributes($this->tenant, $this->account));

    expect($payout->category)->toBe(LedgerCategoryEnum::Event);
});

test('a payout with no event and no category is refused with a clear message', function (): void {
    expect(fn () => EventPayout::create(payoutAttributes($this->tenant, $this->account)))
        ->toThrow(LogicException::class, 'A payout without an event needs an accounting category.');
});

test('an event-less payout is allowed when it names its category', function (): void {
    $payout = EventPayout::create(payoutAttributes($this->tenant, $this->account, ['category' => LedgerCategoryEnum::Dues]));

    expect($payout->event_id)->toBeNull()
        ->and($payout->fresh()->category)->toBe(LedgerCategoryEnum::Dues)
        ->and($payout->event)->toBeNull();
});

test('the database rejects a dues payout that names an event', function (): void {
    $attempt = fn () => DB::connection('landlord')->transaction(
        fn () => EventPayout::create(payoutAttributes($this->tenant, $this->account, ['event_id' => $this->event->id, 'category' => LedgerCategoryEnum::Dues]))
    );

    expect($attempt)->toThrow(QueryException::class);
});

test('the database rejects an event payout that names no event', function (): void {
    $attempt = fn () => DB::connection('landlord')->transaction(
        fn () => EventPayout::create(payoutAttributes($this->tenant, $this->account, ['category' => LedgerCategoryEnum::Event]))
    );

    expect($attempt)->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/PayoutCategoryTest.php`
Expected: FAIL (`null value in column "category"`; the LogicException is not thrown).

- [ ] **Step 3: Add the guard to `EventPayout`**

In `app/Models/EventPayout.php`, add `use LogicException;`, and add this method just below the `$casts` property:

```php
    /**
     * Every payout belongs to an accounting category. One for an event is an
     * event payout without anyone having to say so; any other kind of payout
     * must name its category, because there is nothing to infer it from.
     */
    protected static function booted(): void
    {
        static::creating(function (EventPayout $payout): void {
            if ($payout->category !== null) {
                return;
            }

            if ($payout->event_id === null) {
                throw new LogicException('A payout without an event needs an accounting category.');
            }

            $payout->category = LedgerCategoryEnum::Event;
        });
    }
```

- [ ] **Step 4: Make the two existing creation sites explicit**

In `app/Http/Controllers/Tenant/EventFinanceController.php`, add `use App\Enum\LedgerCategoryEnum;` and add the category to the `$eventModel->payouts()->create([...])` array:

```php
            $payout = $eventModel->payouts()->create([
                'tenant_id' => $tenant->id,
                'category' => LedgerCategoryEnum::Event,
                'payout_account_id' => $validated['payout_account_id'],
                'amount' => $validated['amount'],
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);
```

In `app/Console/Commands/ReconcilePayoutSchedulesCommand.php`, add `use App\Enum\LedgerCategoryEnum;` and add `'category' => LedgerCategoryEnum::Event,` to the `EventPayout::create([...])` array, after `'event_id' => $event->id,`.

- [ ] **Step 5: Pin the category in the two existing endpoint tests**

In `tests/Feature/Events/EventFinanceTest.php`, in the test `host can record a payout and mark it paid`, add `use App\Enum\LedgerCategoryEnum;` at the top and, directly after the line `expect($payout->status)->toBe(EventPayout::STATUS_SCHEDULED);`, add:

```php
    expect($payout->category)->toBe(LedgerCategoryEnum::Event);
```

In `tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php`, in the test `reconcile payout schedules command processes matured payouts and holdback escrow`, add `use App\Enum\LedgerCategoryEnum;` at the top and, directly after `expect($payout->payout_account_id)->toBe($account->id);`, add:

```php
    expect($payout->category)->toBe(LedgerCategoryEnum::Event);
```

- [ ] **Step 6: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/PayoutCategoryTest.php tests/Feature/Events/EventFinanceTest.php tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php tests/Feature/Admin/PayoutOversightTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Models/EventPayout.php app/Http/Controllers/Tenant/EventFinanceController.php app/Console/Commands/ReconcilePayoutSchedulesCommand.php tests/Feature/Finance/PayoutCategoryTest.php tests/Feature/Events/EventFinanceTest.php tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php
git commit -m "feat(finance): every payout carries an accounting category

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Event readers look only at event money

**Files:**
- Modify: `app/Console/Commands/ReconcilePayoutSchedulesCommand.php` (three `LedgerAccount` lookups)
- Modify: `app/Models/Event.php` (`ledgerTransactions`)
- Test: `tests/Feature/Finance/LedgerCategoryReadersTest.php`

**Interfaces:**
- Consumes: the `category` column and `LedgerCategoryEnum` (Tasks 1 and 2).
- Produces: payout reconciliation and `Event::ledgerTransactions()` consider only `event` category rows.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/LedgerCategoryReadersTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\EventPayoutSchedule;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->event = Event::factory()->create([
        'tenant_id' => $this->tenant->id,
        'currency' => 'GHS',
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(8),
    ]);
    $this->ledger = app(LedgerService::class);
});

function marketplaceBookingFor(Event $event, Tenant $tenant, int $gross): void
{
    app(LedgerService::class)->postTransaction($tenant, $event, 'marketplace_booking', 'Venue booking', 'MKT-'.$gross, [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $gross],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $gross - 500],
        ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 500],
    ], null, 'GHS', LedgerCategoryEnum::Marketplace);
}

test('marketplace money booked against an event never feeds that event payout', function (): void {
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => true]);
    EventPayoutSchedule::create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'schedule_type' => 'post_event',
        'days_after_event' => 3,
        'holdback_percentage' => 10,
        'holdback_release_days' => 14,
        'minimum_payout_amount' => 1000,
        'auto_payout_enabled' => true,
        'preferred_account_id' => $account->id,
    ]);
    $registration = EventRegistration::factory()->create(['event_id' => $this->event->id, 'tenant_id' => $this->tenant->id]);

    // The marketplace booking goes first, so its accounts are created first and
    // an unfiltered lookup would find them instead of the event's.
    marketplaceBookingFor($this->event, $this->tenant, 5_000);
    $this->ledger->recordTicketSale($this->event, $registration, 10_000, 500, 'PAY-1');

    Artisan::call('app:reconcile-payout-schedules');

    // 10,000 gross, 500 commission, 10% of gross held back: the same 8,500 as
    // without the marketplace booking.
    expect($this->event->payouts()->firstOrFail()->amount)->toBe(8_500);
});

test('an event lists only its own ledger transactions', function (): void {
    $registration = EventRegistration::factory()->create(['event_id' => $this->event->id, 'tenant_id' => $this->tenant->id]);
    $this->ledger->recordTicketSale($this->event, $registration, 10_000, 500, 'PAY-2');
    marketplaceBookingFor($this->event, $this->tenant, 5_000);

    $references = $this->event->ledgerTransactions()->pluck('reference')->all();

    expect($references)->toContain('PAY-2')
        ->and($references)->not->toContain('MKT-5000');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/LedgerCategoryReadersTest.php`
Expected: FAIL (the payout is larger than 8,500 because the lookups find both accounts, or `first()` returns the marketplace one; and the event lists the marketplace transaction).

- [ ] **Step 3: Filter the three lookups**

In `app/Console/Commands/ReconcilePayoutSchedulesCommand.php`, add `use App\Enum\LedgerCategoryEnum;` (it was added in Task 4; do not duplicate it). In the three queries that start `LedgerAccount::on('landlord')->where('event_id', $event->id)` (the holdback account, the clearing account and the payable account), add this line directly after `->where('event_id', $event->id)` in each:

```php
            ->where('category', LedgerCategoryEnum::Event->value)
```

- [ ] **Step 4: Filter `Event::ledgerTransactions`**

In `app/Models/Event.php`, add `use App\Enum\LedgerCategoryEnum;` and change the relation to:

```php
    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class)->where('category', LedgerCategoryEnum::Event->value);
    }
```

- [ ] **Step 5: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/LedgerCategoryReadersTest.php`
Expected: PASS (2 tests).

Find any other reader of `ledgerTransactions()` and confirm it wants event money only:
`grep -rn "ledgerTransactions" app tests`
Expected: only the relation and, possibly, tests; if a reader needs all transactions, query `LedgerTransaction` directly there instead.

Run the broader tests that read ledgers:
`"$PHP" artisan test --compact tests/Feature/Events tests/Feature/Finance tests/Feature/Admin`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/ReconcilePayoutSchedulesCommand.php app/Models/Event.php tests/Feature/Finance/LedgerCategoryReadersTest.php
git commit -m "fix(finance): event payouts and ledgers read only event money

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The payout oversight page shows the category

**Files:**
- Modify: `resources/views/admin/billing/payouts/index.blade.php` (lines 60 and 100)
- Test: `tests/Feature/Admin/PayoutOversightTest.php` (one new test)

**Interfaces:**
- Consumes: `EventPayout::$category` cast (Task 2) and `LedgerCategoryEnum::label()` (Task 1).
- Produces: each payout row on `admin.billing.payouts.index` names its category.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Admin/PayoutOversightTest.php` (the file already imports `EventPayout`, `TenantPayoutAccount` and defines `oversightPayout`; add `use App\Enum\LedgerCategoryEnum;` at the top):

```php
test('each payout shows its accounting category, including payouts with no event', function (): void {
    [$tenant] = oversightPayout(EventPayout::STATUS_AWAITING_OTP, ['provider_transfer_code' => 'TRF_9']);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    EventPayout::create([
        'tenant_id' => $tenant->id,
        'category' => LedgerCategoryEnum::Dues,
        'payout_account_id' => $account->id,
        'amount' => 12_000,
        'status' => EventPayout::STATUS_AWAITING_OTP,
        'provider_transfer_code' => 'TRF_10',
    ]);

    $this->actingAs($this->superadmin)
        ->get(route('admin.billing.payouts.index'))
        ->assertOk()
        ->assertSee('Event sales')
        ->assertSee('Health Summit')
        ->assertSee('Member dues');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `"$PHP" artisan test --compact tests/Feature/Admin/PayoutOversightTest.php --filter="accounting category"`
Expected: FAIL (the page does not show category labels).

- [ ] **Step 3: Edit the view**

In `resources/views/admin/billing/payouts/index.blade.php`, line 60 currently reads:

```blade
                                <td>{{ $payout->event->name ?? '—' }}</td>
```

Change it to:

```blade
                                <td>{{ $payout->event->name ?? '—' }}<div class="text-muted fs-8">{{ $payout->category->label() }}</div></td>
```

Line 100 currently reads:

```blade
                                        <td>{{ $payout->tenant->name ?? '—' }}<div class="text-muted fs-8">{{ $payout->event->name ?? '' }}</div></td>
```

Change it to:

```blade
                                        <td>{{ $payout->tenant->name ?? '—' }}<div class="text-muted fs-8">{{ $payout->category->label() }}{{ $payout->event ? ' · '.$payout->event->name : '' }}</div></td>
```

- [ ] **Step 4: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Admin/PayoutOversightTest.php`
Expected: PASS (existing tests plus the new one).

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/billing/payouts/index.blade.php tests/Feature/Admin/PayoutOversightTest.php
git commit -m "feat(finance): show each payout's accounting category to the superadmin

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: A command to verify the categories after deploy

**Files:**
- Create: `app/Console/Commands/Finance/VerifyLedgerCategories.php`
- Test: `tests/Feature/Finance/VerifyLedgerCategoriesTest.php`

**Interfaces:**
- Consumes: the `category` columns (Task 2) and `LedgerService` (Task 3).
- Produces: `php artisan finance:verify-categories` prints row counts per category for the three tables and exits 0, or exits 1 naming how many ledger entries sit in an account of a different category from their transaction.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/VerifyLedgerCategoriesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'GHS']);
    $this->ledger = app(LedgerService::class);
});

function postedEntries(): array
{
    return [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 1000],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 1000],
    ];
}

test('a consistent ledger passes and reports its counts', function (): void {
    $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'V-1', postedEntries());
    $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'Dues', 'V-2', postedEntries(), null, 'GHS', LedgerCategoryEnum::Dues);

    $exitCode = Artisan::call('finance:verify-categories');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('event')->toContain('dues')->toContain('ledger_transactions');
});

test('an entry in an account of another category fails with how many', function (): void {
    $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'V-3', postedEntries());
    $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'Dues', 'V-4', postedEntries(), null, 'GHS', LedgerCategoryEnum::Dues);

    // Move one dues entry into an event account, as a bad backfill might have.
    $eventAccount = DB::connection('landlord')->table('ledger_accounts')->where('event_id', $this->event->id)->where('code', LedgerAccount::CODE_GATEWAY_CLEARING)->value('id');
    $duesTransaction = DB::connection('landlord')->table('ledger_transactions')->where('reference', 'V-4')->value('id');
    DB::connection('landlord')->table('ledger_entries')->where('transaction_id', $duesTransaction)->where('direction', LedgerEntry::DIRECTION_DEBIT)->update(['account_id' => $eventAccount]);

    $exitCode = Artisan::call('finance:verify-categories');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('1 ledger entr');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/VerifyLedgerCategoriesTest.php`
Expected: FAIL (`The command "finance:verify-categories" does not exist`).

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/Finance/VerifyLedgerCategories.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only check, run after deploying the accounting categories: shows how
 * many rows are in each category and proves every ledger entry sits in an
 * account of its own transaction's category.
 */
final class VerifyLedgerCategories extends Command
{
    protected $signature = 'finance:verify-categories';

    protected $description = 'Show the rows in each accounting category and check every ledger entry sits in an account of its transaction\'s category';

    public function handle(): int
    {
        $db = DB::connection('landlord');

        foreach (['event_payouts', 'ledger_accounts', 'ledger_transactions'] as $table) {
            $rows = $db->table($table)
                ->select('category', DB::raw('count(*) as total'))
                ->groupBy('category')
                ->orderBy('category')
                ->get();

            $this->table([$table, 'rows'], $rows->map(fn (object $row): array => [$row->category, $row->total])->all());
        }

        $mismatched = (int) $db->table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->join('ledger_transactions as t', 't.id', '=', 'e.transaction_id')
            ->whereColumn('a.category', '<>', 't.category')
            ->count();

        if ($mismatched > 0) {
            $this->error("{$mismatched} ledger entries sit in an account of a different category from their transaction.");

            return self::FAILURE;
        }

        $this->info('Every ledger entry sits in an account of its transaction\'s category.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Finance/VerifyLedgerCategoriesTest.php`
Expected: PASS (2 tests). The failure message in the second test reads `1 ledger entries sit...`; the test matches `1 ledger entr`, which is a prefix of that wording.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Finance/VerifyLedgerCategories.php tests/Feature/Finance/VerifyLedgerCategoriesTest.php
git commit -m "feat(finance): add a command to verify ledger categories after deploy

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Verify the whole change and prepare the deploy

**Files:**
- Modify: `docs/superpowers/specs/2026-10-13-member-dues-design.md` (mark milestone 1 built, one line)
- Modify: memory file `member_dues_design_decisions.md` (status line)

- [ ] **Step 1: Run the code-quality gates**

```bash
"$PHP" vendor/bin/pint
"$PHP" vendor/bin/pint --test
"$PHP" vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```
Expected: Pint passes; PHPStan reports no errors. If PHPStan reports an error about the enum cast on `$payout->category`, add `@property LedgerCategoryEnum|null $category` to the model docblock.

- [ ] **Step 2: Run the whole suite in parallel**

Run: `"$PHP" artisan test --compact --parallel --processes=4`
Expected: all tests pass. If a test fails with `null value in column "category"`, it creates a ledger account, ledger transaction or payout directly; give it a category the way Task 3 or Task 4 did, then re-run.

- [ ] **Step 3: Prove the migration against a copy of real data**

On a database that has real ledger rows (a local dump of production), run:
`"$PHP" artisan migrate --force && "$PHP" artisan finance:verify-categories`
Expected: the migration completes, the command prints counts and exits 0, and the sum of `ledger_entries.amount` and the entry count are unchanged from before the migration. Record the before and after counts in the pull request description. If a local dump is not available, state that in the pull request and rely on the production run in Step 5.

- [ ] **Step 4: Open the pull request**

```bash
git push -u origin HEAD
gh pr create --base main --title "Accounting categories for payouts and the ledger (dues milestone 1)" --body "Milestone 1 of the member dues design: every payout, ledger account and ledger transaction carries a category (event, dues, commission_credit, marketplace), enforced by a check constraint. Backfills existing rows and splits accounts shared with marketplace postings. No visible change for organisers; the payout oversight page now shows each payout's category.

Deploy: the deploy command runs the migration. Afterwards run \`php artisan finance:verify-categories\` on production and expect exit 0.

Spec: docs/superpowers/specs/2026-10-13-member-dues-design.md"
```
Expected: CI (code-quality and laravel-tests) passes.

- [ ] **Step 5: After merge and deploy, verify production**

Run on production (`cloud command:run` for the production environment):
`php artisan finance:verify-categories`
Expected: exit 0 and counts per category. Before merging, record production's total `ledger_entries.amount` and entry count (read-only tinker); after, confirm both are unchanged.

- [ ] **Step 6: Record the result**

In the spec's section 12, append `Milestone 1 built and deployed on <date>.` to item 1. In the memory file `member_dues_design_decisions.md`, add a line stating milestone 1 is deployed and milestone 2 (collecting dues) is next. Commit with the message `docs: mark dues milestone 1 deployed`.
