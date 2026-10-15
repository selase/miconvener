# Commission and Fee Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every fee on the platform explicit, capped, overridable per tenant and per event, and correctly booked in the double-entry ledger — including the Paystack transfer fee the platform currently absorbs in silence.

**Architecture:** One resolver (`PlatformFeeResolver`) answers four questions — commission percentage, commission cap, who bears the fee, and the transfer-fee schedule — each through the same four-level cascade the codebase already uses for the percentage: `event → tenant → package → config`. One calculator (`FeeCalculator`) turns those settings plus a ticket price into an immutable `FeeBreakdown`, and every call site that currently does inline arithmetic consumes it instead. The ledger is corrected to debit only the cash that actually arrives, so `organizer_payable` clears exactly on payout.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 5, PostgreSQL (`landlord` connection), Inertia + React for tenant screens, Blade/Metronic for admin screens.

**Spec:** No separate spec file. This plan is the authority; the commercial decisions it encodes were settled in conversation on 2026-09-12 and are restated verbatim under Commercial Decisions below.

## Commercial Decisions (binding)

These are the agreed numbers. Do not substitute your own.

| Setting | Value | Notes |
|---|---|---|
| Commission — Free package | 5.00% | already seeded |
| Commission — Starter package | 3.50% | already seeded |
| Commission — Growth package | 2.00% | already seeded |
| Commission — Enterprise package | `null` | negotiated per tenant |
| Commission cap | GHS 20.00 (`2000` pesewas) | per ticket; binds above a GHS 1,000 ticket |
| Commission floor | none | the transfer-fee pass-through removes the need for one |
| Paystack collection fee | 1.95% | estimate only; actuals come from the webhook |
| Paystack transfer — mobile money | GHS 1.00 (`100` pesewas) | |
| Paystack transfer — bank account | GHS 8.00 (`800` pesewas) | |
| Default fee bearer | `organizer` | unchanged behaviour for existing tenants |
| Payout batching | one payout per event | the transfer fee is per payout, not per ticket |
| Waiver mechanism | set `0.00` at event or tenant level | `0.0 ?? x` yields `0.0`, so the existing cascade already supports this |

At these numbers a tenant keeps **96.05%** of every ticket up to GHS 1,000, and more above it as the cap takes effect.

## Global Constraints

- Money is **integer minor units (pesewas)** everywhere. Never a float, never a currency string. Percentages are `decimal(5,2)` and are the only non-integer money-adjacent values.
- `Model::shouldBeStrict()` is ON. Every attribute you write must be in `$fillable`; every attribute you read must have been selected. A missing entry throws.
- `tenants`, `events`, `event_payouts`, `ledger_*` all live on the **`landlord`** connection. Their migrations belong in `database/migrations/landlord/`, and models set `protected $connection = 'landlord'`.
- `BelongsToTenant` applies a global `TenantScope` that is a **no-op when `TenantContext::activeTenantId()` is null** — true in console commands, webhooks and queued jobs. Any query that must be global uses `withoutGlobalScope(TenantScope::class)` explicitly rather than relying on that accident.
- Never call `env()` outside `config/`. Read `config('services.platform.*')`.
- PHP style: curly braces on every control structure, explicit return types on every method, constructor property promotion, PHPDoc blocks over inline comments.
- Run `vendor/bin/pint --dirty` before every commit.
- Local toolchain: `PHP="$HOME/Library/Application Support/Herd/bin/php"`. A bare `php` is broken on this machine. Run tests as `"$PHP" artisan test --compact --filter=<name>`.
- Do not change `composer.json` or `package.json`. No new dependencies.
- Pest loads every test file into one process, so a top-level `function` in a test file is **global**. This plan defines three — `eventWithFee` (Task 2), `payableBalance` (Task 5) and `eventUpdatePayload` (Task 6) — each in a different file. Before adding any other helper, `grep -rn "^function <name>" tests` to confirm the name is free; a collision is a fatal redeclaration error, not a test failure.
- Existing test helpers to reuse rather than reinvent: `refreshTenantDatabases()`, `eventHost('<slug>')` (returns `[$tenant, $user]`) and `eventSubdomainHost('<slug>')`. Tenant-routed requests need both the `http://{$host}/...` URL and the `['HTTP_HOST' => $host]` server array — see `tests/Feature/Events/EventRegistrationTest.php`.

## File Structure

**Created**

| Path | Responsibility |
|---|---|
| `app/Services/Finance/PlatformFeeResolver.php` | Resolves the four fee settings through the cascade. No arithmetic. |
| `app/Services/Finance/FeeBreakdown.php` | Immutable readonly value object: the full fee split for one ticket. |
| `app/Services/Finance/FeeCalculator.php` | Turns settings + ticket price into a `FeeBreakdown`. Pure, no DB. |
| `app/Services/Finance/TransferFeeSchedule.php` | Maps a payout destination type to its Paystack transfer fee. |
| `database/migrations/landlord/2026_09_12_100000_add_fee_cap_and_bearer_to_tenants_and_events_table.php` | `platform_fee_cap_amount`, `fee_bearer` on both tables. |
| `database/migrations/2026_09_12_100001_add_fee_defaults_to_packages_table.php` | `default_platform_fee_cap_amount`, `default_fee_bearer`. |
| `database/migrations/landlord/2026_09_12_100002_add_fee_columns_to_registrations_and_payouts.php` | `charged_amount`, `gateway_fee_amount` on registrations; `transfer_fee_amount`, `net_paid_amount` on payouts. |
| `tests/Unit/Services/Finance/FeeCalculatorTest.php` | Cap, waiver, bearer and rounding arithmetic. |
| `tests/Unit/Services/Finance/PlatformFeeResolverTest.php` | The cascade for cap and bearer. |
| `tests/Feature/Finance/AttendeeBorneFeeTest.php` | Checkout charges the buyer the fee when `fee_bearer` is `attendee`. |
| `tests/Feature/Finance/TransferFeePassThroughTest.php` | Payout deducts and records the transfer fee. |
| `tests/Feature/Finance/GatewayFeeLedgerTest.php` | `organizer_payable` reaches exactly zero after payout. |

**Modified**

| Path | Change |
|---|---|
| `config/services.php:63-65` | Add `default_fee_cap_amount`, `default_fee_bearer`, `gateway_fee_percentage`, `transfer_fees`. |
| `app/Models/Event.php:325` | Keep `effectivePlatformFeePercentage()`; add `effectivePlatformFeeCapAmount()` and `effectiveFeeBearer()`. |
| `app/Models/Tenant.php` | `$fillable` gains the two new columns. |
| `app/Models/Package.php` | `$fillable` and `casts()` gain the two new defaults. |
| `app/Models/EventRegistration.php:63-75` | `$fillable`/`casts()` gain `charged_amount`, `gateway_fee_amount`. |
| `app/Models/EventPayout.php:30-46` | `$fillable`/`casts()` gain `transfer_fee_amount`, `net_paid_amount`. |
| `app/Http/Controllers/Public/PublicEventController.php:180` | Use `FeeCalculator`. |
| `app/Http/Controllers/Public/EventCheckoutController.php:58` | Charge `charged_amount`, not `amount`. |
| `app/Http/Controllers/Tenant/Commerce/WebhookController.php:161` | Use `FeeCalculator`; capture the real gateway fee. |
| `app/Http/Controllers/Public/SettlementWebhookController.php:161` | Same. |
| `app/Services/Finance/LedgerService.php:150-202` | `recordTicketSale` takes a gateway fee and balances against cash actually received. |
| `app/Services/Finance/LedgerService.php` (`recordPayout`) | Accept and book the transfer fee. |
| `database/seeders/EventPackageSeeder.php:43,67,91,115` | Add `default_cap` and `default_bearer` per tier. |

---

### Task 1: Extend the fee cascade to the cap and the bearer

The percentage cascade already exists and works. This task adds two more settings to it and nothing else — no arithmetic, no call-site changes.

**Files:**
- Create: `database/migrations/landlord/2026_09_12_100000_add_fee_cap_and_bearer_to_tenants_and_events_table.php`
- Create: `database/migrations/2026_09_12_100001_add_fee_defaults_to_packages_table.php`
- Create: `app/Services/Finance/PlatformFeeResolver.php`
- Modify: `config/services.php` (the `platform` block at lines 63-65)
- Modify: `app/Models/Event.php` (after `effectivePlatformFeePercentage()` at line 325)
- Modify: `app/Models/Tenant.php`, `app/Models/Package.php` (`$fillable`, `casts()`)
- Modify: `database/seeders/EventPackageSeeder.php`
- Test: `tests/Unit/Services/Finance/PlatformFeeResolverTest.php`

**Interfaces:**
- Consumes: `Event::effectivePlatformFeePercentage()` (exists, unchanged).
- Produces: `PlatformFeeResolver::resolve(Event $event): PlatformFeeSettings` — but note this task deliberately does NOT introduce a `PlatformFeeSettings` class. The resolver exposes three methods that later tasks call:
  - `percentageFor(Event $event): float`
  - `capAmountFor(Event $event): ?int` — pesewas, `null` means uncapped
  - `bearerFor(Event $event): string` — `'organizer'` or `'attendee'`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Finance/PlatformFeeResolverTest.php`. Mirror the existing idiom in `tests/Unit/Models/EventEffectivePlatformFeePercentageTest.php` — plain `test()` closures, factories, `expect()`.

```php
<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Package;
use App\Models\Tenant;
use App\Services\Finance\PlatformFeeResolver;

test('the cap falls back to the package default', function () {
    $package = Package::factory()->create(['default_platform_fee_cap_amount' => 2000]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_cap_amount' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_cap_amount' => null]);

    expect(app(PlatformFeeResolver::class)->capAmountFor($event))->toBe(2000);
});

test('an event cap override wins over the tenant and the package', function () {
    $package = Package::factory()->create(['default_platform_fee_cap_amount' => 2000]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_cap_amount' => 5000]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_cap_amount' => 750]);

    expect(app(PlatformFeeResolver::class)->capAmountFor($event))->toBe(750);
});

test('a zero cap is honoured rather than treated as absent', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_cap_amount' => 0]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_cap_amount' => null]);

    expect(app(PlatformFeeResolver::class)->capAmountFor($event))->toBe(0);
});

test('the bearer defaults to the organizer', function () {
    config(['services.platform.default_fee_bearer' => 'organizer']);
    $tenant = Tenant::factory()->create(['package_id' => null, 'fee_bearer' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'fee_bearer' => null]);

    expect(app(PlatformFeeResolver::class)->bearerFor($event))->toBe('organizer');
});

test('an event can move the fee onto the attendee', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'fee_bearer' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'fee_bearer' => 'attendee']);

    expect(app(PlatformFeeResolver::class)->bearerFor($event))->toBe('attendee');
});

test('percentageFor delegates to the model so both paths agree', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => 1.75]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => null]);

    expect(app(PlatformFeeResolver::class)->percentageFor($event))
        ->toEqual($event->effectivePlatformFeePercentage());
});

test('a charity waiver is expressed as a zero percentage', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => 2.0]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 0.0]);

    expect(app(PlatformFeeResolver::class)->percentageFor($event))->toEqual(0.0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
PHP="$HOME/Library/Application Support/Herd/bin/php"
"$PHP" artisan test --compact --filter=PlatformFeeResolver
```

Expected: FAIL — `Class "App\Services\Finance\PlatformFeeResolver" not found`.

- [ ] **Step 3: Add the landlord migration**

`database/migrations/landlord/2026_09_12_100000_add_fee_cap_and_bearer_to_tenants_and_events_table.php`. Follow the shape of the existing `2026_09_04_085402_add_platform_fee_percentage_to_tenants_and_events_table.php` exactly — same connection, same `after()` discipline.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_fee_cap_amount')->nullable()->after('platform_fee_percentage');
            $table->string('fee_bearer')->nullable()->after('platform_fee_cap_amount');
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_fee_cap_amount')->nullable()->after('platform_fee_percentage');
            $table->string('fee_bearer')->nullable()->after('platform_fee_cap_amount');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['platform_fee_cap_amount', 'fee_bearer']);
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn(['platform_fee_cap_amount', 'fee_bearer']);
        });
    }
};
```

- [ ] **Step 4: Add the packages migration**

`packages` lives on the default connection — check `database/migrations/2026_09_05_000001_add_default_platform_fee_percentage_to_packages_table.php` and copy its connection handling verbatim rather than assuming.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_platform_fee_cap_amount')->nullable()->after('default_platform_fee_percentage');
            $table->string('default_fee_bearer')->nullable()->after('default_platform_fee_cap_amount');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn(['default_platform_fee_cap_amount', 'default_fee_bearer']);
        });
    }
};
```

- [ ] **Step 5: Extend the config block**

`config/services.php`, replacing the `platform` block currently at lines 63-65:

```php
    'platform' => [
        'default_fee_percentage' => (float) env('PLATFORM_DEFAULT_FEE_PERCENTAGE', 5.0),

        /**
         * Commission ceiling per ticket, in minor units. Binds above a
         * GHS 1,000 ticket at a 2% rate. Null means uncapped.
         */
        'default_fee_cap_amount' => env('PLATFORM_DEFAULT_FEE_CAP_AMOUNT') !== null
            ? (int) env('PLATFORM_DEFAULT_FEE_CAP_AMOUNT')
            : 2000,

        /**
         * Who pays the platform commission: 'organizer' deducts it from the
         * ticket price, 'attendee' adds it to what the buyer is charged.
         */
        'default_fee_bearer' => env('PLATFORM_DEFAULT_FEE_BEARER', 'organizer'),

        /**
         * Paystack Ghana's published collection rate. Used only for the
         * estimate shown before a sale — the ledger always books the actual
         * fee reported by the webhook.
         */
        'gateway_fee_percentage' => (float) env('PLATFORM_GATEWAY_FEE_PERCENTAGE', 1.95),

        /**
         * Paystack Ghana transfer fees, in minor units, keyed by destination.
         */
        'transfer_fees' => [
            // Keys are TenantPayoutAccount::TYPE_MOBILE_MONEY and TYPE_BANK,
            // so no mapping layer is needed between the account and the fee.
            'mobile_money' => (int) env('PLATFORM_TRANSFER_FEE_MOMO', 100),
            'bank' => (int) env('PLATFORM_TRANSFER_FEE_BANK', 800),
        ],
    ],
```

Add the five new keys to `.env.example` under a `# Platform fees` heading, with these same defaults as their values.

- [ ] **Step 6: Add the model accessors**

In `app/Models/Event.php`, directly after `effectivePlatformFeePercentage()` (which ends at line 331). Note the `??` cascade is correct for the cap because a stored `0` is not null — a zero cap means "commission is always zero", which is a legitimate waiver expression and must not be swallowed.

```php
    /**
     * The commission ceiling for one ticket on this event, in minor units.
     * Null means uncapped.
     */
    public function effectivePlatformFeeCapAmount(): ?int
    {
        $cap = $this->platform_fee_cap_amount
            ?? $this->tenant?->platform_fee_cap_amount
            ?? $this->tenant?->package?->default_platform_fee_cap_amount
            ?? config('services.platform.default_fee_cap_amount');

        return $cap === null ? null : (int) $cap;
    }

    /**
     * Whether the organizer absorbs the platform commission or the attendee
     * pays it on top of the ticket price.
     */
    public function effectiveFeeBearer(): string
    {
        return (string) ($this->fee_bearer
            ?? $this->tenant?->fee_bearer
            ?? $this->tenant?->package?->default_fee_bearer
            ?? config('services.platform.default_fee_bearer'));
    }
```

Add `'platform_fee_cap_amount'` and `'fee_bearer'` to `$fillable` on both `Event` and `Tenant`, cast `platform_fee_cap_amount` to `'integer'` in each model's `casts()` method, and add `'default_platform_fee_cap_amount'` (cast `'integer'`) and `'default_fee_bearer'` to `Package`.

- [ ] **Step 7: Write the resolver**

`app/Services/Finance/PlatformFeeResolver.php`. It is a thin seam over the model accessors so that later tasks depend on an injectable service rather than on the model, and so a future fee source (a promo, a campaign) has one place to land.

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Event;

final class PlatformFeeResolver
{
    public const string BEARER_ORGANIZER = 'organizer';

    public const string BEARER_ATTENDEE = 'attendee';

    public function percentageFor(Event $event): float
    {
        return $event->effectivePlatformFeePercentage();
    }

    public function capAmountFor(Event $event): ?int
    {
        return $event->effectivePlatformFeeCapAmount();
    }

    public function bearerFor(Event $event): string
    {
        $bearer = $event->effectiveFeeBearer();

        return $bearer === self::BEARER_ATTENDEE
            ? self::BEARER_ATTENDEE
            : self::BEARER_ORGANIZER;
    }

    /**
     * Paystack's published collection rate, for pre-sale estimates only.
     */
    public function gatewayFeePercentage(): float
    {
        return (float) config('services.platform.gateway_fee_percentage');
    }
}
```

- [ ] **Step 8: Seed the per-tier defaults**

In `database/seeders/EventPackageSeeder.php`, add `'default_cap' => 2000` and `'default_bearer' => 'organizer'` to each of the four package config arrays (lines 43, 67, 91, 115 hold their `default_fee` entries), extend the array-shape PHPDoc at line 33 to match, and map them through at line 171 alongside `default_platform_fee_percentage`:

```php
                    'default_platform_fee_cap_amount' => $config['default_cap'],
                    'default_fee_bearer' => $config['default_bearer'],
```

Enterprise keeps `'default_cap' => null` — a negotiated tenant gets its ceiling set on the tenant row.

- [ ] **Step 9: Run the tests to verify they pass**

```bash
PHP="$HOME/Library/Application Support/Herd/bin/php"
"$PHP" artisan migrate --no-interaction
"$PHP" artisan test --compact --filter=PlatformFeeResolver
"$PHP" artisan test --compact --filter=EventEffectivePlatformFeePercentage
```

Expected: PASS, and the pre-existing percentage test still green.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: extend the fee cascade to a commission cap and a fee bearer"
```

---

### Task 2: A calculator that produces one authoritative fee breakdown

Four call sites currently compute the commission inline as `(int) round($amount * $pct / 100)`. None of them applies a cap, none knows about the bearer, and they will drift. This task replaces all four with one pure calculator.

**Files:**
- Create: `app/Services/Finance/FeeBreakdown.php`
- Create: `app/Services/Finance/FeeCalculator.php`
- Modify: `app/Http/Controllers/Public/PublicEventController.php:180`
- Modify: `app/Http/Controllers/Tenant/Commerce/WebhookController.php:161`
- Modify: `app/Http/Controllers/Public/SettlementWebhookController.php:161`
- Modify: `app/Http/Controllers/Tenant/EventController.php:241`
- Test: `tests/Unit/Services/Finance/FeeCalculatorTest.php`

**Interfaces:**
- Consumes: `PlatformFeeResolver::percentageFor()`, `capAmountFor()`, `bearerFor()`, `gatewayFeePercentage()` from Task 1.
- Produces:
  - `FeeCalculator::for(Event $event, int $ticketAmount): FeeBreakdown`
  - `FeeBreakdown` readonly properties: `ticketAmount`, `platformFee`, `gatewayFeeEstimate`, `chargedAmount`, `organizerNet`, `bearer`, `percentage`, `capApplied` (bool).

`chargedAmount` is what the payment gateway must be told to collect. `organizerNet` is what the organizer is owed before any transfer fee. Both are always integers in minor units.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Finance/FeeCalculatorTest.php`.

```php
<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Tenant;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\PlatformFeeResolver;

function eventWithFee(float $percentage, ?int $cap = 2000, string $bearer = 'organizer'): Event
{
    $tenant = Tenant::factory()->create(['package_id' => null]);

    return Event::factory()->create([
        'tenant_id' => $tenant->id,
        'platform_fee_percentage' => $percentage,
        'platform_fee_cap_amount' => $cap,
        'fee_bearer' => $bearer,
        'currency' => 'GHS',
    ]);
}

test('a GHS 100 ticket at 2 percent splits as agreed', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 10000);

    expect($breakdown->ticketAmount)->toBe(10000)
        ->and($breakdown->platformFee)->toBe(200)
        ->and($breakdown->gatewayFeeEstimate)->toBe(195)
        ->and($breakdown->chargedAmount)->toBe(10000)
        ->and($breakdown->organizerNet)->toBe(9605)
        ->and($breakdown->capApplied)->toBeFalse();
});

test('the cap binds on a GHS 1500 ticket', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 150000);

    expect($breakdown->platformFee)->toBe(2000)
        ->and($breakdown->capApplied)->toBeTrue();
});

test('the cap does not bind at exactly GHS 1000', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 100000);

    expect($breakdown->platformFee)->toBe(2000)
        ->and($breakdown->capApplied)->toBeFalse();
});

test('a null cap leaves the commission uncapped', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0, cap: null), 500000);

    expect($breakdown->platformFee)->toBe(10000)
        ->and($breakdown->capApplied)->toBeFalse();
});

test('a waived event charges no commission at all', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(0.0), 10000);

    expect($breakdown->platformFee)->toBe(0)
        ->and($breakdown->organizerNet)->toBe(9805);
});

test('an attendee-borne fee is added to what the buyer is charged', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0, bearer: 'attendee'), 10000);

    // The attendee absorbs the GHS 2 commission, so the organizer keeps the
    // full GHS 100 ticket less only Paystack's 1.95% on the larger charge.
    expect($breakdown->chargedAmount)->toBe(10200)
        ->and($breakdown->platformFee)->toBe(200)
        ->and($breakdown->gatewayFeeEstimate)->toBe(199)
        ->and($breakdown->organizerNet)->toBe(9801);
});

test('a free ticket moves no money and incurs no fee', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 0);

    expect($breakdown->platformFee)->toBe(0)
        ->and($breakdown->chargedAmount)->toBe(0)
        ->and($breakdown->organizerNet)->toBe(0)
        ->and($breakdown->gatewayFeeEstimate)->toBe(0);
});

test('a negative ticket amount is rejected rather than silently coerced', function () {
    app(FeeCalculator::class)->for(eventWithFee(2.0), -1);
})->throws(InvalidArgumentException::class);

test('rounding is half-up on a half-pesewa commission', function () {
    // 2% of 2550 pesewas is 51.0; 2% of 2525 is 50.5 and must round to 51.
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 2525);

    expect($breakdown->platformFee)->toBe(51);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
"$PHP" artisan test --compact --filter=FeeCalculator
```

Expected: FAIL — `Class "App\Services\Finance\FeeCalculator" not found`.

- [ ] **Step 3: Write the value object**

`app/Services/Finance/FeeBreakdown.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

/**
 * The complete fee split for a single ticket, in minor units.
 *
 * `chargedAmount` is what the payment gateway collects from the buyer.
 * `organizerNet` is what the organizer is owed before any transfer fee.
 */
final readonly class FeeBreakdown
{
    public function __construct(
        public int $ticketAmount,
        public int $platformFee,
        public int $gatewayFeeEstimate,
        public int $chargedAmount,
        public int $organizerNet,
        public string $bearer,
        public float $percentage,
        public bool $capApplied,
    ) {}

    /**
     * @return array{ticket_amount: int, platform_fee: int, gateway_fee_estimate: int, charged_amount: int, organizer_net: int, bearer: string, percentage: float, cap_applied: bool}
     */
    public function toArray(): array
    {
        return [
            'ticket_amount' => $this->ticketAmount,
            'platform_fee' => $this->platformFee,
            'gateway_fee_estimate' => $this->gatewayFeeEstimate,
            'charged_amount' => $this->chargedAmount,
            'organizer_net' => $this->organizerNet,
            'bearer' => $this->bearer,
            'percentage' => $this->percentage,
            'cap_applied' => $this->capApplied,
        ];
    }
}
```

- [ ] **Step 4: Write the calculator**

`app/Services/Finance/FeeCalculator.php`. The gateway fee is always borne by the organizer — Paystack nets it out of the settlement regardless of who pays the platform commission — so it is subtracted from `organizerNet` in both bearer modes.

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Event;
use InvalidArgumentException;

final class FeeCalculator
{
    public function __construct(private PlatformFeeResolver $resolver) {}

    public function for(Event $event, int $ticketAmount): FeeBreakdown
    {
        if ($ticketAmount < 0) {
            throw new InvalidArgumentException('Ticket amounts cannot be negative.');
        }

        $percentage = $this->resolver->percentageFor($event);
        $bearer = $this->resolver->bearerFor($event);
        $cap = $this->resolver->capAmountFor($event);

        if ($ticketAmount === 0) {
            return new FeeBreakdown(0, 0, 0, 0, 0, $bearer, $percentage, false);
        }

        $uncappedFee = (int) round($ticketAmount * $percentage / 100);
        $platformFee = $cap !== null ? min($uncappedFee, $cap) : $uncappedFee;
        $capApplied = $cap !== null && $uncappedFee > $cap;

        $chargedAmount = $bearer === PlatformFeeResolver::BEARER_ATTENDEE
            ? $ticketAmount + $platformFee
            : $ticketAmount;

        $gatewayFeeEstimate = (int) round(
            $chargedAmount * $this->resolver->gatewayFeePercentage() / 100
        );

        $organizerNet = $chargedAmount - $platformFee - $gatewayFeeEstimate;

        return new FeeBreakdown(
            ticketAmount: $ticketAmount,
            platformFee: $platformFee,
            gatewayFeeEstimate: $gatewayFeeEstimate,
            chargedAmount: $chargedAmount,
            organizerNet: $organizerNet,
            bearer: $bearer,
            percentage: $percentage,
            capApplied: $capApplied,
        );
    }
}
```

Note what the attendee-borne case does and does not shift. Charged 10200, Paystack's estimate on 10200 is 199, so `organizerNet` is 10200 − 200 − 199 = 9801. The attendee absorbs the platform commission; the organizer still bears the collection fee, because Paystack nets that out of the settlement no matter who agreed to pay the commission. Say so in the commit body — it is the detail a tenant will ask about.

- [ ] **Step 5: Replace the four inline call sites**

Each currently reads some variant of:

```php
$platformFeeAmount = $isFree ? 0 : (int) round($amount * $eventModel->effectivePlatformFeePercentage() / 100);
```

Replace with an injected `FeeCalculator` (constructor-promoted where the controller already has a constructor; otherwise `app(FeeCalculator::class)` is acceptable in these controllers — check each file's existing style first and match it):

```php
$fees = $feeCalculator->for($eventModel, $amount);
$platformFeeAmount = $fees->platformFee;
```

In `PublicEventController.php:180` also persist the breakdown on the registration — `'charged_amount' => $fees->chargedAmount` — which Task 3 depends on. The two webhook controllers at line 161 must keep booking the commission they already compute; only the arithmetic source changes.

In `EventController.php:241`, extend the payload the tenant UI receives so the console can show the real terms:

```php
                'effective_platform_fee_percentage' => $eventModel->effectivePlatformFeePercentage(),
                'effective_platform_fee_cap_amount' => $eventModel->effectivePlatformFeeCapAmount(),
                'effective_fee_bearer' => $eventModel->effectiveFeeBearer(),
```

- [ ] **Step 6: Fix the misleading ledger description**

`app/Services/Finance/LedgerService.php:185` interpolates `$event->platform_fee_percentage` — the raw nullable column — so every sale on an event without an explicit override is described as `Platform commission fee (%)`. Change it to the effective value:

```php
                'description' => 'Platform commission fee ('.$event->effectivePlatformFeePercentage().'%)',
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan test --compact --filter=FeeCalculator
"$PHP" artisan test --compact --filter=LedgerParity
"$PHP" artisan test --compact --filter=PublicEvent
```

Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: one fee calculator with a commission cap replaces four inline formulas"
```

---

### Task 3: Let an organizer pass the commission to the ticket buyer

This is the churn-reducing feature. A tenant who sets an event to `attendee` receives the full ticket price; the buyer sees the commission added at checkout.

**Files:**
- Create: `database/migrations/landlord/2026_09_12_100002_add_fee_columns_to_registrations_and_payouts.php`
- Modify: `app/Models/EventRegistration.php` (`$fillable` around line 63, `casts()` around line 73)
- Modify: `app/Http/Controllers/Public/EventCheckoutController.php:58`
- Test: `tests/Feature/Finance/AttendeeBorneFeeTest.php`

**Interfaces:**
- Consumes: `FeeBreakdown::$chargedAmount` from Task 2; `EventRegistration.charged_amount` written by Task 2 Step 5.
- Produces: `EventRegistration.charged_amount` (int, minor units) — the amount the gateway was told to collect. Task 4 and Task 5 read it. Also `EventRegistration.gateway_fee_amount` (int, nullable) — the actual fee reported by the webhook, written in Task 5.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/AttendeeBorneFeeTest.php`. Read an existing checkout feature test first to copy this codebase's tenant-context setup and its route names — do not invent them.

```php
<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('an organizer-borne event charges the buyer the ticket price only', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => 2.0]);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => 'organizer',
        'platform_fee_cap_amount' => 2000,
        'currency' => 'GHS',
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
        'platform_fee_amount' => 200,
        'charged_amount' => 10000,
    ]);

    expect($registration->charged_amount)->toBe(10000);
});

test('an attendee-borne event charges the buyer the ticket price plus commission', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => 2.0]);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => 'attendee',
        'platform_fee_cap_amount' => 2000,
        'currency' => 'GHS',
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
        'platform_fee_amount' => 200,
        'charged_amount' => 10200,
    ]);

    expect($registration->charged_amount)->toBe(10200)
        ->and($registration->amount)->toBe(10000);
});

test('the gateway is told to collect the charged amount, not the bare ticket price', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $tenant->update([
        'settlement_mode' => 'platform_default',
        'platform_fee_percentage' => 2.0,
        'platform_fee_cap_amount' => 2000,
    ]);

    config([
        'services.paystack.secret_key' => 'sk_test_checkout_123',
        'services.settlement.paystack.secret_key' => 'sk_test_checkout_123',
    ]);

    Http::fake([
        'api.paystack.co/customer*' => Http::response([
            'status' => true,
            'data' => ['id' => 1, 'customer_code' => 'CUS_test_1', 'email' => 'buyer@example.com'],
        ]),
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/test'],
        ]),
    ]);

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => 'attendee',
        'currency' => 'GHS',
        'status' => 'published',
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'buyer@example.com',
        'amount' => 10000,
        'platform_fee_amount' => 200,
        'charged_amount' => 10200,
        'currency' => 'GHS',
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    $this->get("http://{$host}/e/{$event->id}/checkout/{$registration->id}", ['HTTP_HOST' => $host]);

    Http::assertSent(function ($request): bool {
        return str_contains((string) $request->url(), 'transaction/initialize')
            && (int) $request['amount'] === 10200;
    });
});
```

The third test is the one that proves the behaviour rather than the schema. If the route signature or the tenant settlement setup differs from the above, adjust the setup — but the `Http::assertSent` assertion on `10200` is the point of the test and must survive intact. Read `tests/Feature/Events/EventRegistrationTest.php` for the `eventHost` / `eventSubdomainHost` helpers and the `refreshTenantDatabases()` plus `RoleSeeder`/`PermissionsSeeder` `beforeEach` that public-event tests require.

- [ ] **Step 2: Run the test to verify it fails**

```bash
"$PHP" artisan test --compact --filter=AttendeeBorneFee
```

Expected: FAIL — unknown column `charged_amount`.

- [ ] **Step 3: Add the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->unsignedBigInteger('charged_amount')->nullable()->after('platform_fee_amount');
            $table->unsignedBigInteger('gateway_fee_amount')->nullable()->after('charged_amount');
        });

        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('transfer_fee_amount')->default(0)->after('amount');
            $table->unsignedBigInteger('net_paid_amount')->nullable()->after('transfer_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn(['charged_amount', 'gateway_fee_amount']);
        });

        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->dropColumn(['transfer_fee_amount', 'net_paid_amount']);
        });
    }
};
```

`charged_amount` is nullable because rows predating this migration have no value. Every read must treat null as "equal to `amount`" — add that fallback as an accessor rather than scattering `??` at call sites:

```php
    /**
     * What the gateway was asked to collect. Registrations created before
     * fee pass-through existed were charged the bare ticket price.
     */
    public function effectiveChargedAmount(): int
    {
        return (int) ($this->charged_amount ?? $this->amount);
    }
```

Add `'charged_amount'` and `'gateway_fee_amount'` to `EventRegistration::$fillable` and cast both to `'integer'` in `casts()`. Do the same for `'transfer_fee_amount'` and `'net_paid_amount'` on `EventPayout`.

- [ ] **Step 4: Charge the right amount**

`app/Http/Controllers/Public/EventCheckoutController.php:58` currently passes `$registrationModel->amount`. Change it to `$registrationModel->effectiveChargedAmount()`.

This is the single line that makes pass-through real. Read the surrounding 40 lines first: confirm nothing downstream re-derives the expected amount from `amount`, because a mismatch between what Paystack collected and what the webhook expects would reject a genuine payment. If anything does, it must move to `effectiveChargedAmount()` in the same commit.

- [ ] **Step 5: Verify the webhook accepts the larger amount**

Both `WebhookController.php` and `SettlementWebhookController.php` compare the amount Paystack reports against what the platform expected. Grep each for comparisons against `amount` and move them to `effectiveChargedAmount()`:

```bash
grep -n "amount" app/Http/Controllers/Tenant/Commerce/WebhookController.php app/Http/Controllers/Public/SettlementWebhookController.php
```

A pass-through sale collects 10200 against a registration whose `amount` is 10000. Any equality check on `amount` will reject it.

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan migrate --no-interaction
"$PHP" artisan test --compact --filter=AttendeeBorneFee
"$PHP" artisan test --compact --filter=Webhook
"$PHP" artisan test --compact --filter=Checkout
```

Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: an organizer can pass the platform commission to the ticket buyer"
```

---

### Task 4: Record the transfer fee and pass it through at cost

Today the platform absorbs every Paystack transfer fee — GHS 1 to mobile money, GHS 8 to a bank — and records it nowhere. On a small event that alone can exceed the commission earned.

**Files:**
- Create: `app/Services/Finance/TransferFeeSchedule.php`
- Modify: `app/Services/Settlement/PaystackSettlementGateway.php` (`initiateTransfer` at line 54, `verifyTransfer` at line 75)
- Modify: `app/Http/Controllers/Tenant/EventFinanceController.php` (the payout path, near line 241)
- Test: `tests/Feature/Finance/TransferFeePassThroughTest.php`

**Interfaces:**
- Consumes: `EventPayout.transfer_fee_amount`, `net_paid_amount` from Task 3; `config('services.platform.transfer_fees')` from Task 1.
- Produces:
  - `TransferFeeSchedule::feeFor(string $destinationType): int`
  - `TransferFeeSchedule::feeFromProviderResponse(array $response): ?int`
  - `PaystackSettlementGateway::initiateTransfer()` and `verifyTransfer()` gain a nullable `fee` key in their returned arrays (additive; existing keys unchanged)

**Semantics — read this before writing code.** Paystack's `initiateTransfer(recipient, amount)` sends `amount` to the recipient and debits the platform's balance by `amount + fee`. So to pass the fee through on a payout of X that the organizer is owed:

- Initiate the transfer for **X − fee**. The organizer receives X − fee; Paystack takes fee; the platform's balance drops by exactly X.
- `event_payouts.amount` stays X (what was owed and what the ledger clears).
- `transfer_fee_amount` is fee; `net_paid_amount` is X − fee.
- Ledger: DEBIT `organizer_payable` X, CREDIT `gateway_clearing` X. The payable clears exactly, and no expense account is touched because the organizer bore the cost.

If the platform absorbs the fee instead (a goodwill gesture, an Enterprise term), initiate for X, and post DEBIT `organizer_payable` X + DEBIT `gateway_fees` fee + CREDIT `gateway_clearing` X + fee.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/TransferFeePassThroughTest.php`.

```php
<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\TransferFeeSchedule;
use App\Services\Settlement\PaystackSettlementGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('mobile money transfers cost one cedi', function () {
    expect(app(TransferFeeSchedule::class)->feeFor('mobile_money'))->toBe(100);
});

test('bank transfers cost eight cedis', function () {
    expect(app(TransferFeeSchedule::class)->feeFor('bank'))->toBe(800);
});

test('an unknown destination falls back to the dearer bank fee', function () {
    expect(app(TransferFeeSchedule::class)->feeFor('carrier_pigeon'))->toBe(800);
});

test('a fee reported by the provider overrides the schedule', function () {
    $actual = app(TransferFeeSchedule::class)->feeFromProviderResponse([
        'transfer_code' => 'TRF_test_123',
        'status' => 'pending',
        'fee' => 150,
    ]);

    expect($actual)->toBe(150);
});

test('a provider response without a fee yields null so the schedule is used', function () {
    expect(app(TransferFeeSchedule::class)->feeFromProviderResponse([
        'transfer_code' => 'TRF_test_123',
        'status' => 'pending',
        'fee' => null,
    ]))->toBeNull();
});

test('the gateway surfaces the transfer fee Paystack reports', function () {
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);

    Http::fake([
        'api.paystack.co/transfer' => Http::response([
            'status' => true,
            'data' => ['transfer_code' => 'TRF_test_123', 'status' => 'pending', 'fee_charged' => 100],
        ]),
    ]);

    $result = app(PaystackSettlementGateway::class)
        ->initiateTransfer('RCP_test_123', 699900, 'GHS', 'PAYOUT-REF-1');

    expect($result['fee'])->toBe(100)
        ->and($result['transfer_code'])->toBe('TRF_test_123');
});

test('a passed-through payout sends the organizer the amount less the fee', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);

    Http::fake([
        'api.paystack.co/transfer' => Http::response([
            'status' => true,
            'data' => ['transfer_code' => 'TRF_test_1', 'status' => 'pending'],
        ]),
    ]);

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'currency' => 'GHS',
    ]);

    $account = TenantPayoutAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY,
        'recipient_code' => 'RCP_test_1',
        'is_verified' => true,
    ]);

    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 700000,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])
        ->assertOk();

    $payout->refresh();

    expect($payout->amount)->toBe(700000)
        ->and($payout->transfer_fee_amount)->toBe(100)
        ->and($payout->net_paid_amount)->toBe(699900);

    // The organizer is sent GHS 6,999 and Paystack keeps the GHS 1 fee, so
    // the platform balance drops by exactly the GHS 7,000 it owed.
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/transfer')
        && (int) $request['amount'] === 699900);
});

test('a payout smaller than the bank transfer fee is refused rather than sent negative', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
    Http::fake();

    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $account = TenantPayoutAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => TenantPayoutAccount::TYPE_BANK,
        'recipient_code' => 'RCP_test_2',
        'is_verified' => true,
    ]);

    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 500,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($payout->refresh()->status)->toBe(EventPayout::STATUS_SCHEDULED);
    Http::assertNothingSent();
});
```

Both payout tests hit `tenant.events.finance.payouts.send`, which requires the `update event` permission — seed `RoleSeeder` and `PermissionsSeeder` in `beforeEach` as the public-event tests do, and confirm `eventHost()` returns a user holding it. If `TenantPayoutAccount` has no factory, create one; do not work around the gap by inserting rows by hand.

- [ ] **Step 2: Run the test to verify it fails**

```bash
"$PHP" artisan test --compact --filter=TransferFeePassThrough
```

Expected: FAIL — `Class "App\Services\Finance\TransferFeeSchedule" not found`.

- [ ] **Step 3: Write the schedule**

`app/Services/Finance/TransferFeeSchedule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\TenantPayoutAccount;

final class TransferFeeSchedule
{
    /**
     * The configured transfer fee for a destination, in minor units. Unknown
     * destinations fall back to the dearer bank fee so the platform never
     * under-recovers on a destination it does not recognise.
     */
    public function feeFor(string $destinationType): int
    {
        $fees = (array) config('services.platform.transfer_fees');

        return (int) ($fees[$destinationType] ?? $fees[TenantPayoutAccount::TYPE_BANK] ?? 800);
    }

    /**
     * The fee Paystack actually charged, when the transfer response carries
     * one. Null means the response did not report a fee and the configured
     * schedule should stand.
     *
     * @param  array{transfer_code?: string, status?: string, fee?: int|null}  $response
     */
    public function feeFromProviderResponse(array $response): ?int
    {
        $fee = $response['fee'] ?? null;

        return is_numeric($fee) ? (int) $fee : null;
    }
}
```

**The gateway must be widened first, or this method can never see a fee.**
`PaystackSettlementGateway::initiateTransfer` (line 54) currently returns only
`transfer_code` and `status`, discarding the rest of Paystack's response body.
Add the fee to what it surfaces, keeping the existing two keys so no caller
breaks:

```php
            return [
                'transfer_code' => (string) $response->json('data.transfer_code'),
                'status' => (string) $response->json('data.status'),
                'fee' => $response->json('data.fee_charged') ?? $response->json('data.fee'),
            ];
```

Apply the same addition to `verifyTransfer` (line 75), which is where a fee is
most likely to appear — Paystack does not always report one at initiation.
Whether Paystack Ghana populates either field on a transfer is **unverified**;
that is exactly why the configured schedule is the fallback and why
`feeFromProviderResponse` returns null rather than zero when the field is
absent. One real payout will settle it — check the recorded
`transfer_fee_amount` against the Paystack dashboard afterwards.

- [ ] **Step 4: No destination mapping is needed**

`TenantPayoutAccount` already carries the distinction on its `type` column, with `TYPE_MOBILE_MONEY = 'mobile_money'` and `TYPE_BANK = 'bank'` (lines 20-22). Because Step 3's config keys are those same two strings, `feeFor($account->type)` works directly.

Add no column, no accessor and no mapping table. If you find yourself writing a `match` over the type, stop — the config keys are the map.

- [ ] **Step 5: Apply the fee on the payout path**

The transfer is fired in **`EventFinanceController::sendPayout()`**, at line 159:

```php
$transfer = $settlementGateway->initiateTransfer($account->recipient_code, $payoutModel->amount, $currency, $reference);
```

That is the only place money leaves the platform. It already sits inside `DB::transaction()` with the payout row held by `lockForUpdate()`, and the surrounding comment explains why — two concurrent sends must not both fire a live transfer. Put the fee logic **inside** that lock; it needs no new guard of its own.

Note that `recordPayout` is called from a different method, `updatePayoutStatus()` at line 245, when a payout is marked paid. The transfer fee must be persisted in `sendPayout` and *read* in `updatePayoutStatus`, because the ledger entry is posted there. Do not try to do both in one place.

Replace the transfer call with:

```php
                $transferFee = $transferFeeSchedule->feeFor($account->type);
                $netToSend = $payoutModel->amount - $transferFee;

                if ($netToSend <= 0) {
                    return response()->json([
                        'message' => 'This balance is smaller than the transfer fee, so it cannot be sent on its own. It will be paid out with the next batch.',
                    ], 422);
                }

                $reference = 'payout_'.$payoutModel->id.'_'.Str::random(8);
                $transfer = $settlementGateway->initiateTransfer($account->recipient_code, $netToSend, $currency, $reference);
```

Inject `TransferFeeSchedule` alongside the existing `SettlementGateway` parameter on the method signature — Laravel resolves both from the container:

```php
    public function sendPayout(
        string $subdomain,
        string $event,
        string $payout,
        SettlementGateway $settlementGateway,
        TransferFeeSchedule $transferFeeSchedule
    ): JsonResponse
```

Then record the fee on the success path, extending the existing `update()` at line 185:

```php
            $payoutModel->update([
                'status' => EventPayout::STATUS_PROCESSING,
                'provider_reference' => $reference,
                'failure_reason' => null,
                'transfer_fee_amount' => $transferFeeSchedule->feeFromProviderResponse($transfer) ?? $transferFee,
                'net_paid_amount' => $netToSend,
            ]);
```

The guard against a payout smaller than the fee is not hypothetical: GHS 8 against a GHS 5 balance would otherwise send Paystack a negative amount. Returning 422 leaves the payout `scheduled` so it batches with the next one, which is the agreed behaviour.

Finally, in `updatePayoutStatus()` at line 245, pass the recorded fee through to the ledger:

```php
                    app(\App\Services\Finance\LedgerService::class)->recordPayout(
                        $eventModel,
                        $payoutModel->amount,
                        'payout_'.$payoutModel->id,
                        $payoutModel,
                        (int) $payoutModel->transfer_fee_amount,
                        false,
                    );
```

Read the existing call's argument list before editing — it was moved inside an idempotency guard during the previous remediation, and it must stay there. Task 5 defines the two new parameters.

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test --compact --filter=TransferFeePassThrough
"$PHP" artisan test --compact --filter=EventFinance
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: record the Paystack transfer fee and pass it through at cost"
```

---

### Task 5: Book the gateway fee so organizer_payable actually clears

`recordTicketSale` debits `gateway_clearing` the full gross and credits `organizer_payable` gross − commission. But Paystack nets its 1.95% out before the money ever lands, so the clearing account is overstated by the collection fee and the payable can never be extinguished by a real payout. This is the fourth of the four issues parked during the previous remediation.

**Files:**
- Modify: `app/Services/Finance/LedgerService.php` (`recordTicketSale` lines 150-202, `recordRefund` lines 204-250, `recordPayout`)
- Modify: `app/Http/Controllers/Tenant/Commerce/WebhookController.php`, `app/Http/Controllers/Public/SettlementWebhookController.php` — pass the actual fee through
- Test: `tests/Feature/Finance/GatewayFeeLedgerTest.php`

**Interfaces:**
- Consumes: `EventRegistration.gateway_fee_amount` from Task 3; `EventPayout.transfer_fee_amount` from Task 4.
- Produces: changed signatures, which every caller must be updated to match in this same commit:
  - `recordTicketSale(Event $event, EventRegistration $registration, int $grossAmount, int $platformFee, string $reference, int $gatewayFee = 0): ?LedgerTransaction`
  - `recordRefund(...same..., int $gatewayFee = 0): ?LedgerTransaction`
  - `recordPayout(Event $event, int $amount, string $reference, ?EventPayout $payout = null, int $transferFee = 0, bool $platformAbsorbsTransferFee = false): LedgerTransaction`

The `$gatewayFee` default of 0 preserves every existing caller's behaviour, so the signature change is not itself a breaking one. Callers that know the real fee must pass it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/GatewayFeeLedgerTest.php`. The decisive assertion is that the payable reaches exactly zero — that is the invariant currently violated.

```php
<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;

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

test('a ticket sale credits the organizer only the cash that actually arrives', function () {
    $tenant = Tenant::factory()->create(['package_id' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'TEST-SALE-1', 195);

    // Gross 10000, Paystack keeps 195, platform keeps 200.
    expect(payableBalance($event))->toBe(9605);
});

test('a payout with a passed-through transfer fee clears the payable to zero', function () {
    $tenant = Tenant::factory()->create(['package_id' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

    $ledger = app(LedgerService::class);
    $ledger->recordTicketSale($event, $registration, 10000, 200, 'TEST-SALE-2', 195);
    $ledger->recordPayout($event, 9605, 'TEST-PAYOUT-2', null, 100, false);

    expect(payableBalance($event))->toBe(0);
});

test('a payout whose transfer fee the platform absorbs still clears the payable', function () {
    $tenant = Tenant::factory()->create(['package_id' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

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
    $tenant = Tenant::factory()->create(['package_id' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'TEST-SALE-4');

    expect(payableBalance($event))->toBe(9800);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
"$PHP" artisan test --compact --filter=GatewayFeeLedger
```

Expected: FAIL — the first test reports a payable of 9800 rather than 9605, because the gateway fee is not booked.

- [ ] **Step 3: Rewrite recordTicketSale**

Replace the entries array in `recordTicketSale` (lines 167-191). The clearing debit becomes the cash that actually lands, and the payable credit drops by the same fee, which keeps the transaction balanced:

```php
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        $cashReceived = $grossAmount - $gatewayFee;
        $netAmount = $cashReceived - $platformFee;

        $entries = [
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $cashReceived,
                'description' => "Ticket payment net of processing fee: {$registration->full_name} ({$registration->email})",
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $netAmount,
                'description' => 'Net ticket revenue payable to organizer',
            ],
        ];
```

Guard the degenerate case before building entries — `postTransaction` rejects a non-positive amount, so a fee larger than the gross would throw deep inside the ledger rather than at the boundary:

```php
        if ($gatewayFee < 0 || $gatewayFee >= $grossAmount) {
            throw new InvalidArgumentException(
                "Gateway fee ({$gatewayFee}) must be non-negative and smaller than the gross amount ({$grossAmount})."
            );
        }
```

Apply the mirror-image change to `recordRefund`: the clearing credit becomes `$grossAmount - $gatewayFee` and the payable debit follows, because Paystack does not return its collection fee on a refund.

- [ ] **Step 4: Rewrite recordPayout**

Find the current `recordPayout` body and replace its entries with the two cases described in Task 4's semantics note:

```php
        $entries = [
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'description' => 'Payout to organizer',
            ],
        ];

        if ($platformAbsorbsTransferFee && $transferFee > 0) {
            $entries[] = [
                'code' => LedgerAccount::CODE_GATEWAY_FEES,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $transferFee,
                'description' => 'Transfer fee absorbed by the platform',
            ];
        }

        $entries[] = [
            'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
            'direction' => LedgerEntry::DIRECTION_CREDIT,
            'amount' => $platformAbsorbsTransferFee ? $amount + $transferFee : $amount,
            'description' => 'Funds transferred out of the gateway balance',
        ];
```

- [ ] **Step 5: Feed the real gateway fee from the webhooks**

Both webhook controllers already receive Paystack's `fees` field — the live GHS 1 purchase recorded gross 100, fee 2, net 96, which proves it arrives. Grep each controller for `fees`, persist it to `registration.gateway_fee_amount`, and pass it as the new sixth argument to `recordTicketSale`. If a webhook payload carries no `fees`, pass `0` rather than guessing from the configured percentage — an estimate booked as fact is worse than a known gap.

- [ ] **Step 6: Prove the tests are load-bearing**

The previous remediation shipped a ledger fix where deleting both new calls left all 755 tests green. Do not repeat that. Delete the `$gatewayFee` argument from the first test's `recordTicketSale` call, confirm that test fails, then restore it:

```bash
"$PHP" artisan test --compact --filter=GatewayFeeLedger
```

Record in the task report that you performed this check and what happened.

- [ ] **Step 7: Run the full finance suite**

```bash
"$PHP" artisan test --compact --filter=Finance
"$PHP" artisan test --compact --filter=LedgerParity
"$PHP" artisan test --compact --filter=Webhook
```

Expected: all PASS.

`LedgerParityTest` will **not** break, and it is worth knowing why before you run it. Its three tests exercise `recordPayout` only, always with the default `transferFee = 0` and `platformAbsorbsTransferFee = false`, which produces byte-for-byte the same two entries as today. Nothing in that file touches `recordTicketSale`.

More importantly, this change moves the two ledgers *into* agreement rather than out of it. `EventLedgerEntry` already carries a `gateway_fee_amount` column and already computes `net_amount = gross − gateway_fee − commission`, which is why the live GHS 1 sale recorded net 96. The double-entry ledger was the one that disagreed, crediting `gross − commission` and ignoring the gateway fee entirely. After this task both report 9605 on a 10000 sale. If a parity test *does* fail, that is a genuine signal — do not adjust the expectation to match the code.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "fix: book the gateway fee so organizer_payable clears on payout"
```

---

### Task 6: Surface the terms where they are set and where they are paid

The overrides exist but nobody can see or set them. Tenant-facing: the fee bearer, which is the organizer's own pricing decision. Platform-facing: the percentage and the cap, which are commercial bargains and must not be self-serve.

**Files:**
- Modify: the event settings screen reached from `app/Http/Controllers/Tenant/EventController.php` — find its Inertia page component and add the bearer control
- Modify: the platform admin tenant-edit screen — add percentage and cap fields
- Modify: the public checkout summary — show the fee line when the attendee bears it
- Test: `tests/Feature/Tenant/EventFeeSettingsTest.php`

**Interfaces:**
- Consumes: `effective_platform_fee_percentage`, `effective_platform_fee_cap_amount`, `effective_fee_bearer` from Task 2 Step 5; `FeeBreakdown::toArray()` from Task 2.
- Produces: no new interfaces.

**Authorization split — this is the security-relevant part of the task:**

| Setting | Who may change it |
|---|---|
| `events.fee_bearer` | tenant admin (their pricing choice) |
| `tenants.fee_bearer` | tenant admin (their default) |
| `events.platform_fee_percentage` | application superadmin only |
| `tenants.platform_fee_percentage` | application superadmin only |
| `events.platform_fee_cap_amount` | application superadmin only |
| `tenants.platform_fee_cap_amount` | application superadmin only |

"Application superadmin" is a specific, existing thing — the `access-superadmin-dashboard` gate defined at `app/Providers/AuthServiceProvider.php:48`. It requires the global `Superadmin` role with a **null `tenant_id`**, so a tenant-scoped role of any name cannot satisfy it. Use that gate verbatim:

```php
Gate::authorize('access-superadmin-dashboard');
```

Do not introduce a permission, a policy method, or a role check of your own. A tenant owner on an Enterprise package must not be able to set their own commission, and the null-`tenant_id` condition in that gate is precisely what prevents it.

A tenant who can set their own commission can set it to zero. `EventController::validateEvent()` must **not** list the percentage or cap keys at all — omit them from the rules rather than validating and then discarding them. The controller assigns from `$validated`, so a key absent from the rules cannot reach `update()`; that is the whole protection, and it is why the tests in Step 1 assert the persisted value rather than the response status.

- [ ] **Step 1: Write the failing test**

The event update route is `PUT /events/{event}` (`tenant.events.update`, from the `Route::resource` at `routes/subdomain.php:125`). Validation is **not** a Form Request — it is the private `EventController::validateEvent()` at line 296. That is where the `fee_bearer` rule goes, and it is also why the percentage and cap must simply never appear there.

```php
<?php

declare(strict_types=1);

use App\Models\Event;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * The update route validates the whole event, so every request needs a full
 * valid payload. This builds one and lets each test override a single key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventUpdatePayload(Event $event, array $overrides = []): array
{
    return array_merge([
        'name' => $event->name,
        'description' => 'A great event',
        'status' => 'published',
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => '123 Main St',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], $overrides);
}

test('a tenant admin can move the platform fee onto the attendee', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => null,
        'currency' => 'GHS',
    ]);

    $this->actingAs($user)->put(
        "http://{$host}/events/{$event->id}",
        eventUpdatePayload($event, ['fee_bearer' => 'attendee']),
        ['HTTP_HOST' => $host]
    );

    expect($event->refresh()->fee_bearer)->toBe('attendee');
});

test('a tenant admin cannot set their own commission percentage', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'platform_fee_percentage' => 2.0,
        'currency' => 'GHS',
    ]);

    $this->actingAs($user)->put(
        "http://{$host}/events/{$event->id}",
        eventUpdatePayload($event, ['platform_fee_percentage' => 0]),
        ['HTTP_HOST' => $host]
    );

    // A 302 proves nothing here — assert the commission is untouched.
    expect((float) $event->refresh()->platform_fee_percentage)->toEqual(2.0);
});

test('a tenant admin cannot set their own commission cap', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'platform_fee_cap_amount' => 2000,
        'currency' => 'GHS',
    ]);

    $this->actingAs($user)->put(
        "http://{$host}/events/{$event->id}",
        eventUpdatePayload($event, ['platform_fee_cap_amount' => 1]),
        ['HTTP_HOST' => $host]
    );

    expect($event->refresh()->platform_fee_cap_amount)->toBe(2000);
});

test('an invalid fee bearer is rejected', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $this->actingAs($user)->put(
        "http://{$host}/events/{$event->id}",
        eventUpdatePayload($event, ['fee_bearer' => 'somebody_else']),
        ['HTTP_HOST' => $host]
    )->assertSessionHasErrors('fee_bearer');
});
```

The second and third tests are the security gate. They pass only because `validateEvent()` never lists those keys, so `$validated` cannot carry them into `update()` — which is exactly the protection being asserted. If a future refactor switches the controller to `$request->all()`, these two tests are what catch it.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
"$PHP" artisan test --compact --filter=EventFeeSettings
```

- [ ] **Step 3: Add the tenant-facing bearer control**

Locate the event settings Inertia page:

```bash
grep -rn "Inertia::render" app/Http/Controllers/Tenant/EventController.php
```

Add the rule to `EventController::validateEvent()` (line 296), alongside the existing `visibility` rule — not to a Form Request; this controller does not use one.

Add a two-option control — "I absorb the platform fee" / "Add the platform fee to the ticket price" — wired to `fee_bearer`. Use the app's existing form components; do not introduce new styling. Validation rule:

```php
            'fee_bearer' => ['nullable', 'in:organizer,attendee'],
```

Show the resulting split beneath the control using the values already passed from Task 2 Step 5, so the organizer sees what a buyer will pay before saving.

- [ ] **Step 4: Add the admin-only percentage and cap fields**

Find the platform admin tenant editor:

```bash
grep -rln "platform_fee_percentage" app/Http/Controllers/Admin resources/views/admin 2>/dev/null
```

Add both fields there, gated by the same superadmin check the surrounding fields already use — copy that check, do not invent one. Label them plainly: "Commission %" and "Commission cap (GHS)", with help text saying a blank value inherits the package default and `0` waives the commission entirely. The cap field takes cedis in the UI and stores pesewas; convert at the boundary.

- [ ] **Step 5: Show the fee on the checkout summary**

Where the public checkout renders the order total, add a fee line when `bearer` is `attendee`:

```
Ticket                GHS 100.00
Service fee             GHS 2.00
Total                 GHS 102.00
```

When the organizer bears it, show nothing — the buyer pays the ticket price and the fee is none of their concern.

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test --compact --filter=EventFeeSettings
npm run build
```

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: expose the fee bearer to tenants and the commission terms to platform admins"
```

---

### Task 7: Collapse the two ledgers onto a single write path

This is the structural fix. Task 5 makes the two ledgers *agree*; this task makes it impossible for them to disagree again.

**The problem.** Six call sites write `EventLedgerEntry::create(...)` by hand, and nine call `LedgerService`. Most sites do both, side by side, held together by nothing but the author remembering. That discipline has already failed twice: `ReconcilePayoutSchedulesCommand.php:156` calls `recordPayout` and writes **no** `EventLedgerEntry`, and before the previous remediation three sites wrote the old row and called no service at all.

**What the two ledgers actually are.** They are not duplicates, which is why deleting one is the wrong answer:

- `EventLedgerEntry` is a flat settlement-statement line — one row per money movement carrying `gross_amount`, `gateway_fee_amount`, `commission_amount`, `net_amount`. `EventFinanceController::exportSettlementStatement()` (line 303) streams it straight to CSV for organizers. Rebuilding that CSV from double-entry pairs is possible but pointless.
- `LedgerTransaction` / `LedgerEntry` is a real double-entry general ledger that supports a trial balance.

Both are worth having. Only one should be *written by hand*.

**The fix.** `LedgerService` becomes the sole writer of both. Move the `EventLedgerEntry::create(...)` inside `recordTicketSale`, `recordRefund` and `recordPayout`, then delete all six scattered creates. One call, two ledgers, no drift possible.

**Files:**
- Modify: `app/Services/Finance/LedgerService.php` — all three record methods
- Modify: `app/Http/Controllers/Tenant/Commerce/WebhookController.php:174` — delete the create
- Modify: `app/Http/Controllers/Public/SettlementWebhookController.php` — delete **both** creates
- Modify: `app/Http/Controllers/Tenant/EventRegistrationController.php` — delete the create
- Modify: `app/Http/Controllers/Tenant/EventFinanceController.php` — delete the create
- Modify: `app/Console/Commands/Events/ReconcileStuckPayouts.php` — delete the create
- Test: `tests/Feature/Finance/LedgerSingleWritePathTest.php`

**Interfaces:**
- Consumes: the Task 5 signatures, which already carry every field the old row needs — `$grossAmount`, `$platformFee`, `$gatewayFee`, `$reference`.
- Produces: `recordTicketSale` / `recordRefund` / `recordPayout` gain two optional parameters for the columns only the old row has: `string $provider = 'paystack'` and `?string $providerReference = null` (defaults to `$reference`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('one recordTicketSale call writes both ledgers and they agree', function () {
    $tenant = Tenant::factory()->create(['slug' => 'single-write', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'SALE-REF-1', 195);

    $old = EventLedgerEntry::where('registration_id', $registration->id)
        ->where('type', EventLedgerEntry::TYPE_CHARGE)
        ->sole();

    expect($old->gross_amount)->toBe(10000)
        ->and($old->gateway_fee_amount)->toBe(195)
        ->and($old->commission_amount)->toBe(200)
        ->and($old->net_amount)->toBe(9605);

    // The same figure, arrived at independently through the double-entry side.
    expect(payableBalanceFor($event))->toBe($old->net_amount);
});

test('a sale writes exactly one old-ledger row, not two', function () {
    $tenant = Tenant::factory()->create(['slug' => 'single-write-2', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'SALE-REF-2', 195);

    // If a caller's hand-written create survived the deletion, this is 2.
    expect(EventLedgerEntry::where('registration_id', $registration->id)->count())->toBe(1);
});

test('no controller or command writes EventLedgerEntry directly any more', function () {
    $offenders = [];

    foreach (['app/Http/Controllers', 'app/Console/Commands', 'app/Jobs'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains((string) file_get_contents($file->getPathname()), 'EventLedgerEntry::create')) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});
```

The third test is the one that keeps this fix alive. Without it, the next person to add a settlement path writes the pair by hand again and nothing complains. Define `payableBalanceFor()` in this file — `payableBalance()` is already taken by Task 5's file, and Pest's global function namespace is shared.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
"$PHP" artisan test --compact --filter=LedgerSingleWritePath
```

Expected: the first two fail (no old row is written by the service), the third fails listing six offending files.

- [ ] **Step 3: Move the write into recordTicketSale**

Inside `recordTicketSale`, after the `postTransaction` call and within the same `DB::connection('landlord')->transaction()` boundary — if the double-entry post throws, the flat row must not survive:

```php
        EventLedgerEntry::create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'registration_id' => $registration->id,
            'gross_amount' => $grossAmount,
            'gateway_fee_amount' => $gatewayFee,
            'commission_amount' => $platformFee,
            'net_amount' => $grossAmount - $gatewayFee - $platformFee,
            'currency' => $event->currency ?: 'GHS',
            'provider' => $provider,
            'provider_reference' => $providerReference ?? $reference,
        ]);
```

`net_amount` here and the `organizer_payable` credit from Task 5 are the same arithmetic written twice. That is deliberate and is what the first test asserts — the two ledgers must agree by construction, not by coincidence.

Do the equivalent in `recordRefund` (`TYPE_REFUND`) and `recordPayout` (`TYPE_PAYOUT`, with `payout_id` and `amount` as `gross_amount`). Copy the exact field values from the call sites you are about to delete rather than inferring them; `ReconcileStuckPayouts.php` and `EventFinanceController.php` each build a payout row slightly differently, and the union of what they set is what the service must now produce.

- [ ] **Step 4: Delete the six hand-written creates**

```bash
grep -rn "EventLedgerEntry::create" app
```

Remove each one and its now-unused `use App\Models\EventLedgerEntry;` import where nothing else in the file needs it. `SettlementWebhookController` has **two** — do not stop at the first.

Where a call site currently passes a value the service cannot derive — a provider name that is not `paystack`, a provider reference distinct from the ledger reference — pass it through the new optional parameters rather than leaving the create in place.

- [ ] **Step 5: Close the gap this task exists to prove**

`ReconcilePayoutSchedulesCommand.php:156` calls `recordPayout` and writes no old-ledger row today, so a payout reconciled by that command is missing from every settlement statement. Once the service writes both, that gap closes with no change to the command. Add a test that proves it:

```php
test('a payout reconciled by the schedule command reaches the settlement statement', function () {
    // Drive ReconcilePayoutSchedulesCommand end to end and assert an
    // EventLedgerEntry of TYPE_PAYOUT now exists for the payout. Model the
    // setup on the payouts:reconcile test in LedgerParityTest.
});
```

This is the concrete bug the consolidation fixes; ship the task with it proven, not merely asserted.

- [ ] **Step 6: Run the full suite**

```bash
"$PHP" artisan test --compact
```

Every existing finance test must stay green. They were written against call sites that wrote both ledgers; after this task the service writes both, so the observable outcome is unchanged. Any test that now sees two rows where it expected one means a create survived Step 4.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "refactor: LedgerService becomes the only writer of both ledgers"
```

---

### Task 8: Run the whole suite and reconcile against production reality

**Files:** none created; this task is verification.

- [ ] **Step 1: Full suite**

```bash
"$PHP" artisan test --compact
```

The baseline before this plan was **759 tests, 758 passing, 1 skipped, 0 failing**. Anything below that is a regression this plan introduced. Report the exact counts.

- [ ] **Step 2: Check the live figures still reconcile**

The production GHS 1 purchase recorded gross 100, gateway fee 2, commission 2, net 96 in `EventLedgerEntry`. Before Task 5 the double-entry ledger credited the organizer 98 for that same sale — it ignored the gateway fee. After Task 5 it credits 96, matching the old ledger exactly.

Confirm on production data: read the `EventLedgerEntry` row for registration `EVT-FDQ4-722` and compare `net_amount` against the `organizer_payable` balance for that event. They must be equal. A remaining difference means Task 5 is incomplete, not that the comparison is wrong.

- [ ] **Step 3: Confirm no estimate is shown as fact**

```bash
grep -rn "gateway_fee_percentage" app resources
```

Every use must be labelled an estimate in the UI. The only authoritative gateway fee is the one the webhook reports.

- [ ] **Step 4: Commit any fixes and report**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "test: reconcile the fee model against the live production figures"
```

---

## Out of Scope — follow-on plans

These were designed alongside the fee model but are separate subsystems, each producing working software on its own. They get their own plans.

**A. Notification credits.** Email allowances per package (free 200, starter 1000, growth 10000, enterprise unlimited), transactional email exempt from metering, overage billing allowed, SMS and WhatsApp priced in volume from the Omnichannel application at AWS cost plus margin and paid for before activation. The metering service and `TenantNotificationSetting` already exist and were corrected in the previous remediation; this plan is about purchasable credit packs and the balance display.

**B. Subscription lifecycle and material retention.** The agreed ladder: on lapse the account locks to read-only with **no material downloads**, weekly reminders for 30 days, then inactive with no new events, then deletion of cost-incurring files at month 3. No penalties and no back-billed hosting — R2's zero egress makes a 1GB conference archive cost about GHS 0.23 a month, so the lock is the lever and cost recovery is not worth the friction. This plan must also **build `App\Services\Compliance\RetentionPolicyService` from scratch** — it does not exist, yet `app/Console/Kernel.php:46` schedules `compliance:purge-expired` daily and `app/Jobs/PurgeExpiredDataJob.php` imports it. That command is not registered either. The schedule entry has been failing silently since it was written.

**C. Recurring subscription payments.** Paystack card authorizations are reusable via `charge_authorization`, so card-backed auto-renewal is feasible. **Mobile money authorizations in Ghana are generally not reusable** — verify this against Paystack's current documentation before designing, because if it holds, requiring a card on file excludes most of the Ghanaian market and the fallback must be an invoice-plus-payment-link renewal. No subscription has ever been paid end to end on this platform, so that path is unproven regardless.
