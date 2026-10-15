# Billing Emails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tell tenants, in branded email, when a platform payment succeeds, when a subscription payment fails, when a plan will not renew, and when a plan has ended — exactly once per event, however many times Paystack or the browser reports it.

**Architecture:** First make "one payment = one recorded transaction" true: the browser callback and the webhook currently key the same payment on different identifiers (Paystack's numeric id vs its reference string), so token packs and subscriptions can be fulfilled twice. Then a `billing_emails` table with a unique `(type, dedupe_key)` gives every email a durable once-only guard, and a `BillingNotifier` service builds four markdown mailables (inheriting the MiConvener logo header) and queues them to the payer and the tenant's contact address.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 5, PostgreSQL (landlord connection), Laravel markdown mail, Paystack.

**Spec:** No separate spec. Requirements settled in conversation 2026-09-14/15 and restated under Decisions.

## Decisions (binding)

| Decision | Value |
|---|---|
| Emails | payment receipt · payment failed · plan ending (not renewing) · plan ended (moved to Free) |
| Receipt covers | subscription charges (metadata `plan_subscription` and legacy Paystack plan codes), invoice payments, AI token packs |
| First-subscription email | the receipt says the plan is active; the browser-only `SubscriptionConfirmedMail` is removed so one payment never sends two emails, and mobile-money payers (whose browser often never returns) still get one |
| Recipients | the payer's email (Paystack customer email, or the signed-in user on the callback) plus `tenants.email`; lower-cased and de-duplicated; none → log and skip |
| Once-only key | receipt `receipt:{paystack reference}` · failed `payment_failed:{invoice_code}` (fallback `{subscription_code}:{amount}:{Y-m-d}`) · ending `subscription_ending:{subscription_code}` · ended `subscription_ended:{subscription_code}` |
| Metering | billing emails are transactional: never count against email credits, no opt-out |
| Branding | markdown `<x-mail::message>` layout, which renders the MiConvener logo header |
| Money display | `CURRENCY 1,234.56` from integer minor units, e.g. `GHS 99.00` |
| Queueing | mailables implement `ShouldQueue`; the once-only row is written before queueing and removed if queueing throws, so a failure can be retried |

## Global Constraints

- Money is integer minor units everywhere; format only for display.
- `Model::shouldBeStrict()` is on: every written attribute must be fillable.
- Billing tables live on the `landlord` connection; migrations in `database/migrations/landlord/`.
- PostgreSQL only; the test suite runs on Postgres (`miconvener_testing`).
- Pest; run `"$PHP" artisan test --compact --filter=...`; `vendor/bin/pint --dirty` before every commit.
- PHP style: curly braces always, explicit return types, constructor promotion, PHPDoc over inline comments.
- No new dependencies. No `intl`-based `Number::currency` (not guaranteed in CI).
- `PHP="$HOME/Library/Application Support/Herd/bin/php"`.

## File Structure

| Path | Responsibility |
|---|---|
| `app/Services/Payment/PaystackGateway.php` (modify) | `verifyTransaction()` also returns `reference`, `channel`, `authorization`, `paid_at` |
| `app/Http/Controllers/Billing/CallbackController.php` (modify) | fulfil and provision under the Paystack reference; invoice path uses `PaymentFulfillmentService::fulfillInvoice`; notify receipts; stop sending `SubscriptionConfirmedMail` |
| `app/Services/Billing/SubscriptionProvisioningService.php` (modify) | `provision()` is idempotent by `transaction_id` and returns `bool` |
| `database/migrations/landlord/2026_09_15_090000_make_transaction_references_unique.php` (create) | unique index on `transactions.provider_transaction_id` |
| `database/migrations/landlord/2026_09_15_090001_create_billing_emails_table.php` (create) | once-only ledger of billing emails |
| `app/Models/BillingEmail.php` (create) | model |
| `app/Services/Billing/BillingEmailSender.php` (create) | claim the once-only key, then queue |
| `app/Services/Billing/BillingNotifier.php` (create) | resolve recipients, describe the payment, build and send the four emails |
| `app/Mail/Billing/PaymentReceiptMail.php` + `resources/views/emails/billing/payment-receipt.blade.php` (create) | receipt |
| `app/Mail/Billing/PaymentFailedMail.php` + `resources/views/emails/billing/payment-failed.blade.php` (create) | failed payment |
| `app/Mail/Billing/SubscriptionEndingMail.php` + `resources/views/emails/billing/subscription-ending.blade.php` (create) | plan will not renew |
| `app/Mail/Billing/SubscriptionEndedMail.php` + `resources/views/emails/billing/subscription-ended.blade.php` (create) | plan ended |
| `app/Http/Controllers/Billing/WebhookController.php` (modify) | call the notifier from the charge, failed, not_renew and disable handlers |
| `app/Mail/SubscriptionConfirmedMail.php`, `resources/views/emails/subscription-confirmed.blade.php` (delete) | superseded by the receipt |
| Tests: `tests/Feature/Billing/PaymentReferenceConsistencyTest.php`, `tests/Feature/Billing/BillingEmailSenderTest.php`, `tests/Feature/Billing/BillingEmailsTest.php` (create) | |

---

### Task 1: One payment, one reference, one transaction

**Why:** `PaystackGateway::verifyTransaction()` returns `transaction_id => $data['id']` (numeric). The callback fulfils token packs and provisions subscriptions under that id; the webhook uses `$data['reference']`. `PaymentFulfillmentService::alreadyRecorded()` compares the two and never matches, so a token pack whose buyer's browser returns is credited twice, and `SubscriptionProvisioningService::provision()` has no duplicate check at all. The existing "cannot both credit" test calls the service twice with one string and never exercises the real callback.

**Files:** modify `PaystackGateway.php` (`verifyTransaction`), `CallbackController.php`, `SubscriptionProvisioningService.php`; create the unique-index migration; test `tests/Feature/Billing/PaymentReferenceConsistencyTest.php`.

**Interfaces — Produces:**
- `verifyTransaction(string $reference): array{status: string, metadata: array, transaction_id: int|string, reference: string, amount: int, currency: string, channel: ?string, authorization: array, paid_at: ?string}`
- `SubscriptionProvisioningService::provision(Tenant $tenant, array $dto): bool` — `false` when a transaction with `$dto['transaction_id']` already exists (nothing changed).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\SubscriptionProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['services.paystack.secret_key' => 'sk_one_account', 'services.settlement.paystack.secret_key' => 'sk_one_account', 'services.paystack.metadata_source' => 'miconvener']);
});

function referenceTenant(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'llm_topup_balance' => 0]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');

    return [$tenant, $user, 'acme.'.mb_ltrim((string) config('session.domain'), '.')];
}

function fakeVerifiedPaystackCharge(array $metadata): void
{
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
        'id' => 4242424242, 'reference' => 'ref_same_payment', 'status' => 'success', 'amount' => 50000,
        'currency' => 'GHS', 'channel' => 'card', 'paid_at' => '2026-09-15T10:00:00Z',
        'authorization' => ['last4' => '4081', 'brand' => 'visa'],
        'customer' => ['email' => 'owner@example.com'], 'metadata' => $metadata,
    ]])]);
}

function signedCharge(array $metadata): void
{
    $body = json_encode(['event' => 'charge.success', 'data' => [
        'id' => 4242424242, 'reference' => 'ref_same_payment', 'status' => 'success', 'amount' => 50000,
        'currency' => 'GHS', 'customer' => ['email' => 'owner@example.com'], 'metadata' => $metadata,
    ]]);
    test()->call('POST', '/webhooks/paystack', [], [], [], ['HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_one_account'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
}

test('a token pack reported by both the browser and the webhook is credited once', function (): void {
    [$tenant, $user, $host] = referenceTenant();
    $metadata = ['source' => 'miconvener', 'type' => 'llm_token_purchase', 'pack_key' => 'starter', 'tenant_id' => $tenant->id];
    fakeVerifiedPaystackCharge($metadata);

    $this->actingAs($user)->get("http://{$host}/billing/callback?reference=ref_same_payment", ['HTTP_HOST' => $host]);
    signedCharge($metadata);

    expect((int) $tenant->fresh()->llm_topup_balance)->toBe(500000)
        ->and(Transaction::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a subscription reported by both the browser and the webhook is recorded once', function (): void {
    [$tenant, $user, $host] = referenceTenant();
    $metadata = ['source' => 'miconvener', 'type' => 'plan_subscription', 'plan_slug' => 'growth', 'interval' => 'month', 'tenant_id' => $tenant->id];
    fakeVerifiedPaystackCharge($metadata);

    $this->actingAs($user)->get("http://{$host}/billing/callback?reference=ref_same_payment", ['HTTP_HOST' => $host]);
    signedCharge($metadata);

    expect(Transaction::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(Subscription::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('provisioning the same payment twice changes nothing the second time', function (): void {
    [$tenant] = referenceTenant();
    $growth = Package::query()->where('slug', 'growth')->firstOrFail();
    $dto = ['package_id' => $growth->id, 'provider' => 'paystack', 'provider_subscription_id' => 'ps_ref_x', 'provider_plan_id' => 'growth_month', 'status' => 'active', 'current_period_end' => now()->addMonth(), 'amount_paid' => 9900, 'currency' => 'ghs', 'transaction_id' => 'ref_x'];

    $service = app(SubscriptionProvisioningService::class);

    expect($service->provision($tenant, $dto))->toBeTrue()
        ->and($service->provision($tenant, $dto))->toBeFalse()
        ->and(Transaction::query()->where('provider_transaction_id', 'ref_x')->count())->toBe(1);
});
```

Before running, confirm the callback route path (`grep -n "billing/callback" routes/*.php`) and that `/billing/callback` resolves on the tenant subdomain; adjust the URL in both callback tests to match if not.

- [ ] **Step 2: Run to verify failure** — `"$PHP" artisan test --compact --filter=PaymentReferenceConsistencyTest`. Expected: the token test shows balance 1000000 or 2 transactions; the provisioning test fails on `toBeTrue()` (provision returns void).

- [ ] **Step 3: Return the reference and payment details from `verifyTransaction`** — in `PaystackGateway::verifyTransaction()`, extend the returned array:

```php
            return [
                'status' => $data['status'],
                'metadata' => $data['metadata'] ?? [],
                'transaction_id' => $data['id'],
                // The webhook keys every payment on this string; the browser
                // callback must use the same key or one payment is fulfilled twice.
                'reference' => (string) ($data['reference'] ?? $reference),
                'amount' => $data['amount'] ?? 0,
                'currency' => mb_strtolower($data['currency'] ?? 'ghs'),
                'channel' => $data['channel'] ?? null,
                'authorization' => (array) ($data['authorization'] ?? []),
                'paid_at' => $data['paid_at'] ?? null,
            ];
```

Keep any keys already present that this list does not show; read the method first.

- [ ] **Step 4: Use the reference in the callback** — in `CallbackController`, replace every use of `$result['transaction_id']` as a payment key with `$result['reference']`:
  - `handlePlanSubscriptionFulfillment`: `'provider_subscription_id' => 'ps_'.$result['reference']` and `'transaction_id' => $result['reference']` (this matches the webhook's `'ps_'.($data['reference'] ?? $data['id'])`).
  - `handleSubscriptionFulfillment`: `'provider_subscription_id' => $result['subscription_code'] ?? $result['reference']`, `'transaction_id' => $result['reference']`.
  - `handleLlmTokenFulfillment`: pass `(string) $result['reference']`.
  - `handleInvoiceFulfillment`: replace its hand-written update and `Transaction::create` with the shared, locked, idempotent path:

```php
        app(PaymentFulfillmentService::class)->fulfillInvoice(
            $tenant,
            (string) $invoice->id,
            (string) $result['reference'],
            (int) ($result['amount'] ?? 0),
            mb_strtoupper((string) ($result['currency'] ?? $invoice->currency)),
        );
```

- [ ] **Step 5: Make provisioning idempotent** — at the top of `SubscriptionProvisioningService::provision()`, change the return type to `bool` and add:

```php
        /*
         * The browser callback and the webhook both report a payment, in either
         * order and sometimes more than once. Everything below changes plans and
         * records money, so a payment already recorded must change nothing.
         */
        $reference = (string) ($dto['transaction_id'] ?? '');

        if ($reference !== '' && Transaction::query()->where('provider_transaction_id', $reference)->exists()) {
            return false;
        }
```

and `return true;` after the final `Transaction::create`. Callers that ignore the result need no change.

- [ ] **Step 6: Back it with a unique index** — `database/migrations/landlord/2026_09_15_090000_make_transaction_references_unique.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A provider reference names one payment. Two rows with the same reference are
 * a double-recorded payment, which the browser callback and the webhook could
 * each produce. The index turns a race between them into an error instead of a
 * duplicate. Production had no duplicate references when this was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::connection('landlord')->table('transactions')
            ->select('provider_transaction_id')
            ->groupBy('provider_transaction_id')
            ->havingRaw('count(*) > 1')
            ->pluck('provider_transaction_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate transaction references must be resolved first: '.$duplicates->implode(', '));
        }

        Schema::connection('landlord')->table('transactions', function (Blueprint $table): void {
            $table->unique('provider_transaction_id', 'transactions_provider_transaction_id_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('transactions', function (Blueprint $table): void {
            $table->dropUnique('transactions_provider_transaction_id_unique');
        });
    }
};
```

- [ ] **Step 7: Run** the new tests plus `PaymentFulfillmentTest`, `PlatformWebhookRoutingTest`, and any callback tests (`grep -rln "billing/callback\|CallbackController" tests`). Expected: PASS. Mutation-check: revert Step 4's token change and confirm the token test fails.

- [ ] **Step 8: Commit** — `fix: key browser and webhook fulfilment on the same Paystack reference`.

---

### Task 2: A durable once-only guard for billing emails

**Files:** create the `billing_emails` migration, `app/Models/BillingEmail.php`, `app/Services/Billing/BillingEmailSender.php`; test `tests/Feature/Billing/BillingEmailSenderTest.php`.

**Interfaces — Produces:** `BillingEmailSender::send(Tenant $tenant, string $type, string $dedupeKey, array $recipients, Mailable $mail): bool` — `true` when queued now, `false` when already sent or there are no recipients.

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\BillingEmail;
use App\Models\Tenant;
use App\Services\Billing\BillingEmailSender;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => refreshTenantDatabases());

function probeMailable(): Mailable
{
    return new class extends Mailable
    {
        public function build(): static
        {
            return $this->subject('Probe')->html('<p>probe</p>');
        }
    };
}

test('an email is queued once per type and key, however often it is requested', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $sender = app(BillingEmailSender::class);

    expect($sender->send($tenant, 'payment_receipt', 'receipt:ref_1', ['a@example.com'], probeMailable()))->toBeTrue()
        ->and($sender->send($tenant, 'payment_receipt', 'receipt:ref_1', ['a@example.com'], probeMailable()))->toBeFalse();

    Mail::assertQueuedCount(1);
    expect(BillingEmail::query()->count())->toBe(1);
});

test('recipients are lower-cased and de-duplicated, and none means nothing is sent', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $sender = app(BillingEmailSender::class);

    $sender->send($tenant, 'payment_receipt', 'receipt:ref_2', ['Owner@Example.com', 'owner@example.com', ''], probeMailable());
    expect(BillingEmail::query()->where('dedupe_key', 'receipt:ref_2')->value('recipients'))->toBe(['owner@example.com']);

    expect($sender->send($tenant, 'payment_receipt', 'receipt:ref_3', [null, ''], probeMailable()))->toBeFalse();
    expect(BillingEmail::query()->where('dedupe_key', 'receipt:ref_3')->exists())->toBeFalse();
});

test('if queueing fails the claim is released so the email can be retried', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue down'));
    $tenant = Tenant::factory()->create();

    expect(fn () => app(BillingEmailSender::class)->send($tenant, 'payment_receipt', 'receipt:ref_4', ['a@example.com'], probeMailable()))
        ->toThrow(RuntimeException::class);

    expect(BillingEmail::query()->where('dedupe_key', 'receipt:ref_4')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure** (class not found).

- [ ] **Step 3: Migration** `database/migrations/landlord/2026_09_15_090001_create_billing_emails_table.php`:

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
        Schema::connection('landlord')->create('billing_emails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('dedupe_key');
            $table->json('recipients');
            $table->timestamps();

            $table->unique(['type', 'dedupe_key']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('billing_emails');
    }
};
```

- [ ] **Step 4: Model** `app/Models/BillingEmail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per billing email ever queued. The unique (type, dedupe_key) index is
 * what guarantees a receipt or warning is sent once, however many times the
 * browser or Paystack reports the event behind it.
 */
final class BillingEmail extends Model
{
    use HasUuids;

    public const string TYPE_PAYMENT_RECEIPT = 'payment_receipt';

    public const string TYPE_PAYMENT_FAILED = 'payment_failed';

    public const string TYPE_SUBSCRIPTION_ENDING = 'subscription_ending';

    public const string TYPE_SUBSCRIPTION_ENDED = 'subscription_ended';

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'type', 'dedupe_key', 'recipients'];

    protected function casts(): array
    {
        return ['recipients' => 'array'];
    }
}
```

- [ ] **Step 5: Sender** `app/Services/Billing/BillingEmailSender.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BillingEmail;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class BillingEmailSender
{
    /**
     * Claim the once-only key, then queue the email to every recipient.
     *
     * @param  array<int, string|null>  $recipients
     */
    public function send(Tenant $tenant, string $type, string $dedupeKey, array $recipients, Mailable $mail): bool
    {
        $recipients = array_values(array_unique(array_filter(array_map(
            fn (?string $email): string => mb_strtolower(mb_trim((string) $email)),
            $recipients,
        ))));

        if ($recipients === []) {
            Log::warning("Billing email {$type} for tenant {$tenant->id} has no recipient", ['key' => $dedupeKey]);

            return false;
        }

        try {
            $claim = BillingEmail::query()->create([
                'tenant_id' => $tenant->id,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'recipients' => $recipients,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        try {
            Mail::to($recipients)->queue($mail);
        } catch (Throwable $e) {
            // Release the claim so a redelivered event can send it after all.
            $claim->delete();

            throw $e;
        }

        return true;
    }
}
```

- [ ] **Step 6: Run**, mutation-check by removing the unique index from the migration (the first test must fail), restore, **commit** `feat: a once-only guard for billing emails`.

---

### Task 3: Payment receipt

**Files:** create `PaymentReceiptMail`, its view, `BillingNotifier` (receipt part); modify `WebhookController::handlePaystackChargeSuccess` and the four `CallbackController` handlers; delete `SubscriptionConfirmedMail` and its view; test `tests/Feature/Billing/BillingEmailsTest.php`.

**Interfaces — Consumes:** Task 1 `verifyTransaction()['reference']`, Task 2 `BillingEmailSender::send`. **Produces:** `BillingNotifier::paymentReceived(Tenant $tenant, string $reference, ?string $payerEmail, array $provider = []): bool`.

- [ ] **Step 1: Failing tests** (create `tests/Feature/Billing/BillingEmailsTest.php`; it reuses the Task 1 helpers `referenceTenant()` / `signedCharge()`, so define local equivalents with different names if run in isolation — Pest shares global functions, so do not redeclare the same names):

```php
<?php

declare(strict_types=1);

use App\Mail\Billing\PaymentReceiptMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['services.paystack.secret_key' => 'sk_one_account', 'services.settlement.paystack.secret_key' => 'sk_one_account', 'services.paystack.metadata_source' => 'miconvener']);
    Mail::fake();
});

function billingEmailTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'email' => 'accounts@acme.test', 'llm_topup_balance' => 0]);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($owner->id);

    return $tenant;
}

function billingWebhook(string $event, array $data): void
{
    $data['metadata'] = ($data['metadata'] ?? []) + ['source' => 'miconvener'];
    $body = json_encode(['event' => $event, 'data' => $data]);
    test()->call('POST', '/webhooks/paystack', [], [], [], ['HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_one_account'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
}

test('a token pack payment sends one branded receipt to the payer and the tenant contact', function (): void {
    $tenant = billingEmailTenant();
    $charge = ['reference' => 'ref_receipt_1', 'amount' => 500, 'currency' => 'USD', 'channel' => 'card',
        'authorization' => ['last4' => '4081', 'brand' => 'visa'], 'customer' => ['email' => 'owner@example.com'],
        'metadata' => ['type' => 'llm_token_purchase', 'pack_key' => 'starter', 'tenant_id' => $tenant->id]];

    billingWebhook('charge.success', $charge);
    billingWebhook('charge.success', $charge); // Paystack redelivers

    Mail::assertQueued(PaymentReceiptMail::class, 1);
    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->hasTo('owner@example.com')
        && $mail->hasTo('accounts@acme.test')
        && $mail->amountDisplay === 'USD 5.00'
        && str_contains($mail->description, 'token')
        && $mail->paymentMethod === 'Visa card ending 4081');
});

test('a subscription payment receipt says the plan is active', function (): void {
    $tenant = billingEmailTenant();

    billingWebhook('charge.success', ['reference' => 'ref_receipt_2', 'amount' => 9900, 'currency' => 'GHS',
        'customer' => ['email' => 'owner@example.com'],
        'metadata' => ['type' => 'plan_subscription', 'plan_slug' => 'growth', 'interval' => 'month', 'tenant_id' => $tenant->id]]);

    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->amountDisplay === 'GHS 99.00'
        && str_contains($mail->description, 'Growth'));
});

test('the receipt renders with the MiConvener logo and the payment details', function (): void {
    $tenant = billingEmailTenant();
    $html = (new PaymentReceiptMail($tenant, 'Growth plan, monthly', 'GHS 99.00', 'ref_render', '15 September 2026', 'Mobile money', 'https://acme.example/billing'))->render();

    expect($html)->toContain('miconvener@2x.png')
        ->toContain('GHS 99.00')
        ->toContain('Growth plan, monthly')
        ->toContain('ref_render')
        ->toContain('Mobile money');
});
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Mailable** `app/Mail/Billing/PaymentReceiptMail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PaymentReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $description,
        public readonly string $amountDisplay,
        public readonly string $reference,
        public readonly string $paidOn,
        public readonly ?string $paymentMethod,
        public readonly string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment received: {$this->amountDisplay}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.payment-receipt');
    }
}
```

- [ ] **Step 4: View** `resources/views/emails/billing/payment-receipt.blade.php`:

```blade
<x-mail::message>
# Payment received

Thank you. We've received your payment for **{{ $tenant->name }}**.

<x-mail::panel>
**{{ $amountDisplay }}**<br>
{{ $description }}
</x-mail::panel>

- **Paid on:** {{ $paidOn }}
@if ($paymentMethod)
- **Payment method:** {{ $paymentMethod }}
@endif
- **Reference:** {{ $reference }}

<x-mail::button :url="$billingUrl">
View billing
</x-mail::button>

Keep this email as your receipt.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
```

- [ ] **Step 5: Notifier** `app/Services/Billing/BillingNotifier.php` (receipt part; Tasks 4–5 add the other methods):

```php
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Mail\Billing\PaymentReceiptMail;
use App\Models\BillingEmail;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

final class BillingNotifier
{
    public function __construct(private readonly BillingEmailSender $sender) {}

    /**
     * Send the receipt for a payment already recorded as a transaction. Safe
     * to call from every path that sees the payment: the once-only key is the
     * provider reference, and an unrecorded reference sends nothing.
     *
     * @param  array<string, mixed>  $provider  Paystack's transaction data, for the payment method.
     */
    public function paymentReceived(Tenant $tenant, string $reference, ?string $payerEmail, array $provider = []): bool
    {
        $transaction = Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_transaction_id', $reference)
            ->where('status', 'success')
            ->first();

        if (! $transaction) {
            return false;
        }

        $mail = new PaymentReceiptMail(
            tenant: $tenant,
            description: $this->describe($transaction),
            amountDisplay: self::money((int) $transaction->amount, (string) $transaction->currency),
            reference: $reference,
            paidOn: CarbonImmutable::parse($provider['paid_at'] ?? $transaction->created_at)->format('j F Y'),
            paymentMethod: self::paymentMethod($provider),
            billingUrl: $this->billingUrl($tenant),
        );

        return $this->sender->send($tenant, BillingEmail::TYPE_PAYMENT_RECEIPT, "receipt:{$reference}", [$payerEmail, $tenant->email], $mail);
    }

    public static function money(int $minor, string $currency): string
    {
        return mb_strtoupper($currency).' '.number_format($minor / 100, 2);
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    public static function paymentMethod(array $provider): ?string
    {
        $channel = $provider['channel'] ?? null;
        $authorization = (array) ($provider['authorization'] ?? []);

        return match ($channel) {
            'card' => isset($authorization['last4'])
                ? ucfirst((string) ($authorization['brand'] ?? 'Card')).' card ending '.$authorization['last4']
                : 'Card',
            'mobile_money' => 'Mobile money',
            'bank', 'bank_transfer' => 'Bank transfer',
            null => null,
            default => ucfirst(str_replace('_', ' ', (string) $channel)),
        };
    }

    private function describe(Transaction $transaction): string
    {
        $meta = (array) $transaction->meta;

        if (($meta['type'] ?? null) === 'llm_token_purchase') {
            $pack = Config::get('llm.token_packs.'.($meta['pack_key'] ?? ''), []);

            return ($pack['name'] ?? 'AI').' token pack ('.number_format((int) ($meta['tokens'] ?? 0)).' tokens)';
        }

        if (($meta['type'] ?? null) === 'invoice_payment') {
            $number = Invoice::query()->whereKey($meta['invoice_id'] ?? null)->value('number');

            return $number ? "Invoice {$number}" : 'Invoice payment';
        }

        if (isset($meta['package_id'])) {
            $name = Package::query()->whereKey($meta['package_id'])->value('name') ?? 'Subscription';
            $interval = str_ends_with((string) ($meta['provider_plan_id'] ?? ''), '_year') ? 'yearly' : 'monthly';

            return "{$name} plan, {$interval}. Your plan is active.";
        }

        return config('app.name').' payment';
    }

    private function billingUrl(Tenant $tenant): string
    {
        return route('billing.index', ['subdomain' => $tenant->slug]);
    }
}
```

Confirm `llm.token_packs.*` entries carry a `name` key (`sed -n '50,70p' config/llm.php`); if not, fall back as written.

- [ ] **Step 6: Wire the webhook** — in `WebhookController::handlePaystackChargeSuccess`, after each of these fulfils or attempts to fulfil, call `app(BillingNotifier::class)->paymentReceived($tenant, $reference, $customerEmail, $data);` with `$reference = (string) ($data['reference'] ?? $data['id'] ?? '')`:
  - the `llm_token_purchase` branch (after `fulfillLlmTokenPurchase`, before `return`)
  - the invoice branch (after `fulfillInvoice`, before `return`)
  - the `plan_subscription` branch (after `provision`)
  - the legacy plan-code branch (after `provision`)

  Call it whether fulfilment returned true or false: the once-only key makes a repeat harmless, and a receipt the first path failed to queue is then sent by the second.

- [ ] **Step 7: Wire the callback** — in each of the four `CallbackController` handlers, after fulfilment, call `app(BillingNotifier::class)->paymentReceived($tenant, (string) $result['reference'], auth()->user()?->email, $result);`. Delete both `Mail::to(auth()->user()->email)->queue(new SubscriptionConfirmedMail(...))` blocks and the `use App\Mail\SubscriptionConfirmedMail;` import, then `git rm app/Mail/SubscriptionConfirmedMail.php resources/views/emails/subscription-confirmed.blade.php` after confirming `grep -rn SubscriptionConfirmedMail app tests` finds nothing else.

- [ ] **Step 8: Run** `BillingEmailsTest`, `PaymentReferenceConsistencyTest`, `PaymentFulfillmentTest`, `PlatformWebhookRoutingTest`. Mutation-check: remove the notifier call from the token branch and confirm the first test fails. **Commit** `feat: send a branded receipt for every platform payment, once`.

---

### Task 4: Failed payment warning

**Files:** create `PaymentFailedMail` + `payment-failed.blade.php`; add `BillingNotifier::paymentFailed`; modify `WebhookController::handlePaystackInvoiceFailed`; extend `BillingEmailsTest`.

**Interfaces — Produces:** `BillingNotifier::paymentFailed(Tenant $tenant, array $data, ?string $payerEmail): bool`.

- [ ] **Step 1: Failing tests** (append):

```php
use App\Mail\Billing\PaymentFailedMail;

test('a failed subscription payment warns once and says what happens next', function (): void {
    $tenant = billingEmailTenant();
    $failed = ['invoice_code' => 'INV_fail_1', 'amount' => 9900, 'currency' => 'GHS',
        'customer' => ['email' => 'owner@example.com'],
        'subscription' => ['subscription_code' => 'SUB_1', 'next_payment_date' => '2026-09-18T00:00:00Z']];

    billingWebhook('invoice.payment_failed', $failed);
    billingWebhook('invoice.payment_failed', $failed);

    Mail::assertQueued(PaymentFailedMail::class, 1);
    Mail::assertQueued(PaymentFailedMail::class, fn (PaymentFailedMail $mail): bool => $mail->amountDisplay === 'GHS 99.00'
        && $mail->retryOn === '18 September 2026');
});

test('a failed payment for an unknown customer sends nothing', function (): void {
    billingEmailTenant();
    billingWebhook('invoice.payment_failed', ['invoice_code' => 'INV_x', 'amount' => 100, 'customer' => ['email' => 'stranger@example.com']]);

    Mail::assertNothingQueued();
});
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Mailable** `app/Mail/Billing/PaymentFailedMail.php` — same shape as `PaymentReceiptMail` with constructor `(Tenant $tenant, string $amountDisplay, ?string $retryOn, string $billingUrl)`, subject `Action needed: your {$this->tenant->name} payment didn't go through`, markdown `emails.billing.payment-failed`.

```php
<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PaymentFailedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $amountDisplay,
        public readonly ?string $retryOn,
        public readonly string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Action needed: your {$this->tenant->name} payment didn't go through");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.payment-failed');
    }
}
```

- [ ] **Step 4: View** `resources/views/emails/billing/payment-failed.blade.php`:

```blade
<x-mail::message>
# Your payment didn't go through

We couldn't take **{{ $amountDisplay }}** for your {{ config('app.name') }} subscription for **{{ $tenant->name }}**.

Your plan is still active for now. Nothing has been cancelled.

@if ($retryOn)
We'll try the payment again on **{{ $retryOn }}**. To avoid any interruption, check that your card or mobile money account can cover it before then.
@else
To avoid any interruption, check that your card or mobile money account can cover the payment, or update your payment method.
@endif

<x-mail::button :url="$billingUrl">
Review billing
</x-mail::button>

If you've already sorted this out, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
```

- [ ] **Step 5: Notifier method** (add to `BillingNotifier`, import the mailable):

```php
    /**
     * @param  array<string, mixed>  $data  Paystack invoice.payment_failed data.
     */
    public function paymentFailed(Tenant $tenant, array $data, ?string $payerEmail): bool
    {
        $amount = (int) ($data['amount'] ?? $data['subscription']['amount'] ?? 0);
        $retry = $data['subscription']['next_payment_date'] ?? null;
        $key = $data['invoice_code'] ?? (($data['subscription']['subscription_code'] ?? 'unknown').':'.$amount.':'.now()->toDateString());

        $mail = new PaymentFailedMail(
            tenant: $tenant,
            amountDisplay: self::money($amount, (string) ($data['currency'] ?? 'GHS')),
            retryOn: $retry ? CarbonImmutable::parse($retry)->format('j F Y') : null,
            billingUrl: $this->billingUrl($tenant),
        );

        return $this->sender->send($tenant, BillingEmail::TYPE_PAYMENT_FAILED, "payment_failed:{$key}", [$payerEmail, $tenant->email], $mail);
    }
```

- [ ] **Step 6: Wire** `handlePaystackInvoiceFailed` — resolve the tenant exactly as `handlePaystackSubscriptionDisable` does (by `customer.email` via `users`), return if none, keep the log line, then `app(BillingNotifier::class)->paymentFailed($tenant, $data, $customerEmail);`. Replace the "Could send a payment failure notification here" comment.

- [ ] **Step 7: Run, mutation-check, commit** `feat: warn tenants when a subscription payment fails`. Existing test "a failed subscription invoice payment does not cancel or downgrade the subscription" must still pass.

---

### Task 5: Plan ending and plan ended

**Files:** create `SubscriptionEndingMail` + `subscription-ending.blade.php`, `SubscriptionEndedMail` + `subscription-ended.blade.php`; add two notifier methods; modify the not_renew and disable handlers; extend `BillingEmailsTest`.

**Interfaces — Produces:** `BillingNotifier::subscriptionEnding(Tenant $tenant, array $data, ?string $payerEmail): bool`, `BillingNotifier::subscriptionEnded(Tenant $tenant, array $data, ?string $payerEmail, ?string $previousPlan): bool`.

- [ ] **Step 1: Failing tests** (append):

```php
use App\Mail\Billing\SubscriptionEndedMail;
use App\Mail\Billing\SubscriptionEndingMail;
use App\Models\Package;

test('a plan that will not renew sends one notice with the end date', function (): void {
    $tenant = billingEmailTenant();
    $tenant->update(['package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    $data = ['subscription_code' => 'SUB_end_1', 'next_payment_date' => '2026-10-15T00:00:00Z', 'customer' => ['email' => 'owner@example.com']];

    billingWebhook('subscription.not_renew', $data);
    billingWebhook('subscription.not_renew', $data);

    Mail::assertQueued(SubscriptionEndingMail::class, 1);
    Mail::assertQueued(SubscriptionEndingMail::class, fn (SubscriptionEndingMail $mail): bool => $mail->endsOn === '15 October 2026'
        && $mail->planName === 'Growth');
});

test('a plan that has ended sends one notice naming the plan they were on', function (): void {
    $tenant = billingEmailTenant();
    $tenant->update(['package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    $data = ['subscription_code' => 'SUB_end_2', 'customer' => ['email' => 'owner@example.com']];

    billingWebhook('subscription.disable', $data);
    billingWebhook('subscription.disable', $data);

    Mail::assertQueued(SubscriptionEndedMail::class, 1);
    Mail::assertQueued(SubscriptionEndedMail::class, fn (SubscriptionEndedMail $mail): bool => $mail->previousPlan === 'Growth');
});
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Mailables.** `SubscriptionEndingMail(Tenant $tenant, string $planName, ?string $endsOn, string $billingUrl)`, subject `Your {$planName} plan won't renew`, markdown `emails.billing.subscription-ending`. `SubscriptionEndedMail(Tenant $tenant, ?string $previousPlan, string $billingUrl)`, subject `Your {$this->tenant->name} plan has ended`, markdown `emails.billing.subscription-ended`. Same class shape as `PaymentFailedMail` (final, `ShouldQueue`, `Queueable`, `SerializesModels`, promoted `public readonly` properties, `envelope()`, `content()`).

- [ ] **Step 4: Views.**

`resources/views/emails/billing/subscription-ending.blade.php`:

```blade
<x-mail::message>
# Your {{ $planName }} plan won't renew

Your **{{ $planName }}** plan for **{{ $tenant->name }}** has been set not to renew.

@if ($endsOn)
Everything keeps working until **{{ $endsOn }}**. After that, your organisation moves to the Free plan.
@else
Everything keeps working until the end of your current billing period. After that, your organisation moves to the Free plan.
@endif

On the Free plan you can run one event at a time with up to 50 registrations, and paid tickets are switched off.

<x-mail::button :url="$billingUrl">
Keep my plan
</x-mail::button>

If you meant to cancel, there's nothing more to do.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
```

Before committing, confirm those Free-plan limits against `database/seeders/EventPackageSeeder.php` (`events_in_flight`, `event_registrations`, `paid_tickets` for `free`) and correct the sentence if they differ.

`resources/views/emails/billing/subscription-ended.blade.php`:

```blade
<x-mail::message>
# Your plan has ended

@if ($previousPlan)
Your **{{ $previousPlan }}** plan for **{{ $tenant->name }}** has ended, and your organisation is now on the Free plan.
@else
Your paid plan for **{{ $tenant->name }}** has ended, and your organisation is now on the Free plan.
@endif

Your events, registrations and records are still here. What changes is what you can do next: one event at a time, up to 50 registrations, and no paid tickets.

<x-mail::button :url="$billingUrl">
Choose a plan
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
```

- [ ] **Step 5: Notifier methods:**

```php
    /**
     * @param  array<string, mixed>  $data  Paystack subscription.not_renew data.
     */
    public function subscriptionEnding(Tenant $tenant, array $data, ?string $payerEmail): bool
    {
        $endsOn = $data['next_payment_date'] ?? null;

        $mail = new SubscriptionEndingMail(
            tenant: $tenant,
            planName: $tenant->package?->name ?? 'current',
            endsOn: $endsOn ? CarbonImmutable::parse($endsOn)->format('j F Y') : null,
            billingUrl: $this->billingUrl($tenant),
        );

        $key = 'subscription_ending:'.($data['subscription_code'] ?? $tenant->id.':'.now()->toDateString());

        return $this->sender->send($tenant, BillingEmail::TYPE_SUBSCRIPTION_ENDING, $key, [$payerEmail, $tenant->email], $mail);
    }

    /**
     * @param  array<string, mixed>  $data  Paystack subscription.disable data.
     */
    public function subscriptionEnded(Tenant $tenant, array $data, ?string $payerEmail, ?string $previousPlan): bool
    {
        $mail = new SubscriptionEndedMail(
            tenant: $tenant,
            previousPlan: $previousPlan,
            billingUrl: $this->billingUrl($tenant),
        );

        $key = 'subscription_ended:'.($data['subscription_code'] ?? $tenant->id.':'.now()->toDateString());

        return $this->sender->send($tenant, BillingEmail::TYPE_SUBSCRIPTION_ENDED, $key, [$payerEmail, $tenant->email], $mail);
    }
```

- [ ] **Step 6: Wire.** In `handlePaystackSubscriptionNotRenew`, after the `cancelAtPeriodEnd` block: `app(BillingNotifier::class)->subscriptionEnding($tenant, $data, $customerEmail);`. In `handlePaystackSubscriptionDisable`, capture the plan name **before** switching: `$previousPlan = $tenant->package?->name;`, then after `switchToFree`: `app(BillingNotifier::class)->subscriptionEnded($tenant, $data, $customerEmail, $previousPlan);`.

- [ ] **Step 7: Run, mutation-check, commit** `feat: tell tenants when their plan will end and when it has`.

---

### Task 6: Verify end to end

- [ ] Full suite on Postgres: `"$PHP" artisan test --compact` — all pass.
- [ ] `vendor/bin/pint --test`, PHPStan (`--memory-limit=1G`), `npm run lint`, `npm run build`.
- [ ] Render all four emails to HTML and open them in a real browser (Playwright): logo header present, copy reads correctly, no raw Blade, mobile width (≈400px) readable. Screenshot each.
- [ ] Deploy; confirm both migrations ran on production and `transactions` has the unique index.
- [ ] Production smoke: `BillingNotifier` resolves from the container; queue connection is `cloud`; no billing email has been sent to a real tenant by the deploy itself (`billing_emails` count is 0).

## Out of scope

- Retention-ladder emails (account locked, final warning) — the retention system is not built.
- Receipts for event ticket purchases — attendees already receive a confirmation; this plan covers payments tenants make to MiConvener.
- Downloadable PDF invoices attached to receipts.
