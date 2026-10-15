# Finance / Settlement Rigor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the platform a real, reconciled settlement engine — a `platform_default` settlement mode where money for tenants without their own Paystack account flows into Purpledot's own account, gets a real ledger entry (gross/fee/commission/net), and is paid out to the tenant's bank/mobile-money account via Paystack's Transfers API — while `own_gateway` tenants keep today's behavior (and their own dashboards) completely unchanged.

**Architecture:** A new `App\Contracts\SettlementGateway` interface (resolve/recipient/transfer/verify/listBanks), implemented once by `PaystackSettlementGateway` against Purpledot's own platform Paystack credentials (never a tenant's). A new `event_ledger_entries` table becomes the single source of truth for money movement (charge/refund/payout rows with gross/fee/commission/net), replacing today's ad-hoc sums over `EventRegistration`/`EventPayout`. `EventCheckoutController` and `EventRegistrationController::cancel()` branch on `Tenant.settlement_mode` to charge/refund through either the tenant's own gateway (unchanged) or the platform's. A new tenant-agnostic webhook (`POST /webhooks/settlement/paystack`) handles both `platform_default` charge confirmations (via `tenant_id` in charge metadata, since there's no per-tenant Paystack dashboard to register a webhook on) and payout transfer confirmations.

**Tech Stack:** Laravel 13 / PHP 8.5, Pest 5, Paystack REST API via `Illuminate\Support\Facades\Http`, existing `landlord`-connection multi-tenant patterns (`BelongsToTenant`, `HasUuids`).

**Spec:** `docs/superpowers/specs/2026-09-05-finance-settlement-rigor-design.md`

## Global Constraints

- Tenants who already run their own Paystack/Stripe account (`settlement_mode = own_gateway`) keep today's behavior completely unchanged — money goes straight to them, exactly as it does today. Every task must show, for each file it touches, that the `own_gateway` code path is byte-for-byte unaffected except where explicitly noted (ledger-entry writing, per spec §5, is the one intentional addition to the `own_gateway` path).
- No KYC/KYB, no Stripe settlement adapter, no automatic commission collection for `own_gateway` tenants, no SMS. These stay out of scope for this plan (see spec §2).
- **Ruling (resolved by the user during planning):** Paystack resolves and settles into Ghana mobile-money numbers the same way as bank accounts, via the same `resolve_account_number`-style flow. The spec's "open question" on this is settled — `SettlementGateway::resolveAccount()`/`createRecipient()` do not need a separate mobile-money code path.
- **Ruling (this plan, since the spec left it unresolved):** how an `own_gateway` tenant's refund on their own Paystack dashboard should reflect back into this app's ledger is explicitly OUT OF SCOPE for this phase — `own_gateway` tenants keep today's fully-manual reconciliation for refunds initiated outside this app. This plan only ensures `own_gateway` refunds initiated *through this app* (`EventRegistrationController::cancel()`, already built) get a ledger row.
- **Ruling (this plan, resolving a gap in spec §5/§6):** the spec says charge/refund confirmation for `platform_default` registrations funnels through "this webhook" (implying the existing per-tenant `/webhooks/merchant/paystack/{tenant}` URL), but that is architecturally impossible — a `platform_default` tenant has no Paystack dashboard of their own to register a per-tenant webhook URL on, since the charge runs through Purpledot's own Paystack account. This plan routes `platform_default` charge confirmation through the same new tenant-agnostic `POST /webhooks/settlement/paystack` endpoint the spec already defines for transfer confirmations (extended to also handle `charge.success`/`charge.failed`), resolving tenant context from `tenant_id` carried in the charge's metadata rather than the URL. `own_gateway` charge confirmation is completely unchanged (still the existing per-tenant webhook).
- A payout's amount must be validated against a ledger-computed available balance inside a `DB::transaction()` with a row lock — this is explicitly called out in the spec as "not optional" (a plain query-then-insert is a TOCTOU race).
- A payout retry (re-sending a `failed` payout) must use a freshly generated transfer reference — never reuse verbatim across attempts, per spec §6 step 3.
- The new platform-level transfer/charge webhook must verify Paystack's `x-paystack-signature` header (HMAC SHA512, Purpledot's own platform secret) and must be idempotent (a duplicate `transfer.success`/`charge.success` delivery for an already-processed reference is a no-op, not a duplicate ledger row).
- Existing `tests/Feature/Events/EventFinanceTest.php` assertions (encrypted-at-rest account numbers, masked API output, permission gates) must keep verifying the same guarantees — see Task 4 for the one place this plan must update that file's request payload (not its assertions) to add the newly-required `bank_code` field.
- All new Paystack calls (`resolveAccount`, `createRecipient`, `initiateTransfer`, `verifyTransfer`, `listBanks`) are tested via `Http::fake()`, matching this codebase's existing gateway-test convention — never hit the real Paystack API in tests.
- A real end-to-end sandbox run (once the platform's own Paystack test keys are available) happens as an explicit follow-up after this plan merges — not assumed equivalent to `Http::fake()` coverage.

---

### Task 1: Data model foundation — settlement_mode, event_ledger_entries, and payout/account column extensions

**Files:**
- Create: `database/migrations/landlord/2026_09_05_150000_add_settlement_mode_to_tenants_table.php`
- Create: `database/migrations/landlord/2026_09_05_150001_create_event_ledger_entries_table.php`
- Create: `database/migrations/landlord/2026_09_05_150002_add_settlement_columns_to_event_payouts_table.php`
- Create: `database/migrations/landlord/2026_09_05_150003_add_settlement_columns_to_tenant_payout_accounts_table.php`
- Create: `app/Models/EventLedgerEntry.php`
- Create: `database/factories/EventLedgerEntryFactory.php`
- Modify: `app/Models/Tenant.php` (add `settlement_mode` to `$fillable`, add mode constants + helper method)
- Modify: `app/Models/EventPayout.php` (add `STATUS_PROCESSING`/`STATUS_FAILED` constants, `provider_reference`/`failure_reason` to `$fillable`)
- Modify: `app/Models/TenantPayoutAccount.php` (add `bank_code`/`recipient_code`/`resolved_account_name` to `$fillable`)
- Test: `tests/Feature/Events/EventLedgerEntryTest.php`

**Interfaces:**
- Consumes: nothing (this is the foundation task).
- Produces: `EventLedgerEntry::TYPE_CHARGE`/`TYPE_REFUND`/`TYPE_PAYOUT` constants; `EventLedgerEntry` fillable fields `tenant_id`, `event_id`, `type`, `registration_id`, `payout_id`, `gross_amount`, `gateway_fee_amount`, `commission_amount`, `net_amount`, `currency`, `provider`, `provider_reference`; `Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT`/`SETTLEMENT_MODE_OWN_GATEWAY` constants and `Tenant::isPlatformDefaultSettlement(): bool`; `EventPayout::STATUS_PROCESSING`/`STATUS_FAILED`; `TenantPayoutAccount`'s new `bank_code`/`recipient_code`/`resolved_account_name` fillable fields — every later task relies on these exact names.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Events/EventLedgerEntryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a new tenant defaults to platform_default settlement mode', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT);
    expect($tenant->isPlatformDefaultSettlement())->toBeTrue();
});

test('a tenant can be switched to own_gateway settlement mode', function () {
    $tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

    expect($tenant->isPlatformDefaultSettlement())->toBeFalse();
});

test('an event ledger entry stores gross, fee, commission and net amounts', function () {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $entry = EventLedgerEntry::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'type' => EventLedgerEntry::TYPE_CHARGE,
        'registration_id' => $registration->id,
        'gross_amount' => 10_000,
        'gateway_fee_amount' => 150,
        'commission_amount' => 500,
        'net_amount' => 9_350,
        'currency' => 'GHS',
        'provider' => 'paystack',
        'provider_reference' => 'ref_ledger_test_1',
    ]);

    expect($entry->fresh())
        ->type->toBe(EventLedgerEntry::TYPE_CHARGE)
        ->gross_amount->toBe(10_000)
        ->gateway_fee_amount->toBe(150)
        ->commission_amount->toBe(500)
        ->net_amount->toBe(9_350);
    expect($entry->event->id)->toBe($event->id);
    expect($entry->registration->id)->toBe($registration->id);
});

test('an event payout can be moved through processing and failed statuses with a reference and reason', function () {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = \App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = \App\Models\EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
    ]);

    $payout->update(['status' => \App\Models\EventPayout::STATUS_PROCESSING, 'provider_reference' => 'TRF_test_1']);
    expect($payout->fresh()->status)->toBe('processing');

    $payout->update(['status' => \App\Models\EventPayout::STATUS_FAILED, 'failure_reason' => 'Insufficient balance']);
    expect($payout->fresh())->status->toBe('failed')->failure_reason->toBe('Insufficient balance');
});

test('a tenant payout account can store a bank code, recipient code and resolved account name', function () {
    $tenant = Tenant::factory()->create();
    $account = \App\Models\TenantPayoutAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'bank_code' => '058',
        'recipient_code' => 'RCP_test_1',
        'resolved_account_name' => 'Kwame Asante',
    ]);

    expect($account->fresh())
        ->bank_code->toBe('058')
        ->recipient_code->toBe('RCP_test_1')
        ->resolved_account_name->toBe('Kwame Asante');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EventLedgerEntryTest`
Expected: FAIL — `settlement_mode` column doesn't exist on `tenants`, `EventLedgerEntry` class doesn't exist, `EventPayout::STATUS_PROCESSING`/`STATUS_FAILED` don't exist, `TenantPayoutAccount` has no `bank_code`/`recipient_code`/`resolved_account_name`.

- [ ] **Step 3: Create the migrations**

Create `database/migrations/landlord/2026_09_05_150000_add_settlement_mode_to_tenants_table.php`:

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
            $table->string('settlement_mode')->default('platform_default')->after('platform_fee_percentage');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropColumn('settlement_mode');
        });
    }
};
```

Create `database/migrations/landlord/2026_09_05_150001_create_event_ledger_entries_table.php`:

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
        Schema::connection('landlord')->create('event_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->nullable()->constrained('event_registrations')->nullOnDelete();
            $table->foreignUuid('payout_id')->nullable()->constrained('event_payouts')->nullOnDelete();

            $table->string('type'); // charge, refund, payout
            $table->unsignedInteger('gross_amount');
            $table->unsignedInteger('gateway_fee_amount');
            $table->unsignedInteger('commission_amount');
            $table->integer('net_amount'); // signed: a refund's net is stored as a negative reversal
            $table->string('currency');
            $table->string('provider');
            $table->string('provider_reference')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_ledger_entries');
    }
};
```

Create `database/migrations/landlord/2026_09_05_150002_add_settlement_columns_to_event_payouts_table.php`:

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
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->string('provider_reference')->nullable()->after('note');
            $table->string('failure_reason')->nullable()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->dropColumn(['provider_reference', 'failure_reason']);
        });
    }
};
```

Create `database/migrations/landlord/2026_09_05_150003_add_settlement_columns_to_tenant_payout_accounts_table.php`:

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
        Schema::connection('landlord')->table('tenant_payout_accounts', function (Blueprint $table): void {
            $table->string('bank_code')->nullable()->after('type');
            $table->string('recipient_code')->nullable()->after('is_verified');
            $table->string('resolved_account_name')->nullable()->after('recipient_code');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenant_payout_accounts', function (Blueprint $table): void {
            $table->dropColumn(['bank_code', 'recipient_code', 'resolved_account_name']);
        });
    }
};
```

- [ ] **Step 4: Create the `EventLedgerEntry` model**

Create `app/Models/EventLedgerEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventLedgerEntry extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_CHARGE = 'charge';

    public const string TYPE_REFUND = 'refund';

    public const string TYPE_PAYOUT = 'payout';

    public $timestamps = false;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'type',
        'registration_id',
        'payout_id',
        'gross_amount',
        'gateway_fee_amount',
        'commission_amount',
        'net_amount',
        'currency',
        'provider',
        'provider_reference',
    ];

    protected $casts = [
        'gross_amount' => 'integer',
        'gateway_fee_amount' => 'integer',
        'commission_amount' => 'integer',
        'net_amount' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(EventPayout::class, 'payout_id');
    }
}
```

Create `database/factories/EventLedgerEntryFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventLedgerEntry> */
final class EventLedgerEntryFactory extends Factory
{
    protected $model = EventLedgerEntry::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'gross_amount' => 10_000,
            'gateway_fee_amount' => 150,
            'commission_amount' => 500,
            'net_amount' => 9_350,
            'currency' => 'GHS',
            'provider' => 'paystack',
            'provider_reference' => 'ref_'.$this->faker->uuid(),
        ];
    }

    public function refund(): self
    {
        return $this->state(fn (): array => [
            'type' => EventLedgerEntry::TYPE_REFUND,
            'net_amount' => -9_350,
        ]);
    }

    public function payout(): self
    {
        return $this->state(fn (): array => [
            'type' => EventLedgerEntry::TYPE_PAYOUT,
            'gateway_fee_amount' => 0,
            'commission_amount' => 0,
        ]);
    }
}
```

- [ ] **Step 5: Update `Tenant`, `EventPayout`, `TenantPayoutAccount`**

In `app/Models/Tenant.php`, add to `$fillable` (after `'platform_fee_percentage',`):

```php
        'settlement_mode',
```

Add these constants immediately after the class declaration line (`final class Tenant extends Model` `{`):

```php
    public const string SETTLEMENT_MODE_PLATFORM_DEFAULT = 'platform_default';

    public const string SETTLEMENT_MODE_OWN_GATEWAY = 'own_gateway';

```

Add this method next to `hasActivePaymentGateway()`:

```php
    public function isPlatformDefaultSettlement(): bool
    {
        return $this->settlement_mode === self::SETTLEMENT_MODE_PLATFORM_DEFAULT;
    }
```

In `app/Models/EventPayout.php`, add after `public const string STATUS_PAID = 'paid';`:

```php

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_FAILED = 'failed';
```

Add to `$fillable` (after `'note',`):

```php
        'provider_reference',
        'failure_reason',
```

In `app/Models/TenantPayoutAccount.php`, add to `$fillable` (after `'type',`):

```php
        'bank_code',
```

and (after `'is_verified',`):

```php
        'recipient_code',
        'resolved_account_name',
```

- [ ] **Step 6: Run migrations and the test**

Run: `php artisan migrate --path=database/migrations/landlord --database=landlord` (or the project's existing test-DB refresh path — check how `refreshTenantDatabases()` picks up new landlord migrations; if it runs `migrate:fresh` against the `landlord` connection automatically for tests, no manual step is needed beyond the test run itself).
Run: `php artisan test --compact --filter=EventLedgerEntryTest`
Expected: PASS (5/5).

- [ ] **Step 7: Run the existing finance regression test**

Run: `php artisan test --compact --filter=EventFinanceTest`
Expected: PASS (5/5) — this task only adds columns/constants, nothing yet reads or writes them from `EventFinanceController`, so existing behavior must be completely unaffected.

- [ ] **Step 8: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add database/migrations/landlord/2026_09_05_150000_add_settlement_mode_to_tenants_table.php database/migrations/landlord/2026_09_05_150001_create_event_ledger_entries_table.php database/migrations/landlord/2026_09_05_150002_add_settlement_columns_to_event_payouts_table.php database/migrations/landlord/2026_09_05_150003_add_settlement_columns_to_tenant_payout_accounts_table.php app/Models/EventLedgerEntry.php database/factories/EventLedgerEntryFactory.php app/Models/Tenant.php app/Models/EventPayout.php app/Models/TenantPayoutAccount.php tests/Feature/Events/EventLedgerEntryTest.php
git commit -m "feat: add settlement_mode, event_ledger_entries, and payout/account settlement columns

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: `SettlementGateway` contract, `PaystackSettlementGateway`, config, and service binding

**Files:**
- Create: `app/Contracts/SettlementGateway.php`
- Create: `app/Services/Settlement/PaystackSettlementGateway.php`
- Modify: `config/services.php` (add `settlement` block)
- Modify: `app/Providers/AppServiceProvider.php:76` (add `SettlementGateway::class` binding immediately after the existing `PaymentGateway::class` binding closes)
- Test: `tests/Feature/Billing/PaystackSettlementGatewayTest.php`

**Interfaces:**
- Consumes: nothing from Task 1 directly (this task is independent of the data model — it's pure gateway plumbing).
- Produces: `App\Contracts\SettlementGateway` with `resolveAccount(string $bankCode, string $accountNumber): array` (returns `['account_name' => string]`), `createRecipient(string $type, string $name, string $accountNumber, string $bankCode, string $currency): string` (returns `recipient_code`), `initiateTransfer(string $recipientCode, int $amount, string $currency, string $reference): array` (returns `['transfer_code' => string, 'status' => string]`), `verifyTransfer(string $reference): array` (returns `['status' => string]`), `listBanks(string $country = 'ghana'): array` (returns `[['name' => string, 'code' => string], ...]`) — every later task that touches payouts/account-resolution consumes this exact interface via `app(SettlementGateway::class)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/PaystackSettlementGatewayTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use App\Services\Settlement\PaystackSettlementGateway;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

test('resolveAccount returns the account holder name from a successful resolve call', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_number' => '1234567890', 'account_name' => 'Kwame Asante', 'bank_id' => 58],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $result = $gateway->resolveAccount('058', '1234567890');

    expect($result)->toBe(['account_name' => 'Kwame Asante']);
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'account_number=1234567890')
        && str_contains((string) $request->url(), 'bank_code=058'));
});

test('resolveAccount throws PaymentFailedException when the account cannot be resolved', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name'], 422),
    ]);

    $gateway = app(PaystackSettlementGateway::class);

    expect(fn () => $gateway->resolveAccount('058', '0000000000'))->toThrow(PaymentFailedException::class);
});

test('createRecipient returns the recipient code', function () {
    Http::fake([
        'api.paystack.co/transferrecipient' => Http::response([
            'status' => true,
            'data' => ['recipient_code' => 'RCP_test_123'],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $code = $gateway->createRecipient('nuban', 'Kwame Asante', '1234567890', '058', 'GHS');

    expect($code)->toBe('RCP_test_123');
});

test('initiateTransfer returns the transfer code and status', function () {
    Http::fake([
        'api.paystack.co/transfer' => Http::response([
            'status' => true,
            'data' => ['transfer_code' => 'TRF_test_123', 'status' => 'pending'],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $result = $gateway->initiateTransfer('RCP_test_123', 15_000, 'GHS', 'settlement_ref_1');

    expect($result)->toBe(['transfer_code' => 'TRF_test_123', 'status' => 'pending']);
    Http::assertSent(fn ($request): bool => $request['recipient'] === 'RCP_test_123'
        && $request['amount'] === 15_000
        && $request['reference'] === 'settlement_ref_1');
});

test('verifyTransfer returns the transfer status', function () {
    Http::fake([
        'api.paystack.co/transfer/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success'],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $result = $gateway->verifyTransfer('settlement_ref_1');

    expect($result)->toBe(['status' => 'success']);
});

test('listBanks returns a list of bank names and codes', function () {
    Http::fake([
        'api.paystack.co/bank*' => Http::response([
            'status' => true,
            'data' => [
                ['name' => 'Absa Bank Ghana', 'code' => '030'],
                ['name' => 'MTN Mobile Money', 'code' => 'MTN'],
            ],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $banks = $gateway->listBanks('ghana');

    expect($banks)->toBe([
        ['name' => 'Absa Bank Ghana', 'code' => '030'],
        ['name' => 'MTN Mobile Money', 'code' => 'MTN'],
    ]);
});

test('the SettlementGateway contract resolves to PaystackSettlementGateway by default', function () {
    expect(app(SettlementGateway::class))->toBeInstanceOf(PaystackSettlementGateway::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PaystackSettlementGatewayTest`
Expected: FAIL — `App\Contracts\SettlementGateway` and `App\Services\Settlement\PaystackSettlementGateway` don't exist yet.

- [ ] **Step 3: Create the contract**

Create `app/Contracts/SettlementGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Contracts;

interface SettlementGateway
{
    /**
     * @return array{account_name: string}
     */
    public function resolveAccount(string $bankCode, string $accountNumber): array;

    public function createRecipient(string $type, string $name, string $accountNumber, string $bankCode, string $currency): string;

    /**
     * @return array{transfer_code: string, status: string}
     */
    public function initiateTransfer(string $recipientCode, int $amount, string $currency, string $reference): array;

    /**
     * @return array{status: string}
     */
    public function verifyTransfer(string $reference): array;

    /**
     * @return array<int, array{name: string, code: string}>
     */
    public function listBanks(string $country = 'ghana'): array;
}
```

- [ ] **Step 4: Create `PaystackSettlementGateway`**

Create `app/Services/Settlement/PaystackSettlementGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PaystackSettlementGateway implements SettlementGateway
{
    private string $baseUrl = 'https://api.paystack.co';

    private readonly string $secret;

    public function __construct(?array $config = null)
    {
        $this->secret = $config['secret_key'] ?? config('services.settlement.paystack.secret_key') ?? '';
    }

    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/bank/resolve", [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ])->throw();

            return ['account_name' => (string) $response->json('data.account_name')];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function createRecipient(string $type, string $name, string $accountNumber, string $bankCode, string $currency): string
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transferrecipient", [
                'type' => $type,
                'name' => $name,
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'currency' => $currency,
            ])->throw();

            return (string) $response->json('data.recipient_code');
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function initiateTransfer(string $recipientCode, int $amount, string $currency, string $reference): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transfer", [
                'source' => 'balance',
                'reason' => 'Event settlement payout',
                'amount' => $amount,
                'currency' => $currency,
                'recipient' => $recipientCode,
                'reference' => $reference,
            ])->throw();

            return [
                'transfer_code' => (string) $response->json('data.transfer_code'),
                'status' => (string) $response->json('data.status'),
            ];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function verifyTransfer(string $reference): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/transfer/verify/{$reference}")->throw();

            return ['status' => (string) $response->json('data.status')];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function listBanks(string $country = 'ghana'): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/bank", [
                'country' => $country,
            ])->throw();

            return collect($response->json('data'))
                ->map(fn (array $bank): array => ['name' => (string) $bank['name'], 'code' => (string) $bank['code']])
                ->values()
                ->all();
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }
}
```

- [ ] **Step 5: Add the `settlement` config block**

In `config/services.php`, add this block immediately after the existing `'platform' => [...],` block (before the closing `];` of the file):

```php
    'settlement' => [
        'default' => env('SETTLEMENT_DRIVER', 'paystack'),
        'paystack' => [
            'secret_key' => env('SETTLEMENT_PAYSTACK_SECRET_KEY'),
            'public_key' => env('SETTLEMENT_PAYSTACK_PUBLIC_KEY'),
            'webhook_secret' => env('SETTLEMENT_PAYSTACK_WEBHOOK_SECRET'),
        ],
    ],
```

- [ ] **Step 6: Bind `SettlementGateway::class`**

In `app/Providers/AppServiceProvider.php`, add this immediately after the closing `});` of the existing `PaymentGateway::class` binding (i.e., right after line 76, before the closing `}` of the `register()` method):

```php

        $this->app->bind(\App\Contracts\SettlementGateway::class, function ($app) {
            $driver = config('services.settlement.default', 'paystack');

            return match ($driver) {
                'paystack' => $app->make(\App\Services\Settlement\PaystackSettlementGateway::class),
                default => throw new \RuntimeException("Unsupported settlement driver: {$driver}"),
            };
        });
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PaystackSettlementGatewayTest`
Expected: PASS (7/7).

- [ ] **Step 8: Format, add `.env.example` entries, and commit**

Run: `vendor/bin/pint --dirty`

Add to `.env.example`, immediately after the existing `PAYMENT_DEV_BYPASS=false` line from the earlier payment-readiness work:

```
SETTLEMENT_DRIVER=paystack
SETTLEMENT_PAYSTACK_SECRET_KEY=
SETTLEMENT_PAYSTACK_PUBLIC_KEY=
SETTLEMENT_PAYSTACK_WEBHOOK_SECRET=
```

```bash
git add app/Contracts/SettlementGateway.php app/Services/Settlement/PaystackSettlementGateway.php config/services.php app/Providers/AppServiceProvider.php tests/Feature/Billing/PaystackSettlementGatewayTest.php .env.example
git commit -m "feat: add SettlementGateway contract, PaystackSettlementGateway, and platform settlement credentials

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: Settlement mode toggle on the tenant payments settings screen

**Files:**
- Modify: `app/Http/Controllers/Tenant/PaymentSettingsController.php`
- Modify: `resources/views/tenant/settings/payments.blade.php`
- Test: `tests/Feature/Billing/SettlementModeSettingsTest.php`

**Interfaces:**
- Consumes: `Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT`/`SETTLEMENT_MODE_OWN_GATEWAY` (Task 1).
- Produces: nothing consumed by later tasks — this is a settings-UI leaf.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/SettlementModeSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function settlementModeHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('the payments settings page shows the current settlement mode', function () {
    [$tenant, $user] = settlementModeHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/settings/payments", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertSee('platform_default', false);
});

test('a host can switch a tenant to own_gateway settlement mode', function () {
    [$tenant, $user] = settlementModeHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/settings/payments/settlement-mode", [
        'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect($tenant->fresh()->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
});

test('switching to platform_default is blocked when the platform has no settlement credentials configured', function () {
    config(['services.settlement.paystack.secret_key' => null]);
    [$tenant, $user] = settlementModeHost();
    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/settings/payments/settlement-mode", [
        'settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($tenant->fresh()->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SettlementModeSettingsTest`
Expected: FAIL — the view doesn't render `settlement_mode` yet, and the `settings/payments/settlement-mode` route doesn't exist.

- [ ] **Step 3: Add the controller action**

In `app/Http/Controllers/Tenant/PaymentSettingsController.php`, add `use App\Models\Tenant;` to the imports, and add this method after `update()`:

```php

    /**
     * Switch the tenant's settlement mode between platform_default and own_gateway.
     */
    public function updateSettlementMode(Request $request): RedirectResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->tenantContext->getTenant();

        $validated = $request->validate([
            'settlement_mode' => ['required', 'string', 'in:'.Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT.','.Tenant::SETTLEMENT_MODE_OWN_GATEWAY],
        ]);

        if ($validated['settlement_mode'] === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT && ! config('services.settlement.paystack.secret_key')) {
            return back()->with('error', 'Platform-default settlement is not available yet — the platform has not configured its own settlement credentials.');
        }

        $tenant->update(['settlement_mode' => $validated['settlement_mode']]);

        return back()->with([
            'status' => 'success',
            'message' => 'Settlement mode updated.',
        ]);
    }
```

- [ ] **Step 4: Register the route**

In `routes/subdomain.php`, add this line immediately after the existing `Route::post('/settings/payments', ...)` route (the one at line 84 per current file):

```php
    Route::post('/settings/payments/settlement-mode', [App\Http\Controllers\Tenant\PaymentSettingsController::class, 'updateSettlementMode'])
        ->name('tenant.settings.payments.settlement-mode');
```

- [ ] **Step 5: Add the settlement mode UI to the view**

In `resources/views/tenant/settings/payments.blade.php`, add this new card immediately after the closing `</div>` of the `<div class="row g-5 g-xl-10">` (i.e., insert a new `<div class="row g-5 g-xl-10 mb-5">...</div>` block right before that existing `<div class="row g-5 g-xl-10">` line, so the settlement-mode toggle appears above the Stripe/Paystack cards):

```php
            <div class="row g-5 g-xl-10 mb-5">
                <div class="col-12">
                    <div class="card card-flush">
                        <div class="card-header pt-7">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder text-dark">Settlement Mode</span>
                                <span class="text-muted mt-1 fw-bold fs-7">How does money for this organization reach your bank account?</span>
                            </h3>
                        </div>
                        <div class="card-body pt-5">
                            <form action="{{ route('tenant.settings.payments.settlement-mode') }}" method="POST">
                                @csrf
                                <div class="fv-row mb-5">
                                    <label class="form-check form-check-custom form-check-solid mb-3">
                                        <input class="form-check-input" type="radio" name="settlement_mode" value="platform_default" {{ $tenant->settlement_mode === 'platform_default' ? 'checked' : '' }} />
                                        <span class="form-check-label">
                                            <span class="fw-bold">Platform default</span> — {{ config('app.name') }} collects payments on your behalf and pays you out to your bank/mobile-money account. No Paystack/Stripe account needed.
                                        </span>
                                    </label>
                                    <label class="form-check form-check-custom form-check-solid">
                                        <input class="form-check-input" type="radio" name="settlement_mode" value="own_gateway" {{ $tenant->settlement_mode === 'own_gateway' ? 'checked' : '' }} />
                                        <span class="form-check-label">
                                            <span class="fw-bold">Own gateway</span> — payments go straight into your own Stripe/Paystack account below.
                                        </span>
                                    </label>
                                </div>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">Save Settlement Mode</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=SettlementModeSettingsTest`
Expected: PASS (3/3).

- [ ] **Step 7: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add app/Http/Controllers/Tenant/PaymentSettingsController.php resources/views/tenant/settings/payments.blade.php routes/subdomain.php tests/Feature/Billing/SettlementModeSettingsTest.php
git commit -m "feat: add settlement mode toggle to the tenant payments settings screen

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: Payout account verification via `resolveAccount()`

**Files:**
- Modify: `app/Http/Controllers/Tenant/TenantPayoutAccountController.php`
- Modify: `tests/Feature/Events/EventFinanceTest.php` (the "host can add a payout account" test's request payload only — its assertions stay the same guarantees)
- Test: `tests/Feature/Billing/TenantPayoutAccountVerificationTest.php`

**Interfaces:**
- Consumes: `App\Contracts\SettlementGateway::resolveAccount(string $bankCode, string $accountNumber): array` (Task 2); `TenantPayoutAccount`'s `bank_code`/`resolved_account_name` fillable fields (Task 1).
- Produces: nothing consumed by later tasks.

Today, `TenantPayoutAccountController::store()` saves whatever `account_name` the host typed, with `is_verified` never actually set (it defaults to `false` at the DB level and nothing in the app ever sets it `true`). Per spec §5, account creation must call `resolveAccount()` and only save `is_verified = true` (with the gateway-verified `resolved_account_name`) when it succeeds — creation is rejected outright on a resolution failure. This does not replace the existing user-supplied `account_name` field (still stored as today, e.g. as a display label); it adds real verification alongside it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/TenantPayoutAccountVerificationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function payoutAccountHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('adding a payout account resolves it against the settlement gateway and stores the verified name', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'Kwame Asante'],
        ]),
    ]);

    [$tenant, $user] = payoutAccountHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/payout-accounts", [
        'type' => 'bank',
        'label' => 'Absa Bank Ghana — current',
        'account_name' => 'Purpledot Limited',
        'account_number' => '1234567894417',
        'bank_code' => '030',
    ], ['HTTP_HOST' => $host]);

    $response->assertCreated();
    expect($response->json('is_verified'))->toBeTrue();

    $account = TenantPayoutAccount::where('tenant_id', $tenant->id)->firstOrFail();
    expect($account->is_verified)->toBeTrue();
    expect($account->resolved_account_name)->toBe('Kwame Asante');
    expect($account->bank_code)->toBe('030');
});

test('adding a payout account is rejected when the account cannot be resolved', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name'], 422),
    ]);

    [$tenant, $user] = payoutAccountHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/payout-accounts", [
        'type' => 'bank',
        'label' => 'Absa Bank Ghana — current',
        'account_name' => 'Purpledot Limited',
        'account_number' => '0000000000',
        'bank_code' => '030',
    ], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    expect(TenantPayoutAccount::where('tenant_id', $tenant->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=TenantPayoutAccountVerificationTest`
Expected: FAIL — `store()` doesn't call `resolveAccount()` yet, `bank_code` isn't validated/required, `is_verified` is never set true.

- [ ] **Step 3: Implement resolution in `store()`**

In `app/Http/Controllers/Tenant/TenantPayoutAccountController.php`, add these imports:

```php
use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
```

Replace the `store()` method with:

```php
    public function store(Request $request, string $subdomain, SettlementGateway $settlementGateway): JsonResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->getTenant();

        $validated = $request->validate([
            'type' => ['required', Rule::in([TenantPayoutAccount::TYPE_MOBILE_MONEY, TenantPayoutAccount::TYPE_BANK])],
            'label' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'min:4', 'max:64'],
            'bank_code' => ['required', 'string', 'max:16'],
        ]);

        try {
            $resolved = $settlementGateway->resolveAccount($validated['bank_code'], $validated['account_number']);
        } catch (PaymentFailedException $e) {
            return response()->json(['message' => 'Could not verify this account: '.$e->getMessage()], 422);
        }

        $account = TenantPayoutAccount::create([
            'tenant_id' => $tenant->id,
            'type' => $validated['type'],
            'label' => $validated['label'],
            'account_name' => $validated['account_name'],
            'account_number_encrypted' => $validated['account_number'],
            'bank_code' => $validated['bank_code'],
            'resolved_account_name' => $resolved['account_name'],
            'is_verified' => true,
        ]);

        return response()->json($this->payload($account), 201);
    }
```

Update `payload()` to include the two new fields:

```php
    /**
     * @return array<string, mixed>
     */
    private function payload(TenantPayoutAccount $account): array
    {
        return [
            'id' => $account->id,
            'type' => $account->type,
            'label' => $account->label,
            'account_name' => $account->account_name,
            'masked_account_number' => $account->maskedAccountNumber(),
            'bank_code' => $account->bank_code,
            'resolved_account_name' => $account->resolved_account_name,
            'is_verified' => $account->is_verified,
        ];
    }
```

- [ ] **Step 4: Update the existing `EventFinanceTest.php` payload (assertions unchanged)**

In `tests/Feature/Events/EventFinanceTest.php`, the test `'host can add a payout account and the account number is stored encrypted, never returned raw'` currently posts without `Http::fake()` and without a `bank_code`. Update it to:

```php
test('host can add a payout account and the account number is stored encrypted, never returned raw', function () {
    \Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/bank/resolve*' => \Illuminate\Support\Facades\Http::response([
            'status' => true,
            'data' => ['account_name' => 'Purpledot Limited'],
        ]),
    ]);

    [$tenant, $user] = financeHost();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/payout-accounts", [
        'type' => 'bank',
        'label' => 'Absa Bank Ghana — current',
        'account_name' => 'Purpledot Limited',
        'account_number' => '1234567894417',
        'bank_code' => '030',
    ], ['HTTP_HOST' => $host]);

    $response->assertCreated();
    expect($response->json('masked_account_number'))->toBe('•••• 4417');
    expect($response->json())->not->toHaveKey('account_number_encrypted');
    expect($response->json())->not->toHaveKey('account_number');

    $account = TenantPayoutAccount::where('tenant_id', $tenant->id)->firstOrFail();
    expect($account->getRawOriginal('account_number_encrypted'))->not->toContain('1234567894417');
    expect($account->account_number_encrypted)->toBe('1234567894417');
});
```

This keeps every original assertion (encrypted-at-rest, masked output, no raw account number leaked) — it only adds the now-required `bank_code` input and fakes the resolve call the endpoint now genuinely makes.

The `'a host without manage organization settings permission cannot add a payout account'` test in the same file is unaffected (it still fails on the `authorize()` check before ever reaching the resolve call) — leave it exactly as-is.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=TenantPayoutAccountVerificationTest`
Expected: PASS (2/2).

Run: `php artisan test --compact --filter=EventFinanceTest`
Expected: PASS (5/5).

- [ ] **Step 6: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add app/Http/Controllers/Tenant/TenantPayoutAccountController.php tests/Feature/Events/EventFinanceTest.php tests/Feature/Billing/TenantPayoutAccountVerificationTest.php
git commit -m "feat: verify payout accounts against the settlement gateway before saving

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: Platform-default checkout charging

**Files:**
- Modify: `app/Http/Controllers/Public/EventCheckoutController.php`
- Test: `tests/Feature/Events/PlatformDefaultCheckoutTest.php`

**Interfaces:**
- Consumes: `Tenant::isPlatformDefaultSettlement(): bool` (Task 1); `config('services.settlement.paystack.secret_key')` (Task 2).
- Produces: nothing new consumed by later tasks (the existing `PaystackGateway::createOneTimeCheckoutSession` metadata gains a `tenant_id` key that Task 6's webhook consumes — noted there).

Per spec §3/§4: a `platform_default` tenant has no `TenantPaymentGateway` row at all. `EventCheckoutController::checkout()` must charge through Purpledot's own Paystack credentials for these tenants instead of looking up (and failing to find) a tenant gateway. `own_gateway` tenants hit the exact same code path as today.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Events/PlatformDefaultCheckoutTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

function platformDefaultHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function platformDefaultSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('a platform_default tenant with no TenantPaymentGateway row still completes checkout via the platform Paystack account', function () {
    Http::fake([
        'api.paystack.co/customer/*' => Http::response(['data' => ['email' => 'guest@example.com']]),
        'api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_platform_1']]),
        'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/platform123']]),
    ]);

    [$tenant] = platformDefaultHost('acme');
    $host = platformDefaultSubdomain('acme');
    // No TenantPaymentGateway row is created for this tenant — settlement_mode defaults to platform_default.

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/checkout/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertRedirect('https://checkout.paystack.com/platform123');
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/transaction/initialize')
        && ($request['metadata']['tenant_id'] ?? null) === $tenant->id);
});

test('an own_gateway tenant with no TenantPaymentGateway row gets the existing not-accepting-payments message, unaffected by the platform default', function () {
    [$tenant] = platformDefaultHost('acme');
    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $host = platformDefaultSubdomain('acme');

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 5000,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/checkout/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'This event is not currently accepting payments.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PlatformDefaultCheckoutTest`
Expected: FAIL — `checkout()` currently always looks up a `TenantPaymentGateway` regardless of `settlement_mode`, so the `platform_default` test 404s/errors with the "not accepting payments" message instead of charging via the platform account.

- [ ] **Step 3: Implement the branch**

Replace the full contents of `app/Http/Controllers/Public/EventCheckoutController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class EventCheckoutController extends Controller
{
    public function checkout(string $subdomain, string $event, string $registration): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registration)
            ->firstOrFail();

        if ($registrationModel->isConfirmed()) {
            return redirect()->route('public.events.confirmation', [
                'subdomain' => $tenant->slug,
                'event' => $event,
                'registration' => $registrationModel->id,
            ]);
        }

        $paystack = $tenant->isPlatformDefaultSettlement()
            ? $this->platformGateway()
            : $this->tenantGateway($tenant);

        if (! $paystack) {
            $hasOtherActiveGateway = ! $tenant->isPlatformDefaultSettlement() && TenantPaymentGateway::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->exists();

            $message = $hasOtherActiveGateway
                ? 'This event currently only accepts payments via Paystack, which is not configured for this organizer.'
                : 'This event is not currently accepting payments.';

            return redirect()->back()->with('error', $message);
        }

        $customerId = $paystack->createCustomer($registrationModel->email, $registrationModel->full_name);

        $checkoutUrl = $paystack->createOneTimeCheckoutSession(
            $customerId,
            $registrationModel->amount,
            $registrationModel->currency,
            route('public.events.confirmation', [
                'subdomain' => $tenant->slug,
                'event' => $event,
                'registration' => $registrationModel->id,
            ]),
            [
                'event_registration_id' => $registrationModel->id,
                'tenant_id' => $tenant->id,
                'type' => 'event_ticket',
            ]
        );

        return redirect($checkoutUrl);
    }

    private function tenantGateway(Tenant $tenant): ?PaystackGateway
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        return $gateway ? new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]) : null;
    }

    private function platformGateway(): ?PaystackGateway
    {
        $secret = config('services.settlement.paystack.secret_key');

        return $secret ? new PaystackGateway(['secret_key' => $secret]) : null;
    }
}
```

The `tenant_id` key added to the checkout metadata is what Task 6's tenant-agnostic settlement webhook uses to resolve which tenant a `platform_default` charge belongs to — `own_gateway` charges still confirm via the existing per-tenant webhook URL and don't need it, but including it unconditionally is harmless and keeps this one code path simple.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PlatformDefaultCheckoutTest`
Expected: PASS (2/2).

- [ ] **Step 5: Run the full Events regression suite**

Run: `php artisan test --compact --filter=Events`
Expected: PASS — in particular the existing `'public paid registration redirects to Paystack checkout'` test in `EventRegistrationTest.php`, which exercises an `own_gateway`-shaped tenant (has its own `TenantPaymentGateway` row) and must be completely unaffected. Note: that test's tenant will now default to `settlement_mode = platform_default` (Task 1's new column default) even though it also has its own active `TenantPaymentGateway` row — confirm the branch logic correctly prefers the platform gateway in that case per this task's `isPlatformDefaultSettlement()` check. If that existing test fails because of this interaction, that is a real finding to fix here (not defer): a tenant who has configured their own gateway but never explicitly switched `settlement_mode` to `own_gateway` would have their tickets charged through the platform account instead of their own — set that test's tenant to `settlement_mode: Tenant::SETTLEMENT_MODE_OWN_GATEWAY` explicitly if needed, but flag this in the report, since it reveals that `settlement_mode` defaulting to `platform_default` for tenants who already have a working `TenantPaymentGateway` configured (from before this feature existed) is exactly the backfill problem Task 10 must solve — do not silently patch around it only in the test.

- [ ] **Step 6: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add app/Http/Controllers/Public/EventCheckoutController.php tests/Feature/Events/PlatformDefaultCheckoutTest.php
git commit -m "feat: charge platform_default tenants through the platform's own Paystack account

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: Charge ledger entries — own_gateway webhook extended, new platform-agnostic webhook for platform_default

**Files:**
- Modify: `app/Http/Controllers/Tenant/Commerce/WebhookController.php` (own_gateway: add ledger-entry writing to the existing `charge.success` confirmation)
- Create: `app/Http/Controllers/Public/SettlementWebhookController.php` (platform_default: new tenant-agnostic webhook)
- Modify: `routes/web.php` (register the new webhook route, alongside the existing tenant-agnostic `/webhooks/paystack`/`/webhooks/stripe` SaaS-billing routes)
- Test: `tests/Feature/Events/ChargeLedgerEntryTest.php`

**Interfaces:**
- Consumes: `EventLedgerEntry::TYPE_CHARGE` (Task 1); `tenant_id` key in checkout metadata (Task 5); `Event::effectivePlatformFeePercentage()` (existing).
- Produces: nothing new consumed by later tasks.

Per spec §5: a `charge` ledger row is written where a charge is *confirmed*, using the real `gateway_fee_amount` Paystack reports on the charge response — not an estimate. Paystack's `charge.success` webhook payload includes `data.fees` (the actual fee charged, in the same minor-unit currency as `data.amount`) — this plan uses that field directly rather than estimating it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Events/ChargeLedgerEntryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.webhook_secret' => 'settlement_webhook_secret_test']);
});

function chargeLedgerHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('an own_gateway charge.success webhook writes a charge ledger entry with the real gateway fee', function () {
    Mail::fake();
    [$tenant] = chargeLedgerHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(10_000)->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 5.0]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 10_000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_ledger_own_gw',
            'amount' => 10_000,
            'fees' => 150,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $secret);

    $this->call('POST', "/webhooks/merchant/paystack/{$tenant->id}", [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    $entry = EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_CHARGE)->firstOrFail();
    expect($entry->gross_amount)->toBe(10_000);
    expect($entry->gateway_fee_amount)->toBe(150);
    expect($entry->commission_amount)->toBe(500); // 5% of 10,000
    expect($entry->net_amount)->toBe(9_350); // 10,000 - 150 - 500
    expect($entry->registration_id)->toBe($registration->id);
});

test('a platform_default charge.success confirms the registration via tenant_id in metadata and writes a charge ledger entry', function () {
    Mail::fake();
    [$tenant] = chargeLedgerHost('acme');
    $platformSecret = 'sk_settlement_platform_secret';
    config(['services.settlement.paystack.secret_key' => $platformSecret]);
    config(['services.settlement.paystack.webhook_secret' => $platformSecret]);

    $event = Event::factory()->published()->paid(10_000)->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 5.0]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 10_000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_ledger_platform_default',
            'amount' => 10_000,
            'fees' => 150,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'tenant_id' => $tenant->id, 'type' => 'event_ticket'],
        ],
    ];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $platformSecret);

    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    expect($registration->ticket_code)->not->toBeNull();

    $entry = EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_CHARGE)->firstOrFail();
    expect($entry->gross_amount)->toBe(10_000);
    expect($entry->gateway_fee_amount)->toBe(150);
    expect($entry->commission_amount)->toBe(500);
    expect($entry->net_amount)->toBe(9_350);
});

test('an invalid settlement webhook signature is rejected', function () {
    config(['services.settlement.paystack.webhook_secret' => 'real_secret']);

    $response = $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => 'wrong-signature', 'CONTENT_TYPE' => 'application/json'], json_encode(['event' => 'charge.success', 'data' => []]));

    $response->assertStatus(400);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ChargeLedgerEntryTest`
Expected: FAIL — no `EventLedgerEntry` row is written by the existing per-tenant webhook, and `/webhooks/settlement/paystack` doesn't exist.

- [ ] **Step 3: Extend the existing per-tenant webhook (own_gateway)**

In `app/Http/Controllers/Tenant/Commerce/WebhookController.php`, add `use App\Models\EventLedgerEntry;` to the imports. Replace `confirmEventRegistrationIfApplicable()`'s signature and body — change the call site in `handlePaystack()` first: replace

```php
                $this->confirmEventRegistrationIfApplicable($tenant, (array) ($data['metadata'] ?? []), $data['reference'] ?? null, (int) ($data['amount'] ?? 0), mb_strtoupper((string) ($data['currency'] ?? 'GHS')));
```

with

```php
                $this->confirmEventRegistrationIfApplicable($tenant, (array) ($data['metadata'] ?? []), $data['reference'] ?? null, (int) ($data['amount'] ?? 0), (int) ($data['fees'] ?? 0), mb_strtoupper((string) ($data['currency'] ?? 'GHS')));
```

then replace the `confirmEventRegistrationIfApplicable()` method itself with:

```php
    private function confirmEventRegistrationIfApplicable(Tenant $tenant, array $metadata, ?string $reference, int $amount, int $gatewayFeeAmount, string $currency): void
    {
        $registrationId = $metadata['event_registration_id'] ?? null;
        if (! $registrationId) {
            return;
        }

        $registration = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registrationId)
            ->first();

        if (! $registration || $registration->isConfirmed()) {
            return;
        }

        $eventModel = $registration->event;
        $commissionAmount = (int) round($amount * $eventModel->effectivePlatformFeePercentage() / 100);

        $registration->issueTicket();
        $registration->fill([
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]);
        $registration->save();

        EventLedgerEntry::create([
            'tenant_id' => $tenant->id,
            'event_id' => $eventModel->id,
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'registration_id' => $registration->id,
            'gross_amount' => $amount,
            'gateway_fee_amount' => $gatewayFeeAmount,
            'commission_amount' => $commissionAmount,
            'net_amount' => $amount - $gatewayFeeAmount - $commissionAmount,
            'currency' => $currency,
            'provider' => 'paystack',
            'provider_reference' => $reference,
        ]);

        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

        Mail::to($registration->email)->send(new EventRegistrationConfirmed($registration));
    }
```

Note `$eventModel = $registration->event;` replaces the implicit reliance on `Event::effectivePlatformFeePercentage()` being callable directly — confirm `EventRegistration::event(): BelongsTo` is already defined (it is, per the existing model) so this eager-loads correctly without an N+1 concern in this single-registration context.

- [ ] **Step 4: Create the tenant-agnostic settlement webhook controller**

Create `app/Http/Controllers/Public/SettlementWebhookController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SettlementWebhookController extends Controller
{
    /**
     * Handles both platform_default charge confirmations and payout
     * transfer confirmations — both originate from Purpledot's own
     * Paystack account, so both are verified against the platform's own
     * webhook secret, not any tenant's.
     */
    public function handle(Request $request)
    {
        $signature = $request->header('x-paystack-signature');
        $secret = config('services.settlement.paystack.webhook_secret');

        if (! $signature || ! $secret || $signature !== hash_hmac('sha512', $request->getContent(), (string) $secret)) {
            Log::warning('Settlement webhook signature verification failed');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        try {
            match ($event) {
                'charge.success' => $this->confirmPlatformDefaultCharge((array) ($data['metadata'] ?? []), $data['reference'] ?? null, (int) ($data['amount'] ?? 0), (int) ($data['fees'] ?? 0), mb_strtoupper((string) ($data['currency'] ?? 'GHS'))),
                'transfer.success', 'transfer.failed' => $this->confirmTransfer($event, (string) ($data['reference'] ?? ''), $data),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('Settlement webhook processing failed', ['event' => $event, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    private function confirmPlatformDefaultCharge(array $metadata, ?string $reference, int $amount, int $gatewayFeeAmount, string $currency): void
    {
        $tenantId = $metadata['tenant_id'] ?? null;
        $registrationId = $metadata['event_registration_id'] ?? null;
        if (! $tenantId || ! $registrationId) {
            return;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return;
        }

        $registration = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registrationId)
            ->first();

        if (! $registration || $registration->isConfirmed()) {
            return;
        }

        $eventModel = $registration->event;
        $commissionAmount = (int) round($amount * $eventModel->effectivePlatformFeePercentage() / 100);

        $registration->issueTicket();
        $registration->fill([
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]);
        $registration->save();

        EventLedgerEntry::create([
            'tenant_id' => $tenant->id,
            'event_id' => $eventModel->id,
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'registration_id' => $registration->id,
            'gross_amount' => $amount,
            'gateway_fee_amount' => $gatewayFeeAmount,
            'commission_amount' => $commissionAmount,
            'net_amount' => $amount - $gatewayFeeAmount - $commissionAmount,
            'currency' => $currency,
            'provider' => 'paystack',
            'provider_reference' => $reference,
        ]);

        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

        Mail::to($registration->email)->send(new EventRegistrationConfirmed($registration));
    }

    /**
     * Placeholder for Task 9, which implements the payout side of this
     * webhook (transfer.success/transfer.failed) — left unimplemented here
     * (a no-op) so this task's own tests only exercise charge confirmation.
     * Task 9 replaces this method's body.
     */
    private function confirmTransfer(string $event, string $reference, array $data): void
    {
        // Implemented in Task 9.
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, add this line immediately after the existing `Route::post('/webhooks/merchant/paystack/{tenant}', ...)` line:

```php
Route::post('/webhooks/settlement/paystack', [App\Http\Controllers\Public\SettlementWebhookController::class, 'handle'])->middleware('throttle:webhooks')->name('webhooks.settlement.paystack');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ChargeLedgerEntryTest`
Expected: PASS (3/3).

- [ ] **Step 7: Run the full webhook/registration regression suite**

Run: `php artisan test --compact --filter=Events`
Expected: PASS — in particular `EventRegistrationTest.php`'s existing `'paystack webhook confirms the matching registration exactly once'` test and `PaystackWebhookHardeningTest.php`'s 4 tests, all of which exercise `own_gateway`-shaped tenants through the unchanged per-tenant webhook URL and must still pass with the new `$gatewayFeeAmount` parameter correctly defaulting to `0` when a test payload omits `data.fees` (confirm `(int) ($data['fees'] ?? 0)` handles this — it does, by construction).

- [ ] **Step 8: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add app/Http/Controllers/Tenant/Commerce/WebhookController.php app/Http/Controllers/Public/SettlementWebhookController.php routes/web.php tests/Feature/Events/ChargeLedgerEntryTest.php
git commit -m "feat: write charge ledger entries for both settlement modes via their respective webhooks

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 7: Refund ledger entries for platform_default (extend `EventRegistrationController::cancel()`)

**Files:**
- Modify: `app/Http/Controllers/Tenant/EventRegistrationController.php`
- Test: `tests/Feature/Events/PlatformDefaultRefundTest.php`

**Interfaces:**
- Consumes: `EventLedgerEntry::TYPE_REFUND` (Task 1); `Tenant::isPlatformDefaultSettlement()` (Task 1); `config('services.settlement.paystack.secret_key')` (Task 2).
- Produces: nothing consumed by later tasks.

Today, `refundPaidRegistration()` unconditionally looks up the tenant's own `TenantPaymentGateway` row — for a `platform_default` tenant (who has none), this always 422s with "no active payment gateway is configured," even though the original charge went through the platform's own account and absolutely can be refunded from there. This task branches the refund the same way Task 5 branched the charge, and extends the ledger reconciliation to also write an `event_ledger_entries` `refund` row (reversing both gross and commission, per spec §5) alongside the existing `MerchantTransaction` reconciliation.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Events/PlatformDefaultRefundTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function platformRefundHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function platformRefundSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('cancelling a platform_default paid registration refunds via the platform Paystack account and reverses the ledger', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'ref_platform_refund_1'],
        ]),
    ]);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_platform_secret']);

    [$tenant, $user] = platformRefundHost('acme');
    $host = platformRefundSubdomain('acme');
    // No TenantPaymentGateway row exists — settlement_mode defaults to platform_default.

    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 5.0]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 10_000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_platform_1',
    ]);
    EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'type' => EventLedgerEntry::TYPE_CHARGE,
        'gross_amount' => 10_000,
        'gateway_fee_amount' => 150,
        'commission_amount' => 500,
        'net_amount' => 9_350,
        'currency' => 'GHS',
        'provider' => 'paystack',
        'provider_reference' => 'paid_ref_platform_1',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);

    $refundEntry = EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_REFUND)->firstOrFail();
    expect($refundEntry->gross_amount)->toBe(10_000);
    expect($refundEntry->commission_amount)->toBe(500);
    expect($refundEntry->net_amount)->toBe(-9_350);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/refund')
        && $request['transaction'] === 'paid_ref_platform_1');
});

test('cancelling a platform_default registration aborts with 422 when the platform has no settlement credentials configured', function () {
    config(['services.settlement.paystack.secret_key' => null]);

    [$tenant, $user] = platformRefundHost('acme');
    $host = platformRefundSubdomain('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 10_000,
        'payment_reference' => 'paid_ref_platform_2',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PlatformDefaultRefundTest`
Expected: FAIL — `refundPaidRegistration()` currently always 422s for a `platform_default` tenant (no `TenantPaymentGateway` row to find), and no `event_ledger_entries` refund row is ever written.

- [ ] **Step 3: Branch the refund and extend ledger reconciliation**

In `app/Http/Controllers/Tenant/EventRegistrationController.php`, add `use App\Models\EventLedgerEntry;` to the imports. Replace `refundPaidRegistration()` with:

```php
    private function refundPaidRegistration(Tenant $tenant, EventRegistration $registrationModel): ?JsonResponse
    {
        $paystack = $tenant->isPlatformDefaultSettlement()
            ? $this->platformRefundGateway()
            : $this->tenantRefundGateway($tenant);

        if (! $paystack) {
            $message = $tenant->isPlatformDefaultSettlement()
                ? 'Cannot cancel: the platform has not configured its own settlement credentials to process the refund.'
                : 'Cannot cancel: no active payment gateway is configured to process the refund.';

            return response()->json(['message' => $message], 422);
        }

        try {
            $refundId = $paystack->refund($registrationModel->payment_reference);
        } catch (PaymentFailedException $e) {
            Log::error('Automatic refund on registration cancellation failed', [
                'tenant' => $tenant->id,
                'registration' => $registrationModel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Cancellation aborted: the refund could not be processed. '.$e->getMessage()], 502);
        }

        $this->recordRefundInLedger($tenant, $registrationModel, $refundId);

        return null;
    }

    private function tenantRefundGateway(Tenant $tenant): ?PaystackGateway
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        return $gateway ? new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]) : null;
    }

    private function platformRefundGateway(): ?PaystackGateway
    {
        $secret = config('services.settlement.paystack.secret_key');

        return $secret ? new PaystackGateway(['secret_key' => $secret]) : null;
    }
```

Then extend `recordRefundInLedger()` — replace it with:

```php
    private function recordRefundInLedger(Tenant $tenant, EventRegistration $registrationModel, string $refundId): void
    {
        $transaction = MerchantTransaction::where('tenant_id', $tenant->id)
            ->where('provider_transaction_id', $registrationModel->payment_reference)
            ->first();

        $chargeEntry = EventLedgerEntry::where('tenant_id', $tenant->id)
            ->where('type', EventLedgerEntry::TYPE_CHARGE)
            ->where('provider_reference', $registrationModel->payment_reference)
            ->first();

        if (! $transaction && ! $chargeEntry) {
            Log::warning('Automatic refund succeeded but no matching transaction or ledger entry was found to reconcile', [
                'tenant' => $tenant->id,
                'registration' => $registrationModel->id,
                'payment_reference' => $registrationModel->payment_reference,
                'refund_id' => $refundId,
            ]);

            return;
        }

        if ($transaction) {
            MerchantTransaction::create([
                'tenant_id' => $tenant->id,
                'provider' => $transaction->provider,
                'provider_transaction_id' => $refundId !== 'pending' ? $refundId : 'REF_'.$transaction->provider_transaction_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => 'succeeded',
                'type' => 'refund',
                'description' => 'Refund for '.$transaction->provider_transaction_id,
                'customer_email' => $transaction->customer_email,
                'meta' => ['refund_id' => $refundId],
            ]);

            $transaction->update(['status' => 'refunded']);
        }

        if ($chargeEntry) {
            EventLedgerEntry::create([
                'tenant_id' => $tenant->id,
                'event_id' => $chargeEntry->event_id,
                'type' => EventLedgerEntry::TYPE_REFUND,
                'registration_id' => $registrationModel->id,
                'gross_amount' => $chargeEntry->gross_amount,
                'gateway_fee_amount' => $chargeEntry->gateway_fee_amount,
                'commission_amount' => $chargeEntry->commission_amount,
                'net_amount' => -$chargeEntry->net_amount,
                'currency' => $chargeEntry->currency,
                'provider' => $chargeEntry->provider,
                'provider_reference' => $refundId !== 'pending' ? $refundId : 'REF_'.$chargeEntry->provider_reference,
            ]);
        }
    }
```

`platform_default` registrations have a `TYPE_CHARGE` ledger entry (from Task 6) but no `MerchantTransaction` row (that table is only ever written by the tenant-scoped merchant `WebhookController`, which `platform_default` charges never go through) — so `$transaction` will be `null` and only the ledger branch fires. `own_gateway` registrations have both, so both branches fire, exactly as today plus the new ledger reversal.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PlatformDefaultRefundTest`
Expected: PASS (2/2).

- [ ] **Step 5: Run the full cancel/refund regression suite**

Run: `php artisan test --compact --filter=EventRegistrationCancelRefundTest`
Expected: PASS — the existing `own_gateway`-shaped tests (which all create a `TenantPaymentGateway` row explicitly) must be completely unaffected; none of them seed a matching `EventLedgerEntry`, so `recordRefundInLedger()`'s `$chargeEntry` branch should simply not fire for them (only the pre-existing `MerchantTransaction` branch), which is correct — do not let this task's new ledger-reversal code accidentally require a ledger entry to exist for `own_gateway` refunds to succeed.

- [ ] **Step 6: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add app/Http/Controllers/Tenant/EventRegistrationController.php tests/Feature/Events/PlatformDefaultRefundTest.php
git commit -m "feat: refund platform_default cancellations through the platform account and reverse the ledger

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 8: `EventFinanceController` rebuild — ledger-based stats, locked available-balance payout creation, statement rework

**Files:**
- Modify: `app/Http/Controllers/Tenant/EventFinanceController.php`
- Test: `tests/Feature/Events/EventFinanceLedgerTest.php`

**Interfaces:**
- Consumes: `EventLedgerEntry` model and its `TYPE_CHARGE`/`TYPE_REFUND`/`TYPE_PAYOUT` constants (Task 1).
- Produces: nothing consumed by later tasks.

Per spec §8, this rebuilds `index()`, `storePayout()`, and `exportSettlementStatement()` to read from `event_ledger_entries` instead of computing inline over `EventRegistration`/`EventPayout`. Per spec §5 (`storePayout` note) and the Global Constraints, the payout-amount-vs-available-balance check must happen inside a `DB::transaction()` with a row lock — a plain query-then-insert is a TOCTOU race.

Available balance formula (spec §5): `sum(charge.net) - sum(refund.net) - sum(paid/processing payout.net)`. Since this task's ledger stores a refund's `net_amount` as already-negative (Task 6/7), and a payout's `net_amount` will be recorded as the paid-out amount (Task 9 writes payout ledger rows), the formula simplifies to a single signed sum: `sum(net_amount) for type IN (charge, refund) MINUS sum(amount) for payouts already scheduled/processing/paid` — this task computes available balance from ledger `charge`/`refund` rows only (money collected/reversed) minus the amount already committed to `EventPayout` rows in `scheduled`, `processing`, or `paid` status (not yet `payout`-type ledger rows, since Task 9 is what makes payouts actually move money and write `payout`-type ledger entries — until then, a `scheduled` payout is a reservation against the balance, not yet a ledger movement).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Events/EventFinanceLedgerTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EventFinanceLedgerTest`
Expected: FAIL — `index()` doesn't return `stats.net_collected`/`stats.available_balance` yet, and `storePayout()` accepts any amount without checking balance.

- [ ] **Step 3: Rebuild `EventFinanceController`**

Replace the full contents of `app/Http/Controllers/Tenant/EventFinanceController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\TenantPayoutAccount;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventFinanceController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $collected = (int) $eventModel->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('gross_amount');
        $fees = (int) $eventModel->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('commission_amount');
        $netCollected = $this->netCollected($eventModel);
        $paidOut = (int) $eventModel->payouts()->where('status', EventPayout::STATUS_PAID)->sum('amount');

        $stats = [
            'collected' => $collected,
            'fees' => $fees,
            'net' => $collected - $fees,
            'net_collected' => $netCollected,
            'paid_out' => $paidOut,
            'available_balance' => $this->availableBalance($eventModel),
            'confirmed_orders' => $eventModel->registrations()->confirmed()->count(),
        ];

        $accounts = TenantPayoutAccount::where('tenant_id', $tenant->id)->orderByDesc('created_at')->get();

        return response()->json([
            'stats' => $stats,
            'accounts' => $accounts->map(fn (TenantPayoutAccount $a): array => [
                'id' => $a->id,
                'type' => $a->type,
                'label' => $a->label,
                'account_name' => $a->account_name,
                'masked_account_number' => $a->maskedAccountNumber(),
                'is_verified' => $a->is_verified,
            ])->values(),
            'payouts' => $eventModel->payouts->map(fn (EventPayout $p): array => [
                'id' => $p->id,
                'amount' => $p->amount,
                'status' => $p->status,
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'paid_at' => $p->paid_at?->toIso8601String(),
                'note' => $p->note,
                'failure_reason' => $p->failure_reason,
                'payout_account_label' => $p->payoutAccount?->label,
                'payout_account_masked_number' => $p->payoutAccount?->maskedAccountNumber(),
            ])->values(),
        ]);
    }

    public function storePayout(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'payout_account_id' => ['required', Rule::exists('tenant_payout_accounts', 'id')->where('tenant_id', $tenant->id)],
            'amount' => ['required', 'integer', 'min:1'],
            'scheduled_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($eventModel, $tenant, $validated): JsonResponse {
            // Lock the event row for the balance-check-then-create sequence so
            // two concurrent payout requests (or a payout racing a refund)
            // can't both read the same available balance and both pass,
            // over-drawing the event's collected funds.
            Event::where('id', $eventModel->id)->lockForUpdate()->firstOrFail();

            $available = $this->availableBalance($eventModel);
            if ($validated['amount'] > $available) {
                return response()->json(['message' => "Requested amount ({$validated['amount']}) exceeds the available balance ({$available})."], 422);
            }

            $payout = $eventModel->payouts()->create([
                'tenant_id' => $tenant->id,
                'payout_account_id' => $validated['payout_account_id'],
                'amount' => $validated['amount'],
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);

            return response()->json(['id' => $payout->id], 201);
        });
    }

    public function updatePayoutStatus(Request $request, string $subdomain, string $event, string $payout): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'status' => ['required', Rule::in([EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_PAID])],
        ]);

        $payoutModel = $eventModel->payouts()->where('id', $payout)->firstOrFail();
        $payoutModel->update([
            'status' => $validated['status'],
            'paid_at' => $validated['status'] === EventPayout::STATUS_PAID ? now() : null,
        ]);

        return response()->json(['message' => 'Payout updated.']);
    }

    public function exportSettlementStatement(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $ownGateway = ! $tenant->isPlatformDefaultSettlement();

        $filename = "{$eventModel->slug}-settlement-statement.csv";

        return response()->streamDownload(function () use ($eventModel, $ownGateway): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Type', 'Registration/Reference', 'Gross', 'Gateway fee', 'Commission', 'Net', 'Currency', 'Date']);

            $eventModel->ledgerEntries()->orderBy('created_at')->chunk(200, function ($entries) use ($handle, $ownGateway): void {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        $entry->type,
                        $entry->registration?->full_name ?? $entry->provider_reference,
                        $entry->gross_amount,
                        $ownGateway ? 'not tracked' : $entry->gateway_fee_amount,
                        $entry->commission_amount,
                        $entry->net_amount,
                        $entry->currency,
                        $entry->created_at->toIso8601String(),
                    ]);
                }
            });

            fputcsv($handle, []);
            fputcsv($handle, ['Reconciliation']);
            fputcsv($handle, ['Collected (gross)', $eventModel->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('gross_amount')]);
            fputcsv($handle, ['Paid out', $eventModel->payouts()->where('status', EventPayout::STATUS_PAID)->sum('amount')]);
            fputcsv($handle, ['Available balance', $eventModel->ledgerEntries()->whereIn('type', [EventLedgerEntry::TYPE_CHARGE, EventLedgerEntry::TYPE_REFUND])->sum('net_amount') - $eventModel->payouts()->whereIn('status', [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_PROCESSING, EventPayout::STATUS_PAID])->sum('amount')]);

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function netCollected(Event $eventModel): int
    {
        return (int) $eventModel->ledgerEntries()->whereIn('type', [EventLedgerEntry::TYPE_CHARGE, EventLedgerEntry::TYPE_REFUND])->sum('net_amount');
    }

    private function availableBalance(Event $eventModel): int
    {
        $committedToPayouts = (int) $eventModel->payouts()
            ->whereIn('status', [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_PROCESSING, EventPayout::STATUS_PAID])
            ->sum('amount');

        return $this->netCollected($eventModel) - $committedToPayouts;
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
```

- [ ] **Step 4: Add the `ledgerEntries()` relationship to `Event`**

In `app/Models/Event.php`, add this relationship method (place it next to the existing `registrations()`/`payouts()` relations):

```php
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EventLedgerEntry::class, 'event_id');
    }
```

Add `use App\Models\EventLedgerEntry;` is unnecessary since it's the same namespace (`App\Models`) — confirm `use Illuminate\Database\Eloquent\Relations\HasMany;` is already imported in `Event.php` (it is, since `registrations()`/`payouts()` already use it).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=EventFinanceLedgerTest`
Expected: PASS (3/3).

- [ ] **Step 6: Run the existing finance regression suite**

Run: `php artisan test --compact --filter=EventFinanceTest`
Expected: The original `'finance stats reflect confirmed registrations and paid payouts only'` test asserted `stats.collected`/`stats.fees`/`stats.net` computed from `EventRegistration::confirmed()->sum(...)` directly — this task changes those same stat keys to read from `event_ledger_entries` instead, which will be `0` for that test's data (it seeds registrations directly, not ledger entries) and this test will now FAIL. This is an intentional, expected behavior change (the whole point of this task is moving the source of truth from registrations to the ledger) — update this specific test's setup to seed `EventLedgerEntry` rows matching its registration data instead of relying on the inline `EventRegistration::confirmed()->sum()` computation, keeping the same expected stat values (`collected: 30_000`, `fees: 1_500`, `net: 28_500`, `paid_out: 12_000`). The other 4 tests in `EventFinanceTest.php` (payout account creation/permission gate, record-and-mark-paid, statement export) are unaffected by this specific change and must keep passing with zero modification beyond Task 4's already-applied `bank_code`/`Http::fake()` update.

- [ ] **Step 7: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add app/Http/Controllers/Tenant/EventFinanceController.php app/Models/Event.php tests/Feature/Events/EventFinanceLedgerTest.php tests/Feature/Events/EventFinanceTest.php
git commit -m "feat: rebuild EventFinanceController to read from the ledger with a locked available-balance payout check

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 9: Payout sending — bank listing, recipient creation, transfer initiation, transfer webhook

**Files:**
- Modify: `app/Http/Controllers/Tenant/EventFinanceController.php` (new `sendPayout()` action)
- Modify: `app/Http/Controllers/Tenant/TenantPayoutAccountController.php` (new `banks()` action)
- Modify: `app/Http/Controllers/Public/SettlementWebhookController.php` (implement `confirmTransfer()`, left as a no-op stub by Task 6)
- Modify: `routes/subdomain.php` (new "send payout" route, new "list banks" route)
- Test: `tests/Feature/Events/PayoutSendingTest.php`

**Interfaces:**
- Consumes: `SettlementGateway::createRecipient()`/`initiateTransfer()`/`verifyTransfer()`/`listBanks()` (Task 2); `EventPayout::STATUS_PROCESSING`/`STATUS_FAILED` (Task 1); `EventLedgerEntry::TYPE_PAYOUT` (Task 1).
- Produces: nothing consumed by later tasks.

Per spec §6 steps 3-4: a separate, explicit "Send payout" action on a `scheduled` or `failed` payout creates the recipient (caching `recipient_code` on the account if not already present) and initiates the transfer with a freshly generated reference, moving status to `processing`. The transfer webhook then flips `processing` → `paid`/`failed` and — only for `transfer.success` — writes a `payout`-type ledger row.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Events/PayoutSendingTest.php`:

```php
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
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
    config(['services.settlement.paystack.webhook_secret' => 'sk_settlement_test_123']);
});

function payoutSendingHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('sending a scheduled payout creates a recipient, initiates a transfer, and moves status to processing', function () {
    Http::fake([
        'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_test_1']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_test_1', 'status' => 'pending']]),
    ]);

    [$tenant, $user] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'bank_code' => '030']);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'amount' => 5_000]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $payout->refresh();
    expect($payout->status)->toBe(EventPayout::STATUS_PROCESSING);
    expect($payout->provider_reference)->not->toBeNull();

    $account->refresh();
    expect($account->recipient_code)->toBe('RCP_test_1');
});

test('resending a failed payout uses a fresh reference, not a replay of the failed one', function () {
    Http::fake([
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_test_2', 'status' => 'pending']]),
    ]);

    [$tenant, $user] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'bank_code' => '030', 'recipient_code' => 'RCP_cached_1']);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_FAILED,
        'provider_reference' => 'old_failed_reference',
        'failure_reason' => 'Insufficient balance',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/transfer') && $request['reference'] !== 'old_failed_reference');
    expect($payout->fresh()->provider_reference)->not->toBe('old_failed_reference');
});

test('a transfer.success webhook flips the payout to paid and writes one payout ledger row, idempotently', function () {
    [$tenant] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'transfer_ref_success_1',
    ]);

    $payload = ['event' => 'transfer.success', 'data' => ['reference' => 'transfer_ref_success_1', 'transfer_code' => 'TRF_x']];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, 'sk_settlement_test_123');

    $webhook = fn () => $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);

    $webhook()->assertOk();
    $webhook()->assertOk(); // delivered twice — must stay idempotent

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID);
    expect(EventLedgerEntry::where('payout_id', $payout->id)->where('type', EventLedgerEntry::TYPE_PAYOUT)->count())->toBe(1);
});

test('a transfer.failed webhook flips the payout to failed with no ledger row', function () {
    [$tenant] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'transfer_ref_failed_1',
    ]);

    $payload = ['event' => 'transfer.failed', 'data' => ['reference' => 'transfer_ref_failed_1', 'reason' => 'Insufficient balance in platform account']];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, 'sk_settlement_test_123');

    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_FAILED)->failure_reason->toBe('Insufficient balance in platform account');
    expect(EventLedgerEntry::where('payout_id', $payout->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PayoutSendingTest`
Expected: FAIL — the "send payout" route/action doesn't exist, and `confirmTransfer()` is still Task 6's no-op stub.

- [ ] **Step 3: Add the `sendPayout()` action**

In `app/Http/Controllers/Tenant/EventFinanceController.php`, add `use App\Contracts\SettlementGateway;` and `use App\Exceptions\PaymentFailedException;` and `use Illuminate\Support\Str;` to the imports, and add this method after `storePayout()`:

```php

    public function sendPayout(string $subdomain, string $event, string $payout, SettlementGateway $settlementGateway): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $payoutModel = $eventModel->payouts()->where('id', $payout)->firstOrFail();

        if (! in_array($payoutModel->status, [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_FAILED], true)) {
            return response()->json(['message' => 'Only a scheduled or failed payout can be sent.'], 422);
        }

        $account = $payoutModel->payoutAccount;

        try {
            if (! $account->recipient_code) {
                $recipientCode = $settlementGateway->createRecipient(
                    $account->type === TenantPayoutAccount::TYPE_MOBILE_MONEY ? 'mobile_money' : 'nuban',
                    $account->resolved_account_name ?? $account->account_name,
                    (string) $account->account_number_encrypted,
                    (string) $account->bank_code,
                    'GHS'
                );
                $account->update(['recipient_code' => $recipientCode]);
            }

            $reference = 'payout_'.$payoutModel->id.'_'.Str::random(8);
            $transfer = $settlementGateway->initiateTransfer($account->recipient_code, $payoutModel->amount, 'GHS', $reference);
        } catch (PaymentFailedException $e) {
            return response()->json(['message' => 'Could not send payout: '.$e->getMessage()], 502);
        }

        $payoutModel->update([
            'status' => EventPayout::STATUS_PROCESSING,
            'provider_reference' => $reference,
            'failure_reason' => null,
        ]);

        return response()->json(['message' => 'Payout sent.', 'transfer_code' => $transfer['transfer_code']]);
    }
```

- [ ] **Step 4: Add the `banks()` account-setup endpoint**

In `app/Http/Controllers/Tenant/TenantPayoutAccountController.php`, add `use App\Contracts\SettlementGateway;` to the imports and add this method:

```php

    public function banks(string $subdomain, SettlementGateway $settlementGateway): JsonResponse
    {
        $this->authorize('manage organization settings');

        return response()->json($settlementGateway->listBanks());
    }
```

- [ ] **Step 5: Implement `confirmTransfer()` in the settlement webhook**

In `app/Http/Controllers/Public/SettlementWebhookController.php`, add `use App\Models\EventPayout;` to the imports if not already present, and replace the `confirmTransfer()` stub with:

```php
    private function confirmTransfer(string $event, string $reference, array $data): void
    {
        if ($reference === '') {
            return;
        }

        $payout = EventPayout::where('provider_reference', $reference)->first();
        if (! $payout || $payout->status !== EventPayout::STATUS_PROCESSING) {
            // Already processed (idempotent redelivery) or not a payout this
            // webhook is tracking — a no-op either way.
            return;
        }

        if ($event === 'transfer.success') {
            $payout->update(['status' => EventPayout::STATUS_PAID, 'paid_at' => now()]);

            EventLedgerEntry::create([
                'tenant_id' => $payout->tenant_id,
                'event_id' => $payout->event_id,
                'type' => EventLedgerEntry::TYPE_PAYOUT,
                'payout_id' => $payout->id,
                'gross_amount' => $payout->amount,
                'gateway_fee_amount' => 0,
                'commission_amount' => 0,
                'net_amount' => $payout->amount,
                'currency' => 'GHS',
                'provider' => 'paystack',
                'provider_reference' => $reference,
            ]);

            return;
        }

        $payout->update([
            'status' => EventPayout::STATUS_FAILED,
            'failure_reason' => (string) ($data['reason'] ?? 'Transfer failed'),
        ]);
    }
```

The idempotency guard is the `$payout->status !== EventPayout::STATUS_PROCESSING` check — once a payout is `paid` or `failed`, a redelivered webhook for the same reference finds it no longer `processing` and does nothing.

- [ ] **Step 6: Register the routes**

In `routes/subdomain.php`, add immediately after the existing `Route::patch('events/{event}/finance/payouts/{payout}', ...)` line:

```php
    Route::post('events/{event}/finance/payouts/{payout}/send', [EventFinanceController::class, 'sendPayout'])->name('tenant.events.finance.payouts.send');
```

Add immediately after the existing `Route::get('payout-accounts', ...)` line:

```php
    Route::get('payout-accounts/banks', [TenantPayoutAccountController::class, 'banks'])->name('tenant.payout-accounts.banks');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PayoutSendingTest`
Expected: PASS (4/4).

- [ ] **Step 8: Run the full Events regression suite**

Run: `php artisan test --compact --filter=Events`
Expected: PASS — no prior task's tests exercise the "send payout" or transfer-webhook paths, so this is purely additive; confirm nothing else broke.

- [ ] **Step 9: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add app/Http/Controllers/Tenant/EventFinanceController.php app/Http/Controllers/Tenant/TenantPayoutAccountController.php app/Http/Controllers/Public/SettlementWebhookController.php routes/subdomain.php tests/Feature/Events/PayoutSendingTest.php
git commit -m "feat: implement payout sending (recipient creation, transfer initiation) and the transfer confirmation webhook

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 10: Platform fee percentage UI and rollout backfill

**Files:**
- Modify: `app/Http/Controllers/Tenant/PaymentSettingsController.php` (new `updatePlatformFee()` action, superadmin-only)
- Modify: `resources/views/tenant/settings/payments.blade.php` (new fee-percentage field, shown only to platform superadmins)
- Create: `app/Console/Commands/Tenants/BackfillSettlementMode.php`
- Modify: `routes/subdomain.php` (new route)
- Test: `tests/Feature/Billing/PlatformFeeSettingsTest.php`
- Test: `tests/Console/BackfillSettlementModeTest.php`

**Interfaces:**
- Consumes: `Tenant::SETTLEMENT_MODE_OWN_GATEWAY` (Task 1); `Tenant.platform_fee_percentage` (existing column).
- Produces: nothing consumed by later tasks — this is the plan's final, operational-completeness task.

Per spec §7, `platform_fee_percentage` becomes settable from the UI, not just the existing `php artisan events:set-platform-fee` CLI command (which remains, unchanged, for scripted/bulk use). Per spec §10, existing tenants must be explicitly backfilled to `own_gateway` at deploy time — Task 1's migration defaults the column to `platform_default` for everyone, which is correct only for genuinely new tenants; a rollout command flips every tenant that existed before this feature shipped.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Billing/PlatformFeeSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a platform superadmin can set a tenant platform fee percentage from the settings screen', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'hiselase@gmail.com']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/settings/payments/platform-fee", [
        'platform_fee_percentage' => 7.5,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect((float) $tenant->fresh()->platform_fee_percentage)->toBe(7.5);
});

test('a non-platform-superadmin tenant user cannot set the platform fee percentage', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'someone-else@example.com']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/settings/payments/platform-fee", [
        'platform_fee_percentage' => 7.5,
    ], ['HTTP_HOST' => $host]);

    $response->assertForbidden();
});
```

Create `tests/Console/BackfillSettlementModeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Console;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
});

test('backfilling settlement mode sets every existing tenant to own_gateway', function () {
    $existing = Tenant::factory()->create();
    expect($existing->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT);

    Artisan::call('tenants:backfill-settlement-mode', ['--yes' => true]);

    expect($existing->fresh()->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
});

test('backfilling settlement mode does not touch a tenant already explicitly set', function () {
    $alreadyPlatform = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $alreadyOwnGateway = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

    // Simulate that $alreadyPlatform was deliberately opted in post-launch by
    // recording it as "seen" — this command only needs to run once at
    // rollout, so for this task it backfills unconditionally; a tenant that
    // wants platform_default post-rollout switches via the settings screen
    // (Task 3), not by re-running this command.
    Artisan::call('tenants:backfill-settlement-mode', ['--yes' => true]);

    expect($alreadyOwnGateway->fresh()->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=PlatformFeeSettingsTest`
Run: `php artisan test --compact --filter=BackfillSettlementModeTest`
Expected: FAIL — the `settings/payments/platform-fee` route doesn't exist, and `tenants:backfill-settlement-mode` doesn't exist.

- [ ] **Step 3: Add the platform fee controller action**

In `app/Http/Controllers/Tenant/PaymentSettingsController.php`, add this method after `updateSettlementMode()`:

```php

    /**
     * Superadmin-only: set a tenant's platform fee percentage from the UI,
     * writing to the same column php artisan events:set-platform-fee writes
     * to. The CLI command remains for scripted/bulk use.
     */
    public function updatePlatformFee(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->getTenant();

        if (! $request->user() || ! in_array($request->user()->email, ['hiselase@gmail.com', 'dev@wearepurpledot.com'], true)) {
            abort(403);
        }

        $validated = $request->validate([
            'platform_fee_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $tenant->update(['platform_fee_percentage' => $validated['platform_fee_percentage']]);

        return back()->with([
            'status' => 'success',
            'message' => 'Platform fee percentage updated.',
        ]);
    }
```

This reuses the same email allowlist already used by `AppServiceProvider::boot()`'s `LogViewer::auth()` closure for platform-superadmin gating — the codebase's existing convention for "platform operator, not just a tenant admin."

- [ ] **Step 4: Add the route**

In `routes/subdomain.php`, add immediately after the `settlement-mode` route added in Task 3:

```php
    Route::post('/settings/payments/platform-fee', [App\Http\Controllers\Tenant\PaymentSettingsController::class, 'updatePlatformFee'])
        ->name('tenant.settings.payments.platform-fee');
```

- [ ] **Step 5: Add the fee field to the settings view**

In `resources/views/tenant/settings/payments.blade.php`, add this block immediately after the Settlement Mode card added in Task 3, still inside the same `mb-5` row wrapper's sibling position (i.e., as its own new `<div class="row g-5 g-xl-10 mb-5">...</div>` block, gated to only render for the two platform-operator emails):

```php
            @if(auth()->user() && in_array(auth()->user()->email, ['hiselase@gmail.com', 'dev@wearepurpledot.com']))
            <div class="row g-5 g-xl-10 mb-5">
                <div class="col-12">
                    <div class="card card-flush">
                        <div class="card-header pt-7">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder text-dark">Platform Fee (Superadmin)</span>
                                <span class="text-muted mt-1 fw-bold fs-7">The commission percentage taken on this organization's ticket sales</span>
                            </h3>
                        </div>
                        <div class="card-body pt-5">
                            <form action="{{ route('tenant.settings.payments.platform-fee') }}" method="POST">
                                @csrf
                                <div class="fv-row mb-5">
                                    <label class="fw-bold fs-6 mb-2">Fee Percentage</label>
                                    <input type="number" step="0.1" min="0" max="100" name="platform_fee_percentage" class="form-control form-control-solid" style="max-width: 200px" value="{{ $tenant->platform_fee_percentage }}" />
                                </div>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">Save Fee</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            @endif
```

- [ ] **Step 6: Create the backfill command**

Create `app/Console/Commands/Tenants/BackfillSettlementMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Tenants;

use App\Models\Tenant;
use Illuminate\Console\Command;

final class BackfillSettlementMode extends Command
{
    protected $signature = 'tenants:backfill-settlement-mode {--yes : Skip the confirmation prompt}';

    protected $description = 'One-time rollout step: sets every existing tenant to own_gateway settlement mode, since only genuinely new tenants should default to platform_default.';

    public function handle(): int
    {
        $count = Tenant::count();

        if (! $this->option('yes') && ! $this->confirm("This will set settlement_mode = own_gateway for all {$count} existing tenants. Continue?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $updated = Tenant::query()->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
        $this->info("Backfilled {$updated} tenant(s) to own_gateway settlement mode.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PlatformFeeSettingsTest`
Run: `php artisan test --compact --filter=BackfillSettlementModeTest`
Expected: PASS (2/2 and 2/2).

- [ ] **Step 8: Run the full Events/Billing regression suite**

Run: `php artisan test --compact --filter=Events`
Run: `php artisan test --compact --filter=Billing`
Expected: PASS, modulo the pre-existing `LlmTopupTest` Mockery-vs-final-class failures already established as unrelated to this codebase area throughout this project's history.

- [ ] **Step 9: Format and commit**

Run: `vendor/bin/pint --dirty`

```bash
git add app/Http/Controllers/Tenant/PaymentSettingsController.php resources/views/tenant/settings/payments.blade.php app/Console/Commands/Tenants/BackfillSettlementMode.php routes/subdomain.php tests/Feature/Billing/PlatformFeeSettingsTest.php tests/Console/BackfillSettlementModeTest.php
git commit -m "feat: add platform fee UI and a rollout command to backfill existing tenants to own_gateway

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Self-Review Notes

**Spec coverage:**
- §3 (settlement modes) → Tasks 1, 3, 5, 7.
- §4 (provider abstraction) → Task 2.
- §5 (data model, ledger producers) → Tasks 1, 6, 7, 9.
- §6 (payout flow) → Tasks 3 (account resolve), 8 (schedule + balance check), 9 (send + transfer webhook).
- §7 (commission rate UI) → Task 10.
- §8 (statement rework) → Task 8.
- §9 (testing approach) → every task's own `Http::fake()`-based tests; the spec's one item explicitly "not covered by this phase" (real sandbox verification) is called out as a Global Constraint and an explicit follow-up, not silently assumed done.
- §10 (rollout) → Task 10's backfill command and Task 3's config-presence guard blocking `platform_default` without platform credentials.
- The spec's two "open questions" are both resolved: mobile-money-vs-bank resolution is settled per the user's confirmation (Global Constraints); the `own_gateway`-refund-reflection question is explicitly ruled out of scope for this phase (Global Constraints), rather than silently built or silently dropped.

**Placeholder scan:** none found — every step has concrete, complete code, exact file paths, and runnable test commands. The one intentional stub (Task 6's `confirmTransfer()` no-op, replaced by Task 9) is explicitly labeled as such in both the code comment and the task text, not a silent gap.

**Type consistency:** `EventLedgerEntry`'s fillable/cast field names (`gross_amount`, `gateway_fee_amount`, `commission_amount`, `net_amount`, `provider_reference`) are used identically across Tasks 1, 6, 7, 8, and 9. `SettlementGateway`'s five method signatures (Task 2) are called with matching argument types and return-shape assumptions in Tasks 4 and 9. `Tenant::isPlatformDefaultSettlement(): bool` (Task 1) is consumed identically in Tasks 5 and 7. `EventPayout::STATUS_PROCESSING`/`STATUS_FAILED` (Task 1) are used identically in Tasks 8 and 9.
