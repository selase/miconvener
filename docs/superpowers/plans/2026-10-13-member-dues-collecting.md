# Member Dues, Milestone 2: Collecting Dues Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An organisation can define membership classes, add members by hand, raise a dues charge, let the member pay from a personal link (card or mobile money), see who has paid, record cash payments, waive or void charges, and request a payout of the dues balance, with every cedi booked in the `dues` ledger category.

**Architecture:** New landlord tables (`dues_classes`, `members`, `dues_charges`) and a `currency` column on `event_payouts`. Payment reuses the existing Paystack checkout and the `SettlementWebhookController` dispatch by metadata `type`, with a new `member_dues` branch handled by one idempotent service (`DuesPaymentConfirmer`). Ledger postings are new methods on `LedgerService`, built on the category-aware `postTransaction` from milestone 1. The payout send logic is extracted from `EventFinanceController` into `PayoutSender` so event and dues payouts share one path. Everything ships dark behind an explicit `member_dues` feature row granted per tenant by `dues:enable`.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL (landlord connection), Pest 5, Inertia + React console, Paystack.

**Spec:** `docs/superpowers/specs/2026-10-13-member-dues-design.md` (sections 5 to 8 and the milestone 2 line of section 12). Milestone 1 (`docs/superpowers/plans/2026-10-13-ledger-categories-foundation.md`) is merged and deployed.

## Scope

In: classes, manual member add, manual charge creation, pay page `/pay/{token}`, Paystack checkout (platform default and tenant own-gateway Paystack), webhook branch, receipt email, ledger postings, dues balance, dues payout request/send/release, offline recording, waive, void, dark-launch gate, `manage members` permission.

Out (milestone 3 unless noted): `dues:process` generation and reminders, import, overview dashboard, plan caps and feature sync, privacy text, communities page update. Stripe dues: tenants whose own gateway is Stripe see "card payments are not available" on the pay page; Stripe dues is deferred to milestone 3 and must be flagged to the user at hand-off.

## Global Constraints

- Money is integer minor units. Default currency `GHS`. One currency per class; a charge carries its class currency.
- Commission is `config('services.dues.platform_fee_percentage')`, default `1`, uncapped, borne by the organisation (deducted), rounded with `(int) round($amount * $pct / 100)`.
- Offline payments post no ledger entry and take no commission (spec 6.2). No `dues_offline` transaction type is created; spec 4.4 listed it, spec 6.2 governs. Recorded as a ruling.
- Every ledger transaction and payout this milestone writes carries `LedgerCategoryEnum::Dues` and no event id.
- Webhook branch is idempotent: a charge already `paid` is never paid twice; redelivery posts nothing.
- A payment for a charge that is already `paid`, `waived` or `void` is recorded in the log with context `dues.payment.unmatched` and flagged on the charge `note`; no refund logic.
- The pay page reveals only the member's own name and charges. Unknown and rotated tokens return the same 404 page.
- Controllers use Form Request classes; PHPDoc over inline comments; explicit return types; `declare(strict_types=1)`; `final` classes; named routes.
- Tests are Pest feature tests. Start each file with `refreshTenantDatabases()`, seed `RoleSeeder` and `PermissionsSeeder` (see `tests/Feature/Finance/AttendeeBorneFeeTest.php`). Use `eventHost('acme')` and `eventSubdomainHost('acme')` from `tests/Pest.php`.
- Run `vendor/bin/pint --dirty` before each commit. Use Herd's PHP and nvm as in the global toolchain note.
- No change to any existing ticket, contribution or commission-credit behaviour; the existing Finance and Payments tests must stay green after every task.

## Review Focus

1. **Double payment:** webhook redelivered, or the same charge paid twice from two tabs. One payment, one ledger transaction, second is flagged. (Task 6 tests.)
2. **Paying several charges at once:** per-charge commission is rounded individually and the Paystack split share equals the sum, so the ledger and the gateway agree to the pesewa. (Task 6.)
3. **Balance over-draw:** two simultaneous payout requests cannot both pass the balance check; a payout in `scheduled`, `awaiting_otp` or `processing` counts against the balance. (Task 4.)
4. **Wrong-tenant access:** a member token from tenant A on tenant B's domain returns 404; an admin of tenant A cannot see, charge or pay out tenant B's members. (Tasks 5, 6.)
5. **Money for a member who left:** an `exited` member's open charges can still be paid by link or waived, but no new charge can be raised. (Task 5.)

---

## File Structure

| Path | Responsibility |
|---|---|
| `database/migrations/landlord/2026_10_14_100000_create_dues_tables.php` | `dues_classes`, `members`, `dues_charges`, `event_payouts.currency` |
| `app/Models/{DuesClass,Member,DuesCharge}.php` + factories | Eloquent models, relationships, casts |
| `app/Enum/{DuesPeriodEnum,MemberStatusEnum,DuesChargeStatusEnum}.php` | Backed enums |
| `app/Services/Dues/DuesFee.php` | Commission arithmetic, one place |
| `app/Services/Dues/DuesCharges.php` | Create, waive, void, record offline |
| `app/Services/Dues/DuesCheckout.php` | Start a Paystack checkout for chosen charges |
| `app/Services/Dues/DuesPaymentConfirmer.php` | Idempotent webhook/callback confirmation and ledger posting |
| `app/Services/Dues/DuesBalance.php` | Available balance per currency |
| `app/Services/Finance/PayoutSender.php` | Extracted from `EventFinanceController::sendPayout` |
| `app/Console/Commands/Dues/EnableDuesCommand.php` | `dues:enable {tenant} {--off}` |
| `app/Http/Controllers/Tenant/{DuesClassController,MemberController,DuesChargeController,DuesPayoutController}.php` | Console endpoints |
| `app/Http/Controllers/Public/MemberPayController.php` | Public pay page, checkout, callback |
| `app/Http/Requests/Tenant/Dues/*.php` | Form requests |
| `app/Mail/DuesReceipt.php` + `resources/views/emails/dues-receipt.blade.php` | Receipt |
| `resources/js/Pages/Tenant/Dues/{Members,Classes,Payouts}.jsx`, `resources/js/Pages/Public/MemberPay.jsx` | UI |

---

### Task 1: Dark-launch gate, permission and role grants

**Files:**
- Create: `app/Console/Commands/Dues/EnableDuesCommand.php`
- Modify: `app/Models/Tenant.php` (add `duesEnabled()`), `database/seeders/PermissionsSeeder.php`, `app/Libraries/RolePermissions.php`, `app/Http/Middleware/HandleInertiaRequests.php` (share `manage_members`), `config/services.php` (add `dues`)
- Test: `tests/Feature/Dues/DuesGateTest.php`

**Interfaces:**
- Produces: `Tenant::duesEnabled(): bool`; permission `manage members`; shared Inertia prop `auth.can.manage_members`; `config('services.dues.platform_fee_percentage')`; command `dues:enable {tenant} {--off}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantFeature;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('dues are off for a tenant until enabled', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);

    expect($tenant->duesEnabled())->toBeFalse();
});

test('dues:enable switches dues on as an admin override that a feature sync keeps', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);

    $this->artisan('dues:enable', ['tenant' => 'union'])->assertSuccessful();
    expect($tenant->fresh()->duesEnabled())->toBeTrue();

    $row = TenantFeature::where('tenant_id', $tenant->id)->where('feature_key', 'member_dues')->firstOrFail();
    expect($row->meta['source'] ?? null)->toBe('admin_override');

    $this->artisan('tenants:sync-features')->assertSuccessful();
    expect($tenant->fresh()->duesEnabled())->toBeTrue();
});

test('dues:enable --off switches dues back off', function () {
    $tenant = Tenant::factory()->create(['slug' => 'union']);
    $this->artisan('dues:enable', ['tenant' => 'union'])->assertSuccessful();

    $this->artisan('dues:enable', ['tenant' => 'union', '--off' => true])->assertSuccessful();

    expect($tenant->fresh()->duesEnabled())->toBeFalse();
});

test('org superadmin and org admin roles hold manage members, other roles do not', function () {
    [$tenant, $user] = eventHost('acme');

    expect($user->can('manage members'))->toBeTrue();
    expect(config('services.dues.platform_fee_percentage'))->toBe(1.0);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dues/DuesGateTest.php`
Expected: FAIL (`duesEnabled` undefined, command not found, permission missing).

- [ ] **Step 3: Implement**

`config/services.php`, next to `contributions`:

```php
'dues' => [
    'platform_fee_percentage' => (float) env('DUES_PLATFORM_FEE_PERCENTAGE', 1),
],
```

`Tenant`:

```php
public function duesEnabled(): bool
{
    return $this->featureEnabled('member_dues');
}
```

`PermissionsSeeder`: add `manage members` beside `manage payouts`, copying that entry's shape (read the neighbouring entry for the exact keys). `RolePermissions`: add `'manage members'` to the Org Superadmin and Org Admin lists (the two lists that already contain `'manage payouts'`).

`HandleInertiaRequests`, in the `can` array: `'manage_members' => fn (): bool => (bool) $request->user()?->can('manage members'),`.

`EnableDuesCommand`: look up the tenant by slug (fail with a clear message if missing); `TenantFeature::updateOrCreate(['tenant_id' => $t->id, 'feature_key' => 'member_dues'], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']])`. Mirror the exact column set and `meta` handling used by `Admin\TenantController::updateFeature`.

- [ ] **Step 4: Run it to see it pass**

Run: `php artisan test --compact tests/Feature/Dues/DuesGateTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app config database tests
git commit -m "feat(dues): dark-launch gate, manage members permission and fee config"
```

---

### Task 2: Schema, enums, models and factories

**Files:**
- Create: migration `2026_10_14_100000_create_dues_tables.php` (landlord dir), enums, `DuesClass`, `Member`, `DuesCharge`, three factories
- Modify: `app/Models/EventPayout.php` (`currency` fillable), `database/factories/EventPayoutFactory.php` (add `currency => 'GHS'`)
- Test: `tests/Feature/Dues/DuesSchemaTest.php`

**Interfaces:**
- Produces:
  - `DuesPeriodEnum` (`Monthly`, `Quarterly`, `Yearly`; `nextStart(CarbonImmutable $from): CarbonImmutable`; `periodEnd(CarbonImmutable $start): CarbonImmutable`).
  - `MemberStatusEnum` (`Active`, `Lapsed`, `Exited`), `DuesChargeStatusEnum` (`Open`, `Paid`, `Waived`, `Void`).
  - `DuesClass`: fillable `tenant_id, name, amount, currency, period, is_active`; `members(): HasMany`.
  - `Member`: `tenant_id, dues_class_id, name, email, phone, amount_override, status, joined_on, exited_on, pay_token, notes`; `duesClass()`, `charges()`; `effectiveAmount(): int` (override else class amount); `rotatePayToken(): string`; `booted` creating hook fills `pay_token` with `Str::random(40)` when empty.
  - `DuesCharge`: `tenant_id, member_id, dues_class_id, period_start, period_end, due_on, amount, currency, status, paid_at, payment_method, payment_reference, charged_amount, gateway_fee_amount, commission_amount, recorded_by, note`; `member()`, `duesClass()`; scopes `open()`, `overdue()` (open and `due_on` before today).
  - All three use `BelongsToTenant`, `HasUuids`, `HasFactory`, connection `landlord`. First check `ls app/Models | grep -i member` shows no clash (only `EventParticipantGroupMember` exists).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Models\DuesCharge;
use App\Models\DuesClass;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

beforeEach(fn () => refreshTenantDatabases());

test('a member takes the class amount unless overridden, and a zero override is a waiver', function () {
    $tenant = Tenant::factory()->create();
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'amount' => 5000]);

    $plain = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id]);
    $discounted = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id, 'amount_override' => 2000]);
    $waived = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id, 'amount_override' => 0]);

    expect($plain->effectiveAmount())->toBe(5000)
        ->and($discounted->effectiveAmount())->toBe(2000)
        ->and($waived->effectiveAmount())->toBe(0);
});

test('every member gets a unique 40 character pay token, and rotating changes it', function () {
    $member = Member::factory()->create();
    $old = $member->pay_token;

    expect($old)->toHaveLength(40);
    expect($member->rotatePayToken())->not->toBe($old)->toHaveLength(40);
});

test('a charge is unique per member and period start', function () {
    $member = Member::factory()->create();
    DuesCharge::factory()->create(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'dues_class_id' => $member->dues_class_id, 'period_start' => '2026-11-01']);

    expect(fn () => DuesCharge::factory()->create(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'dues_class_id' => $member->dues_class_id, 'period_start' => '2026-11-01']))
        ->toThrow(QueryException::class);
});

test('class names are unique per tenant', function () {
    $tenant = Tenant::factory()->create();
    DuesClass::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);

    expect(fn () => DuesClass::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']))
        ->toThrow(QueryException::class);
});

test('overdue scope returns only open charges past their due date', function () {
    $member = Member::factory()->create();
    $base = ['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'dues_class_id' => $member->dues_class_id];
    DuesCharge::factory()->create($base + ['period_start' => '2026-01-01', 'due_on' => now()->subDay()->toDateString()]);
    DuesCharge::factory()->create($base + ['period_start' => '2026-02-01', 'due_on' => now()->addDay()->toDateString()]);
    DuesCharge::factory()->create($base + ['period_start' => '2026-03-01', 'due_on' => now()->subDay()->toDateString(), 'status' => DuesChargeStatusEnum::Paid]);

    expect(DuesCharge::overdue()->count())->toBe(1);
});

test('an event payout can carry a currency', function () {
    expect(App\Models\EventPayout::factory()->create(['currency' => 'USD'])->currency)->toBe('USD');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dues/DuesSchemaTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

Create models with `php artisan make:model ... --factory --no-interaction`, then fill in. Migration (landlord path; use Postgres `uuid` primary keys and `foreignUuid` consistent with `2026_09_11_160000_create_double_entry_ledger_and_payout_schedules_tables.php`):

```php
Schema::connection('landlord')->create('dues_classes', function (Blueprint $t): void {
    $t->uuid('id')->primary();
    $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
    $t->string('name');
    $t->unsignedBigInteger('amount');
    $t->string('currency', 3)->default('GHS');
    $t->string('period', 16);
    $t->boolean('is_active')->default(true);
    $t->timestamps();
    $t->unique(['tenant_id', 'name']);
});

Schema::connection('landlord')->create('members', function (Blueprint $t): void {
    $t->uuid('id')->primary();
    $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
    $t->foreignUuid('dues_class_id')->constrained('dues_classes')->restrictOnDelete();
    $t->string('name');
    $t->string('email')->nullable();
    $t->string('phone', 32)->nullable();
    $t->unsignedBigInteger('amount_override')->nullable();
    $t->string('status', 16)->default('active');
    $t->date('joined_on')->nullable();
    $t->date('exited_on')->nullable();
    $t->string('pay_token', 40)->unique();
    $t->text('notes')->nullable();
    $t->timestamps();
    $t->index(['tenant_id', 'status']);
    $t->unique(['tenant_id', 'phone']);
});

Schema::connection('landlord')->create('dues_charges', function (Blueprint $t): void {
    $t->uuid('id')->primary();
    $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
    $t->foreignUuid('member_id')->constrained('members')->cascadeOnDelete();
    $t->foreignUuid('dues_class_id')->constrained('dues_classes')->restrictOnDelete();
    $t->date('period_start');
    $t->date('period_end');
    $t->date('due_on');
    $t->unsignedBigInteger('amount');
    $t->string('currency', 3);
    $t->string('status', 16)->default('open');
    $t->timestamp('paid_at')->nullable();
    $t->string('payment_method', 16)->nullable();
    $t->string('payment_reference')->nullable()->index();
    $t->unsignedBigInteger('charged_amount')->nullable();
    $t->unsignedBigInteger('gateway_fee_amount')->nullable();
    $t->unsignedBigInteger('commission_amount')->nullable();
    $t->foreignUuid('recorded_by')->nullable();
    $t->text('note')->nullable();
    $t->timestamps();
    $t->unique(['member_id', 'period_start']);
    $t->index(['tenant_id', 'status', 'due_on']);
});

Schema::connection('landlord')->table('event_payouts', function (Blueprint $t): void {
    $t->string('currency', 3)->default('GHS');
});
DB::connection('landlord')->statement(
    'UPDATE event_payouts SET currency = events.currency FROM events WHERE events.id = event_payouts.event_id AND events.currency IS NOT NULL'
);
```

The migration test runs the unique-phone rule as a plain unique index; Postgres treats NULL phones as distinct, which is the intended behaviour (email-only members). `down()` drops the tables and the column. Match the `tenant_id` column type to the existing tenant key (read `create_event_payouts` migration; it uses `foreignUuid` or `string` accordingly).

Factories: `DuesClassFactory` (name via `fake()->unique()->words(2, true)`, amount 5000, GHS, monthly), `MemberFactory` (tenant `Tenant::factory()`, class `DuesClass::factory()` with the same tenant via `fn (array $a) => ...`, name, phone `fake()->unique()->numerify('024#######')`, email), `DuesChargeFactory` (amount 5000, GHS, `period_start` now()->startOfMonth, `period_end` endOfMonth, `due_on` period_start, status Open). Casts: `period`, `status` to the enums; dates to `date`.

- [ ] **Step 4: Run it to see it pass**

Run: `php artisan test --compact tests/Feature/Dues/DuesSchemaTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app database tests
git commit -m "feat(dues): classes, members and charges tables, models and factories"
```

---

### Task 3: Fee arithmetic and ledger postings

**Files:**
- Create: `app/Services/Dues/DuesFee.php`
- Modify: `app/Models/LedgerTransaction.php` (add `TYPE_DUES_PAYMENT = 'dues_payment'`), `app/Services/Finance/LedgerService.php`, `app/Services/Finance/PlatformEarnings.php` (label `Dues commission`)
- Test: `tests/Feature/Dues/DuesLedgerTest.php`

**Interfaces:**
- Produces:
  - `DuesFee::for(int $amount): int`.
  - `LedgerService::recordDuesPayment(Tenant $tenant, DuesCharge $charge, int $grossAmount, int $platformFee, string $reference, int $gatewayFee = 0): ?LedgerTransaction` (platform default; entries: Debit Gateway Clearing `gross - gatewayFee`; Credit Organizer Payable `gross - gatewayFee - fee`; Credit Platform Revenue `fee` when > 0; category Dues, event null, currency from charge; idempotent via the existing `(tenant, reference, type)` unique index and `findPostedTransaction`).
  - `LedgerService::recordOwnGatewayDuesPayment(Tenant $tenant, DuesCharge $charge, int $grossAmount, int $platformFee, string $reference, int $gatewayFee = 0, string $provider = 'paystack', int $commissionReceived = 0, int $creditUsed = 0): ?LedgerTransaction`.
  - `LedgerService::recordDuesPayout(Tenant $tenant, int $amount, string $reference, EventPayout $payout, string $currency, int $transferFee = 0, bool $platformAbsorbsTransferFee = false): LedgerTransaction`.
- Consumes: `LedgerService::postTransaction(..., ?string $userId, string $currency, ?LedgerCategoryEnum $category)` from milestone 1.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\DuesCharge;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Member;
use App\Services\Dues\DuesFee;
use App\Services\Finance\LedgerService;

beforeEach(fn () => refreshTenantDatabases());

function dueCharge(int $amount = 10000): DuesCharge
{
    $member = Member::factory()->create();

    return DuesCharge::factory()->create([
        'tenant_id' => $member->tenant_id, 'member_id' => $member->id,
        'dues_class_id' => $member->dues_class_id, 'amount' => $amount,
    ]);
}

function payableFor(string $tenantId): int
{
    return LedgerAccount::where('tenant_id', $tenantId)->where('category', LedgerCategoryEnum::Dues)
        ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)->firstOrFail()->currentBalance();
}

test('the dues commission is one percent, rounded, and zero for zero', function () {
    $fee = app(DuesFee::class);

    expect($fee->for(10000))->toBe(100)->and($fee->for(150))->toBe(2)->and($fee->for(0))->toBe(0);
});

test('a platform-default dues payment posts a balanced dues-category transaction', function () {
    $charge = dueCharge(10000);

    $tx = app(LedgerService::class)->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1', 150);

    expect($tx->category)->toBe(LedgerCategoryEnum::Dues)->and($tx->event_id)->toBeNull();
    $debits = LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'debit')->sum('amount');
    $credits = LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'credit')->sum('amount');
    expect((int) $debits)->toBe(9850)->and((int) $credits)->toBe(9850);
    expect(payableFor($charge->tenant_id))->toBe(9750);
});

test('posting the same reference twice does not double the payable', function () {
    $charge = dueCharge(10000);
    $ledger = app(LedgerService::class);

    $ledger->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1', 0);
    $ledger->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1', 0);

    expect(payableFor($charge->tenant_id))->toBe(9900);
});

test('dues payable is kept apart from every other category', function () {
    $charge = dueCharge();
    app(LedgerService::class)->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1');

    expect(LedgerAccount::where('tenant_id', $charge->tenant_id)->where('category', '<>', LedgerCategoryEnum::Dues)->count())->toBe(0);
});

test('an own-gateway dues payment books only the commission and no payable to the organisation', function () {
    $charge = dueCharge(10000);

    $tx = app(LedgerService::class)->recordOwnGatewayDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_2', 0, 'paystack', 100, 0);

    expect($tx->category)->toBe(LedgerCategoryEnum::Dues);
    $revenue = LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'credit')->sum('amount');
    expect((int) $revenue)->toBe(100);
});

test('a dues payout clears the payable and posts in the dues category', function () {
    $charge = dueCharge(10000);
    $ledger = app(LedgerService::class);
    $ledger->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1');
    $payout = EventPayout::factory()->create(['tenant_id' => $charge->tenant_id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues, 'amount' => 9900]);

    $tx = $ledger->recordDuesPayout($charge->tenant, 9900, 'po_1', $payout, 'GHS');

    expect($tx->category)->toBe(LedgerCategoryEnum::Dues)->and($tx->transaction_type)->toBe(LedgerTransaction::TYPE_PAYOUT);
    expect(payableFor($charge->tenant_id))->toBe(0);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dues/DuesLedgerTest.php`
Expected: FAIL (`DuesFee` not found).

- [ ] **Step 3: Implement**

`DuesFee`: `final class`, `for(int $amount): int { return max(0, (int) round($amount * (float) config('services.dues.platform_fee_percentage', 1) / 100)); }`.

`LedgerService`:
- `recordDuesPayment` follows `recordContribution` entry-for-entry (same guards on `gatewayFee`), with `postTransaction($tenant, null, LedgerTransaction::TYPE_DUES_PAYMENT, "Dues: {$charge->member->name} ({$charge->period_start->toDateString()})", $reference, $entries, null, $charge->currency, LedgerCategoryEnum::Dues)`. No `EventLedgerEntry` (dues has no event; the charge row carries the figures). Idempotency: check `findPostedTransaction($tenant, $reference, TYPE_DUES_PAYMENT)` first and return it.
- `recordOwnGatewaySale` is private and event-shaped. Change its first parameter to `Tenant $tenant, ?Event $event` (callers pass `$event->tenant ?? Tenant::find(...)` resolved the same way as today), add a `LedgerCategoryEnum $category = LedgerCategoryEnum::Event` and `string $feeLabel` argument replacing the hard-coded `'Platform commission fee ('.$event->effectivePlatformFeePercentage().'%)'`, pass `$category` to `postTransaction`, and write the `EventLedgerEntry` only when `$event !== null`. `recordOwnGatewayDuesPayment` calls it with `null` event, `LedgerCategoryEnum::Dues`, label `'Dues commission ('.config('services.dues.platform_fee_percentage').'%)'`. The existing ticket and contribution callers keep passing their event and category defaults.
- `recordDuesPayout`: extract the entry-building block of `recordPayout` into `private function payoutEntries(int $amount, int $transferFee, bool $platformAbsorbsTransferFee): array`, make `recordPayout` use it, and have `recordDuesPayout` call `postTransaction($tenant, null, TYPE_PAYOUT, "Dues payout (#{$reference})", $reference, $entries, null, $currency, LedgerCategoryEnum::Dues)`. No `EventLedgerEntry`.

`LedgerTransaction`: `public const TYPE_DUES_PAYMENT = 'dues_payment';`. `PlatformEarnings::COMMISSION_LABELS`: `LedgerTransaction::TYPE_DUES_PAYMENT => 'Dues commission'`. Check how `PlatformEarnings` selects commission-bearing types (a list or a label map); add the new type wherever `TYPE_CONTRIBUTION` appears.

- [ ] **Step 4: Run, then run the regression set**

Run: `php artisan test --compact tests/Feature/Dues/DuesLedgerTest.php`
Expected: PASS (6 tests).
Run: `php artisan test --compact tests/Feature/Finance tests/Feature/Payments`
Expected: PASS, unchanged counts. (This proves the `recordOwnGatewaySale` and `recordPayout` refactors changed nothing.)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app tests
git commit -m "feat(dues): dues fee and category-aware ledger postings"
```

---

### Task 4: Dues balance and a shared payout sender

**Files:**
- Create: `app/Services/Dues/DuesBalance.php`, `app/Services/Finance/PayoutSender.php`, `app/Http/Controllers/Tenant/DuesPayoutController.php`, `app/Http/Requests/Tenant/Dues/StoreDuesPayoutRequest.php`
- Modify: `app/Http/Controllers/Tenant/EventFinanceController.php` (`sendPayout` delegates), `app/Http/Controllers/Public/SettlementWebhookController.php` (`confirmTransfer` branch), `app/Services/Finance/PayoutRelease.php` (no change expected; verify), `routes/subdomain.php`
- Test: `tests/Feature/Dues/DuesPayoutTest.php`; existing `tests/Feature/Finance/PayoutOtpFlowTest.php` and payout tests must stay green

**Interfaces:**
- Produces:
  - `DuesBalance::available(Tenant $tenant, string $currency): int` = dues Organizer Payable `currentBalance()` for that currency, minus the sum of dues-category payouts in `scheduled`, `awaiting_otp`, `processing` for that currency (a `paid` payout is already debited by `recordDuesPayout`).
  - `PayoutSender::send(EventPayout $payout, string $currency): array{status: string, message: string, http_status: int, transfer_code?: string}`: the body of `sendPayout` from "lock" to the final `update`, lifted unchanged, returning data instead of `JsonResponse`. The caller owns the surrounding `DB::transaction` and the lock.
  - Routes `tenant.dues.payouts.index|store|send|finalize`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\DuesCharge;
use App\Models\EventPayout;
use App\Models\Member;
use App\Models\TenantPayoutAccount;
use App\Services\Dues\DuesBalance;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function tenantWithDuesBalance(int $gross = 10000): array
{
    [$tenant, $user] = eventHost('acme');
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);
    $charge = DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'dues_class_id' => $member->dues_class_id, 'amount' => $gross]);
    app(LedgerService::class)->recordDuesPayment($tenant, $charge, $gross, 100, 'ref_seed');
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);

    return [$tenant, $user, $account];
}

test('available balance is the payable less payouts still in flight', function () {
    [$tenant, , $account] = tenantWithDuesBalance(10000);
    EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues, 'payout_account_id' => $account->id, 'amount' => 3000, 'currency' => 'GHS']);

    expect(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(6900);
});

test('a payout request creates a dues payout with no event', function () {
    [$tenant, $user, $account] = tenantWithDuesBalance(10000);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 5000, 'currency' => 'GHS'])
        ->assertCreated();

    $payout = EventPayout::where('tenant_id', $tenant->id)->firstOrFail();
    expect($payout->category)->toBe(LedgerCategoryEnum::Dues)->and($payout->event_id)->toBeNull()->and($payout->currency)->toBe('GHS');
});

test('a payout above the available balance is refused', function () {
    [$tenant, $user, $account] = tenantWithDuesBalance(10000);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 9901, 'currency' => 'GHS'])
        ->assertStatus(422);
});

test('a second request cannot spend what the first one reserved', function () {
    [$tenant, $user, $account] = tenantWithDuesBalance(10000);
    $post = fn (int $amount) => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => $amount, 'currency' => 'GHS']);

    $post(6000)->assertCreated();
    $post(6000)->assertStatus(422);
});

test("another tenant's payout account cannot be used", function () {
    [, $user] = tenantWithDuesBalance(10000);
    $foreign = TenantPayoutAccount::factory()->create(['is_verified' => true]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $foreign->id, 'amount' => 1000, 'currency' => 'GHS'])
        ->assertStatus(422);
});

test('a user without manage payouts cannot request one', function () {
    [$tenant, , $account] = tenantWithDuesBalance(10000);
    $viewer = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($viewer->id);

    $this->actingAs($viewer)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 1000, 'currency' => 'GHS'])
        ->assertForbidden();
});

test('a confirmed transfer for a dues payout marks it paid and posts the dues payout once', function () {
    [$tenant, , $account] = tenantWithDuesBalance(10000);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues,
        'payout_account_id' => $account->id, 'amount' => 9900, 'currency' => 'GHS',
        'status' => EventPayout::STATUS_PROCESSING, 'provider_reference' => 'payout_x_1',
    ]);
    config(['services.settlement.paystack.secret_key' => 'sk_test_x']);
    $body = json_encode(['event' => 'transfer.success', 'data' => ['reference' => 'payout_x_1']]);
    $sig = hash_hmac('sha512', $body, 'sk_test_x');

    $send = fn () => $this->call('POST', route('webhooks.settlement.paystack'), [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body);
    $send()->assertOk();
    $send()->assertOk();

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID);
    expect(app(App\Services\Dues\DuesBalance::class)->available($tenant, 'GHS'))->toBe(0);
});
```

(The webhook test mirrors how existing tests in `tests/Feature/Finance/PayoutOtpFlowTest.php` sign a settlement webhook; copy its exact signing and route setup if it differs from the above.)

- [ ] **Step 2: Run to see failure**

Run: `php artisan test --compact tests/Feature/Dues/DuesPayoutTest.php`
Expected: FAIL (service and route missing).

- [ ] **Step 3: Implement**

`PayoutSender`: move the logic of `sendPayout` between the status check and the end (recipient creation, fee deduction, `initiateTransfer`, otp/failed/processing updates) into `send(EventPayout $payout, string $currency): array`, constructor-injecting `SettlementGateway` and `TransferFeeSchedule`. Map each `response()->json([...], code)` to `['message' => ..., 'http_status' => code, ...]`. `EventFinanceController::sendPayout` keeps its transaction, lock and `authorize`, calls `PayoutSender`, and returns `response()->json(Arr::except($result, 'http_status'), $result['http_status'])`. Behaviour and response bodies must be byte-identical; `PayoutOtpFlowTest` and the event payout tests are the proof.

`DuesBalance::available`: sum as in the interface. Use `LedgerAccount::query()->where(tenant, category dues, code organizer payable, currency)->first()?->currentBalance() ?? 0`, minus `EventPayout::where('tenant_id')->where('category', Dues)->where('currency')->whereIn('status', [Scheduled, AwaitingOtp, Processing])->sum('amount')`.

`StoreDuesPayoutRequest`: rules `payout_account_id` required, `Rule::exists('tenant_payout_accounts', 'id')->where('tenant_id', $tenantId)`; `amount` required integer min 1; `currency` required size 3; `scheduled_at`, `note` optional. `authorize()` returns `$this->user()->can('manage payouts')`.

`DuesPayoutController`:
- `index`: authorize `read finance`; Inertia render `Tenant/Dues/Payouts` with per-currency balances, accounts, payout history (category dues). Wire the React page in Task 8; here return the props.
- `store`: inside `DB::transaction`, `lockForUpdate` the tenant's dues `LedgerAccount` for the currency (create via `ensureAccountsExist($tenant, null, $currency, Dues)` first) so concurrent requests serialise; recheck `DuesBalance::available`; create `EventPayout` with `category` Dues, `event_id` null, `currency`; 201 `{id}` or 422 `{message}`.
- `send` and `finalize`: same shape as the event versions, locking the payout and using `PayoutSender` and `PayoutRelease`; scoped to `tenant_id` and `category = Dues`.

`SettlementWebhookController::confirmTransfer`: on success, branch on `$payout->category`:

```php
$ledger = app(\App\Services\Finance\LedgerService::class);

if ($payout->category === LedgerCategoryEnum::Dues) {
    $ledger->recordDuesPayout($payout->tenant, (int) $payout->amount, $reference, $payout, (string) $payout->currency);

    return;
}
```

(`$payout->tenant` needs a `tenant()` relation on `EventPayout`; add it if absent. `recordDuesPayout` is idempotent through `findPostedTransaction`; add that check inside it as in `recordDuesPayment`.) Other categories continue through the existing `recordPayout($payout->event, ...)`; a `commission_credit` payout with no event is handled by its own existing path and must not reach this line; add a guard that returns early with a log if `$payout->event` is null and the category is not Dues.

Routes (inside the authenticated tenant group next to `payout-accounts`):

```php
Route::get('dues/payouts', [DuesPayoutController::class, 'index'])->name('tenant.dues.payouts.index');
Route::post('dues/payouts', [DuesPayoutController::class, 'store'])->name('tenant.dues.payouts.store');
Route::post('dues/payouts/{payout}/send', [DuesPayoutController::class, 'send'])->name('tenant.dues.payouts.send');
Route::post('dues/payouts/{payout}/finalize', [DuesPayoutController::class, 'finalize'])->name('tenant.dues.payouts.finalize');
```

All dues console routes in this plan sit behind a `dues.enabled` middleware (create `EnsureDuesEnabled`: `abort(404)` unless `app(TenantContext::class)->getTenant()?->duesEnabled()`), so a dark tenant gets 404 on every dues URL. Register it in `app/Http/Kernel.php`. Add one test that a tenant without the feature gets 404 on `tenant.dues.payouts.index`, and make `tenantWithDuesBalance()` enable dues (`$tenant->features()->create([... 'member_dues' enabled ...])` or call `dues:enable`).

- [ ] **Step 4: Run to see pass, then regressions**

Run: `php artisan test --compact tests/Feature/Dues/DuesPayoutTest.php tests/Feature/Finance`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app routes tests
git commit -m "feat(dues): dues balance, payout request and a shared payout sender"
```

---

### Task 5: Classes, members and charge actions (console back end)

**Files:**
- Create: `app/Services/Dues/DuesCharges.php`, `app/Http/Controllers/Tenant/{DuesClassController,MemberController,DuesChargeController}.php`, Form Requests `StoreDuesClassRequest`, `UpdateDuesClassRequest`, `StoreMemberRequest`, `UpdateMemberRequest`, `StoreDuesChargeRequest`, `RecordOfflineDuesPaymentRequest` (all in `app/Http/Requests/Tenant/Dues/`)
- Modify: `routes/subdomain.php`
- Test: `tests/Feature/Dues/DuesClassesTest.php`, `tests/Feature/Dues/MembersTest.php`, `tests/Feature/Dues/DuesChargeActionsTest.php`

**Interfaces:**
- Produces:
  - `DuesCharges::createFor(Member $member, CarbonImmutable $periodStart): DuesCharge` (period end from class period; `due_on` = `periodStart`; amount frozen from `effectiveAmount()` at creation; status `Waived` when the amount is 0; throws `DomainException('This member has left and cannot be charged.')` for an `exited` member; idempotent on `(member_id, period_start)`: returns the existing charge).
  - `DuesCharges::waive(DuesCharge $c, ?string $note): DuesCharge`, `void(DuesCharge $c, ?string $note): DuesCharge` (open only; otherwise `DomainException`), `recordOffline(DuesCharge $c, string $userId, ?string $note): DuesCharge` (open only; sets `paid`, `payment_method = offline`, `paid_at`, `recorded_by`, no ledger posting).
  - Routes under `tenant.dues.*`: `classes.index|store|update`, `members.index|store|update|exit|rotate-link`, `charges.store|waive|void|record-offline`.
- Authorisation: every action `authorize('manage members')`; all queries scoped by `tenant_id` from `TenantContext`.

- [ ] **Step 1: Write the failing tests** (one file each; representative cases shown, write the full set)

```php
// DuesClassesTest.php
test('an admin creates a class and sees it listed', function () { /* POST tenant.dues.classes.store {name, amount, currency, period} -> 201, row exists with tenant_id */ });
test('a class name already used in the organisation is rejected', function () { /* second POST same name -> 422 name error */ });
test('a class with members cannot be deleted but can be deactivated', function () { /* PATCH is_active=false -> ok; no delete route exists, assert route not defined */ });
test('another tenant cannot edit this tenant\'s class', function () { /* acting as acme admin, PATCH class of tenant beta -> 404 */ });
test('a user without manage members is forbidden', function () { /* 403 */ });

// MembersTest.php
test('a member with neither email nor phone is rejected', function () { /* 422 */ });
test('a member needs an active class of the same organisation', function () { /* foreign class id -> 422 */ });
test('a duplicate phone in the same organisation is rejected, the same phone in another is fine', function () {});
test('rotating the pay link changes the token and the old one stops working', function () { /* assert new token differs; old token GET /pay/{old} is 404 (completed in Task 6; assert token differs here) */ });
test('exiting a member sets status and exit date and keeps their charges', function () {});

// DuesChargeActionsTest.php
test('creating a charge freezes the amount at the time', function () {
    // class 5000; createFor -> 5000; then class amount changed to 9000; charge still 5000.
});
test('a zero override produces a waived charge', function () {});
test('creating a charge twice for the same period returns the same charge', function () {});
test('an exited member cannot be charged', function () { /* DomainException surfaced as 422 */ });
test('recording an offline payment marks paid, posts no ledger entry and takes no commission', function () {
    // assert LedgerTransaction count unchanged, commission_amount null/0, payment_method offline, recorded_by user id.
});
test('only an open charge can be waived, voided or recorded', function () { /* paid charge -> 422 on all three */ });
test('a charge of another tenant cannot be acted on', function () { /* 404 */ });
```

Each test body follows the pattern in `DuesPayoutTest.php`: `eventHost`, enable dues, `actingAs`, `withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])`, `postJson(route(...))`. Write them out in full when executing; none may be left as a comment.

- [ ] **Step 2: Run to see failure**

Run: `php artisan test --compact tests/Feature/Dues/DuesClassesTest.php tests/Feature/Dues/MembersTest.php tests/Feature/Dues/DuesChargeActionsTest.php`
Expected: FAIL (routes missing).

- [ ] **Step 3: Implement**

Form Requests with array rules (match `app/Http/Requests/Tenant/*`). Key rules:
- Class: `name` required string max 120, `Rule::unique('dues_classes')->where('tenant_id', $tenantId)->ignore($id)`; `amount` integer min 1; `currency` in the app's supported list (read how `Event` validates `currency`); `period` `Rule::enum(DuesPeriodEnum::class)`; `is_active` boolean on update.
- Member: `name` required; `email` nullable email; `phone` nullable string max 32; `required_without_all:email,phone` on both with a custom message "Add an email or a phone number so we can reach this member."; `dues_class_id` exists scoped to tenant and `is_active = true`; `amount_override` nullable integer min 0; phone unique per tenant (`Rule::unique('members','phone')->where('tenant_id', ...)->ignore($id)`); `joined_on` nullable date.
- Charge: `member_id` exists in tenant; `period_start` required date.
- Offline: `note` nullable string max 255.

Controllers return Inertia pages for `index` (props in Task 8) and JSON/redirect for writes, following `TeamController` and `TenantPayoutAccountController` for the response convention (read both first; mirror whichever Inertia-form style they use). Custom messages in every Form Request.

`DuesCharges` as specified; period math in `DuesPeriodEnum`: `Monthly` adds one month, `Quarterly` three, `Yearly` twelve; `periodEnd` = next start minus one day. Wrap `createFor` in `firstOrCreate(['member_id', 'period_start'], [...])`. `waive`, `void`, `recordOffline` use `DB::transaction` + `lockForUpdate` on the charge row, and throw `DomainException` unless `status === Open`; controllers catch it and return 422 with the message.

Routes inside the authenticated tenant group, wrapped in `Route::middleware('dues.enabled')->prefix('dues')->name('tenant.dues.')->group(...)` (reconcile with the Task 4 route names: use this single group for all dues console routes).

- [ ] **Step 4: Run to see pass**

Run: the three files above.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app routes tests
git commit -m "feat(dues): classes, members and charge actions for admins"
```

---

### Task 6: Pay page, checkout, webhook branch and confirmation

**Files:**
- Create: `app/Services/Dues/{DuesCheckout,DuesPaymentConfirmer}.php`, `app/Http/Controllers/Public/MemberPayController.php`, `app/Mail/DuesReceipt.php`, `resources/views/emails/dues-receipt.blade.php`
- Modify: `routes/subdomain.php`, `app/Http/Controllers/Public/SettlementWebhookController.php`, `app/Providers/RouteServiceProvider.php` or wherever rate limiters live (add `dues-pay` limiter, 30/min per IP)
- Test: `tests/Feature/Dues/MemberPayPageTest.php`, `tests/Feature/Dues/DuesPaymentConfirmationTest.php`

**Interfaces:**
- Produces:
  - `DuesCheckout::start(Member $member, array $chargeIds, string $callbackUrl): string` (returns the Paystack authorization URL). Loads the member's `open` charges among `$chargeIds` (all must be open, same currency, else `InvalidArgumentException`); `platformShare = sum(DuesFee::for(charge.amount))`; gateway via `TenantPaystackResolver::gatewayFor($tenant, $platformShare, $currency)` (null → `RuntimeException('Online payments are not currently configured for this organisation.')`); customer via `createCustomer`; `createOneTimeCheckoutSession($customerId, $total, $currency, $callbackUrl, $metadata)` with metadata `['type' => 'member_dues', 'tenant_id', 'member_id', 'charge_ids' => [...], 'source' => config('services.paystack.metadata_source'), 'commission_split' => $paystack->splitSubaccount(), 'commission_credit_applied' => $paystack->commissionCreditApplied()]`. Tenants whose settlement is Stripe throw `RuntimeException('Card payments are not available for this organisation yet.')`.
  - `DuesPaymentConfirmer::confirm(string $reference, array $gatewayData): array<int, DuesCharge>` (idempotent; returns the charges it paid).
  - Routes: `GET /pay/{token}` `public.dues.show`, `POST /pay/{token}/checkout` `public.dues.checkout` (throttle `dues-pay`), `GET /pay/{token}/callback` `public.dues.callback`, `GET /pay/{token}/receipt` `public.dues.receipt`.
- Consumes: `DuesFee`, `LedgerService::recordDuesPayment`, `recordOwnGatewayDuesPayment`, `WebhookController::commissionSettlement`, `TenantPaystackResolver`, `Tenant::isPlatformDefaultSettlement()`.

- [ ] **Step 1: Write the failing tests**

Pay page (`MemberPayPageTest.php`): `Http::fake()` Paystack calls as `tests/Feature/Finance/AttendeeBorneFeeTest.php` does for checkout.

```php
test('a valid link shows the member their open charges and nothing about other members', function () {});
test('an unknown token and a rotated token both return the same 404', function () {
    // two requests -> same status and same body.
});
test('a token from another organisation\'s domain returns 404', function () {});
test('checkout asks Paystack for the total and the summed commission share', function () {
    // two open charges 10000 + 5050 -> Http::assertSent: amount 15050,
    // when own-gateway split: transaction_charge == 15050 - (100 + 51)  [round(50.5)=51? use DuesFee value]
});
test('a charge that is not open cannot be sent to checkout', function () {});
test('checkout is throttled', function () { /* 31st request in a minute -> 429 */ });
test('a stripe-only organisation sees card payments are not available', function () {});
```

Confirmation (`DuesPaymentConfirmationTest.php`):

```php
test('a successful charge event pays the charge, posts the ledger and queues a receipt', function () {
    // Mail::fake(); webhook charge.success metadata type member_dues, charge_ids [id], amount 10000, fees 150.
    // assert charge paid, payment_method online, charged_amount 10000, gateway_fee_amount 150, commission_amount 100,
    // LedgerTransaction dues_payment exists, Mail::assertQueued(DuesReceipt::class).
});
test('redelivering the same webhook changes nothing', function () {
    // two deliveries -> one ledger transaction, one queued mail, paid_at unchanged.
});
test('paying two charges at once splits the gateway fee in proportion and balances to the pesewa', function () {
    // amounts 10000 and 5050, fees 151: per-charge fees must sum to exactly 151; ledger payable total == 15050 - 151 - (100+51).
});
test('a payment for a charge already paid is flagged and posts nothing', function () {
    // charge paid offline first; webhook arrives -> no new ledger tx, note contains 'received again', Log context dues.payment.unmatched.
});
test('a payment for a voided charge is flagged and posts nothing', function () {});
test('an own-gateway organisation books only the commission', function () {
    // tenant settlement_mode own_gateway; metadata commission_split set -> recordOwnGatewayDuesPayment used; no organiser payable entry.
});
test('the callback verifies with Paystack and confirms when the webhook has not arrived yet', function () {});
test('a callback whose verified amount is lower than the charges is refused', function () {});
test('a signature that does not verify is rejected before anything is read', function () {});
```

- [ ] **Step 2: Run to see failure**

Run: `php artisan test --compact tests/Feature/Dues/MemberPayPageTest.php tests/Feature/Dues/DuesPaymentConfirmationTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`DuesPaymentConfirmer::confirm`:
1. `$ids = (array) ($gatewayData['metadata']['charge_ids'] ?? [])`; return `[]` if empty.
2. `DB::connection('landlord')->transaction`: `DuesCharge::whereIn('id', $ids)->where('tenant_id', $metadata tenant_id)->lockForUpdate()->get()`. Also require `member_id` to equal the metadata `member_id` (a forged id list must not pay another member's charge).
3. Partition: open charges are paid; others are logged with `Log::warning('dues.payment.unmatched', [...])` and get `note` appended "Payment {reference} received again after this charge was {status}." Stop idempotently when every charge is already `paid` with this `payment_reference`.
4. Apportion `gross` = `gatewayData['amount']` and `fees`: per-charge gateway fee = `intdiv($fees * $charge->amount, $sumOfPaidChargeAmounts)`, remainder added to the last charge, so shares sum exactly. Commission per charge = `DuesFee::for($charge->amount)`.
5. Per charge: update status/`paid_at`/`payment_method=online`/`payment_reference`/`charged_amount`/`gateway_fee_amount`/`commission_amount`; then ledger: platform default → `recordDuesPayment($tenant, $charge, $charge->amount, $commission, "{$reference}:{$charge->id}", $feeShare)`; otherwise compute once `$settlement = WebhookController::commissionSettlement($tenant, $currency, $provider, $metadata, $totalCommission)` and split `received`/`credit` across charges in order (allocate `min(remaining, commission)` to each) before `recordOwnGatewayDuesPayment`.
6. After commit: `Mail::to($member->email)->queue(new DuesReceipt($member, $paidCharges, $reference))` when the member has an email.

`DuesCheckout::start` as specified. `MemberPayController`:
- `show`: resolve member by token **and** current tenant (`Member::where('tenant_id', $tenant->id)->where('pay_token', $token)->first()`), 404 otherwise via `abort(404)` (same view for all misses). Render Inertia `Public/MemberPay` with tenant name/logo, member first name only plus full name, open charges (id, period label, amount, due date, overdue flag), `canPayOnline`. Exited members still see and can pay open charges.
- `checkout`: validate `charge_ids` array (min 1) belonging to the member; `try { $url = start(...); return Inertia::location($url); } catch (InvalidArgumentException|RuntimeException|PaymentFailedException $e) { back()->with('error', ...) }`.
- `callback`: mirror `EventContributionController::callback`: verify via the tenant's gateway (`TenantPaystackResolver::gatewayFor($tenant, 0)`), check `status === success`, currency equals the charges' currency and `amount >= total` of the metadata charges, then `DuesPaymentConfirmer::confirm`; redirect to `public.dues.receipt`. On unverifiable, redirect to `show` with an info flash "We are finalising your payment."
- `receipt`: shows the member's paid charges, most recent first.

Webhook: add to the `match`: `'member_dues' => $this->confirmDuesCharge($data, $data['reference'] ?? null),` and

```php
private function confirmDuesCharge(array $data, ?string $reference): void
{
    if (! $reference) {
        return;
    }

    $data['metadata'] = BillingWebhookController::metadata($data['metadata'] ?? null);
    $data['provider'] = 'paystack';

    app(\App\Services\Dues\DuesPaymentConfirmer::class)->confirm($reference, $data);
}
```

Also add `'member_dues'` to `isSettlementEvent()`'s recognised types (read lines 128 to 155 and extend whatever list `event_contribution` appears in) so the signature and ownership checks treat it like other settlement charges.

`DuesReceipt`: queued `Mailable` using the tenant's white-label display name and Reply-To; copy the pattern from `EventRegistrationConfirmed` (read it for the from/reply-to helper). Subject "Receipt: {tenant name} dues".

Routes (public group in `routes/subdomain.php`, after the event routes); add the throttle `dues-pay` where `public-registration` is defined.

- [ ] **Step 4: Run to see pass, then regressions**

Run: `php artisan test --compact tests/Feature/Dues tests/Feature/Finance tests/Feature/Payments`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app routes resources/views tests
git commit -m "feat(dues): member pay page, checkout, webhook branch and receipt"
```

---

### Task 7: Console and public pages (React)

**Files:**
- Create: `resources/js/Pages/Tenant/Dues/Members.jsx`, `resources/js/Pages/Tenant/Dues/Classes.jsx`, `resources/js/Pages/Tenant/Dues/Payouts.jsx`, `resources/js/Pages/Public/MemberPay.jsx`
- Modify: `resources/js/Layouts/ConsoleLayout.jsx` (nav items), `app/Http/Controllers/Tenant/{MemberController,DuesClassController}.php` (`index` props), `app/Http/Middleware/HandleInertiaRequests.php` (ensure `tenant.features.member_dues` is shared; read how `features` is built at line 73 and include the key)
- Test: `tests/Feature/Dues/DuesPagesRenderTest.php` (Inertia assertions) plus `npm run build` and `npm run lint`

**Interfaces:**
- Consumes the Inertia props produced by the Task 4 and Task 5 controllers:
  - `Members`: `members` (paginated: `id, name, email, phone, status, class_name, effective_amount, currency, amount_override, open_total, overdue, pay_url`), `classes` (active), `filters`.
  - `Classes`: `classes` (`id, name, amount, currency, period, is_active, members_count`).
  - `Payouts`: `balances` (`[{currency, available}]`), `accounts`, `payouts`.
  - `MemberPay`: as Task 6.
- Nav (`ConsoleLayout.jsx` `NAV_ITEMS`): add two items after Finance, using lucide icons already imported elsewhere or add `UsersRound` and `HandCoins`:

```jsx
{ label: 'Members', href: 'tenant.dues.members.index', icon: UsersRound, feature: 'member_dues', permission: 'manage_members' },
{ label: 'Dues payouts', href: 'tenant.dues.payouts.index', icon: HandCoins, feature: 'member_dues', permission: 'manage_payouts' },
```

- [ ] **Step 1: Write the failing render test**

```php
test('members page renders its props for an enabled organisation', function () {
    // enable dues; create class + 2 members; GET tenant.dues.members.index as admin;
    // assertInertia(fn ($page) => $page->component('Tenant/Dues/Members')->has('members.data', 2)->has('classes', 1));
});
test('the members page is a 404 while dues are off', function () {});
test('the pay page component receives only the member\'s own charges', function () {
    // two members with charges; GET /pay/{tokenA}; assert props contain A's charge ids only.
});
test('the shared tenant features include member_dues only when enabled', function () {});
```

- [ ] **Step 2: Run to see failure**, then implement the controllers' `index` props.

Run: `php artisan test --compact tests/Feature/Dues/DuesPagesRenderTest.php`
Expected: FAIL, then PASS after props exist.

- [ ] **Step 3: Build the pages**

Follow `resources/js/Pages/Tenant/Team/Index.jsx`: `ConsoleLayout`, `PageHeader` with an action `Button`, the console `Table`, `Drawer` for a member's detail (charges list with Waive, Void and "Record cash payment" actions through `ConfirmModal`, a "Copy pay link" `CopyField`, "Rotate link" and "Mark as exited"), `Modal` forms for add/edit member and add/edit class using the console `Input`, `Select`, `Button`, and `router.post/patch` with `onError` toasts through `useToast`. Money is shown through the existing formatter used in `Tenant/Finance/Index.jsx` (reuse it; do not write a new one). `Payouts.jsx` mirrors the event finance payout list: balance per currency, "Request payout" modal (account, amount), Send and one-time-code entry using the existing OTP modal pattern from `Tenant/Finance` / event finance page. `MemberPay.jsx` is a standalone mobile-first page (no console layout): tenant name, "Hello {first name}", list of charges with checkboxes (all open selected), total, "Pay {currency} {total}" button posting `charge_ids`, overdue badge, and the invalid-link state is the shared 404 page.

- [ ] **Step 4: Verify**

Run (with nvm activated): `npm run lint` and `npm run build`
Expected: both succeed with no new warnings.
Run: `php artisan test --compact tests/Feature/Dues`
Expected: PASS.
Then open the pages in a browser against the Herd site with dues enabled on a local tenant (`php artisan dues:enable <slug>`) and check: add class, add member, create charge, copy link, open `/pay/{token}` on a phone-width window, record a cash payment, request a payout. Report anything not exercised.

- [ ] **Step 5: Commit**

```bash
npx prettier --write resources/js/Pages/Tenant/Dues resources/js/Pages/Public/MemberPay.jsx
git add resources tests app
git commit -m "feat(dues): members, classes, payouts and pay-page UI"
```

---

### Task 8: Oversight, verification, static analysis and full suite

**Files:**
- Modify: `app/Console/Commands/Finance/VerifyLedgerCategories.php` only if it misses dues (read it; it should already accept dues rows), `phpstan` baseline untouched
- Test: `tests/Feature/Dues/DuesEndToEndTest.php`

- [ ] **Step 1: Write the end-to-end test**

One test walks the whole story with real services and faked HTTP: enable dues, create class, add member, create charge, load `/pay/{token}`, start checkout, deliver a signed `charge.success` webhook, assert the charge is paid, the receipt is queued, the dues payable equals `amount - fee - commission`, request a payout of the balance, deliver `transfer.success`, assert the payout is `paid`, the available balance is 0, and `php artisan finance:verify-categories` exits 0. A second test repeats it for an own-gateway tenant and asserts the organisation's payable stays 0 and platform revenue equals the commission.

- [ ] **Step 2: Run, fix what it exposes**

Run: `php artisan test --compact tests/Feature/Dues/DuesEndToEndTest.php`
Expected: PASS after any wiring fixes. Any fix gets its own regression test in the earlier task's file.

- [ ] **Step 3: Static analysis and style**

Run: `vendor/bin/pint` then `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no errors. Fix at the source (typed relations, docblocks, `blank()`); do not baseline.

- [ ] **Step 4: Full suite**

Run: `php artisan test --parallel --processes=4 --compact`
Expected: all green. Name any failure in the report, including ones not caused by this work.

- [ ] **Step 5: Mandatory self-review (CLAUDE.md), then commit**

Answer in the hand-off message, honestly: dependencies used; spec 5 to 8 coverage versus the Scope section; edge cases (empty, null, cross-tenant, exited member, zero amounts, double delivery); dead code or stubs; tests per public method and route; touchpoints updated (seeders, permissions, nav, `.env.example` for `DUES_PLATFORM_FEE_PERCENTAGE`, `AGENTS.md`/memory file `member_dues_design_decisions.md`); readability.

```bash
git add -A
git commit -m "test(dues): end-to-end dues collection and payout"
```

---

## Rollout notes (not tasks)

- Deploys dark: no tenant sees dues until `php artisan dues:enable <slug>` runs on production for that tenant. Enable first for a probe or internal tenant.
- The migration adds `event_payouts.currency` with a default and a backfill from `events`; run `finance:verify-categories` after deploy as for milestone 1.
- Before enabling for a real organisation: Paystack confirmation that dues collection is within the approved business use (spec section 13).
- Milestone 3 picks up: `dues:process`, reminders, import, overview, caps and feature sync, privacy text, communities page and Stripe dues.

## Self-review against the spec

- Spec 5.1 to 5.3 tables: Task 2. 5.4 `dues_reminders` and 5.5 `tenant_dues_settings`: milestone 3 (reminders and settings are only used there).
- Spec 6.1 collecting: Task 6. 6.2 ledger: Task 3 (offline posts nothing, per ruling above). 6.3 payouts: Task 4; oversight page already shows category from milestone 1.
- Spec 8.2 pay page, rate limit, same 404, receipt: Task 6. 8.3 permission: Task 1. 8.1 screens for members, classes, charges, payouts: Tasks 5 and 7; overview, reminders and import screens are milestone 3.
- Spec 8.4 plan cap and feature sync: milestone 3; until then the explicit `member_dues` row is the only gate.
- Spec 10 edge cases covered here: payment for paid/void charge (Task 6), member without contact (Task 5), webhook before redirect (Task 6), class change mid-period (Task 5).
- Type consistency: `DuesFee::for`, `DuesCharges::{createFor,waive,void,recordOffline}`, `LedgerService::{recordDuesPayment,recordOwnGatewayDuesPayment,recordDuesPayout}`, `DuesBalance::available`, `PayoutSender::send` are named identically wherever used.
- Known soft spots to confirm while executing (each is a read-first step, not a guess): exact `TenantFeature` columns, the `isSettlementEvent` list, white-label mail helper name, Inertia response convention in existing tenant controllers, `tenant_id` column type in migrations.
