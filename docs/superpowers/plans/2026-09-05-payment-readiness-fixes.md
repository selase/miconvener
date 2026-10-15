# Payment Readiness Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the six remaining gaps identified in the pre-launch payment-readiness audit so the event-ticketing checkout/webhook/refund/cancellation path is safe to run against a real (sandboxed, then live) Paystack account.

**Architecture:** Six independent, single-concern fixes to existing controllers — no new subsystems, no schema changes. Each task touches one controller (plus its dedicated test file) and has no file overlap with any other task, so tasks may be implemented and reviewed in any order.

**Tech Stack:** Laravel 13 / PHP 8.5, Pest 5 for tests, Paystack REST API via `Illuminate\Support\Facades\Http`, existing `App\Services\Payment\PaystackGateway` (implements `App\Contracts\PaymentGateway`).

**Spec:** None. This is a bounded bug-fix punch list from a payment-readiness audit conducted directly in conversation with the user (no separate spec/design doc — the user approved automatic-refund-on-cancel as the one policy decision inside this scope; see Task 6). The Goal above is the authority this plan argues from.

## Global Constraints

- Do not touch the settlement-engine / platform-collects-then-pays-out system — that remains explicitly out of scope (spec'd only, 0% implemented) per the earlier audit conversation.
- Do not add Stripe checkout support to `EventCheckoutController` — it stays Paystack-only for event tickets; Task 2 only improves the *error message* shown when a tenant has configured a non-Paystack gateway.
- Refund-on-cancellation (Task 6) must call the tenant's own Paystack gateway using the same per-tenant-credentials pattern as `EventCheckoutController::checkout()` (`new PaystackGateway(['secret_key' => $gateway->api_key_encrypted])`) — **not** the `App\Contracts\PaymentGateway` container binding, which is shared with unrelated SaaS-subscription billing and resolves differently.
- Refund-on-cancellation must abort the cancellation (registration stays in its prior status, HTTP 422/502, no DB write) if the refund call fails — never silently cancel without a successful refund. This was an explicit user decision: "Automatic refund (recommended)."
- Webhook processing failures (Task 5) must return HTTP 500 so Paystack/Stripe retry delivery — do not swallow processing exceptions into a 200, since that would permanently drop a legitimate charge notification.
- Every task must run `vendor/bin/pint --dirty` before its commit and pass `php artisan test --compact --filter=<relevant test>` before moving to the next task, per project convention.
- Follow existing test conventions in `tests/Feature/Events/*` exactly: `refreshTenantDatabases()` + `RoleSeeder`/`PermissionsSeeder` in `beforeEach`, tenant/user built via a small local helper function (`eventHost()`/`approvalHost()` pattern), subdomain routing via `HTTP_HOST` header, `Http::fake()` for Paystack API calls — never hit the real Paystack API in tests.

---

### Task 1: Document Paystack/Stripe environment variables in `.env.example`

**Files:**
- Modify: `.env.example`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks — pure documentation.

`.env.example` currently has zero Paystack/Stripe entries even though `config/services.php:36-47` reads `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY`, `PAYSTACK_MERCHANT_EMAIL`, `PAYSTACK_CURRENCY`, `PAYMENT_DRIVER`, and `PAYMENT_DEV_BYPASS`. A new developer or deploy has no hint these exist. Note: these are the **platform-level** (SaaS billing) credentials — per-tenant event-ticket credentials are stored encrypted in the `tenant_payment_gateways` table via the tenant's own Settings > Payments screen, not via env vars. The comment below must make that distinction so nobody confuses the two.

- [ ] **Step 1: Add the env var block**

Open `.env.example` and insert this block immediately after the `SOFTWARE_PROVIDER_URL=https://wearepurpledot.com` / `SUPERADMIN_EMAIL=hiselase@gmail.com` lines (before the `THEME_PRIMARY_COLOR` block), matching the file's existing comment style:

```
# Payment Gateways — platform-level (SaaS subscription billing) credentials only.
# Per-tenant event-ticket payment credentials are configured per organizer via
# Settings > Payments and stored encrypted in the tenant_payment_gateways table —
# they are never read from environment variables.
PAYMENT_DRIVER=paystack
PAYMENT_DEV_BYPASS=false
PAYSTACK_SECRET_KEY=
PAYSTACK_PUBLIC_KEY=
PAYSTACK_MERCHANT_EMAIL=
PAYSTACK_CURRENCY=GHS
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

- [ ] **Step 2: Verify the keys match `config/services.php` exactly**

Run: `grep -n "env(" config/services.php | grep -iE "stripe|paystack|payment"`
Expected: every `env('...')` name in that output (`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY`, `PAYSTACK_MERCHANT_EMAIL`, `PAYSTACK_CURRENCY`, `PAYMENT_DRIVER`, `PAYMENT_DEV_BYPASS`) appears verbatim in the block you just added.

No automated test applies — this is a documentation-only change to a non-PHP file, so `vendor/bin/pint` and Pest do not touch it.

- [ ] **Step 3: Commit**

```bash
git add .env.example
git commit -m "docs: document Paystack/Stripe env vars in .env.example

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Improve the checkout error message for a non-Paystack gateway

**Files:**
- Modify: `app/Http/Controllers/Public/EventCheckoutController.php:35-42`
- Test: `tests/Feature/Events/EventCheckoutGatewayMessageTest.php`

**Interfaces:**
- Consumes: `App\Models\TenantPaymentGateway` (existing model, `provider`/`is_active` columns).
- Produces: nothing consumed by later tasks.

Today, whether a tenant has zero payment gateways configured or has only a Stripe gateway configured, `EventCheckoutController::checkout()` shows the same generic message: "This event is not currently accepting payments." Since event-ticket checkout is Paystack-only (by design — see Global Constraints), a tenant who configured Stripe thinking it would work needs a message that tells them what's actually wrong.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Events/EventCheckoutGatewayMessageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function gatewayMessageHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function gatewayMessageSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('checkout shows a generic message when no payment gateway is configured at all', function () {
    [$tenant] = gatewayMessageHost('acme');
    $host = gatewayMessageSubdomain('acme');

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

test('checkout shows a Paystack-specific message when only a non-Paystack gateway is configured', function () {
    [$tenant] = gatewayMessageHost('acme');
    $host = gatewayMessageSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'stripe',
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 5000,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/checkout/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas(
        'error',
        'This event currently only accepts payments via Paystack, which is not configured for this organizer.'
    );
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=EventCheckoutGatewayMessageTest`
Expected: the second test FAILs — both assertions currently see the generic message, since the controller does not yet distinguish "no gateway" from "wrong gateway".

- [ ] **Step 3: Implement the message split**

In `app/Http/Controllers/Public/EventCheckoutController.php`, replace lines 35-42:

```php
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return redirect()->back()->with('error', 'This event is not currently accepting payments.');
        }
```

with:

```php
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            $hasOtherActiveGateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->exists();

            $message = $hasOtherActiveGateway
                ? 'This event currently only accepts payments via Paystack, which is not configured for this organizer.'
                : 'This event is not currently accepting payments.';

            return redirect()->back()->with('error', $message);
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=EventCheckoutGatewayMessageTest`
Expected: PASS (2/2).

- [ ] **Step 5: Format and run the full Events test group as a regression check**

Run: `vendor/bin/pint --dirty`
Run: `php artisan test --compact --filter=Events`
Expected: PASS, no regressions in `EventRegistrationTest`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Public/EventCheckoutController.php tests/Feature/Events/EventCheckoutGatewayMessageTest.php
git commit -m "fix: clarify checkout error when only a non-Paystack gateway is configured

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: Fix `FinanceController::refund()` string-vs-array bug

**Files:**
- Modify: `app/Http/Controllers/Tenant/FinanceController.php:83-97`
- Test: `tests/Feature/Billing/FinanceRefundTest.php`

**Interfaces:**
- Consumes: `App\Contracts\PaymentGateway::refund(string $transactionId, ?int $amount = null): string` (existing contract method — `App\Services\Payment\PaystackGateway::refund()` at `app/Services/Payment/PaystackGateway.php:114-128` already returns `string`, never an array).
- Produces: nothing consumed by later tasks.

`FinanceController::refund()` calls `$merchantGateway->refund(...)`, which returns a `string` (Paystack refund id, or the literal `'pending'`). The current code treats that string as an array (`$result['id'] ?? ...`, then stores the raw string into `meta` — an array-cast column). Accessing `['id']` on a string silently emits a PHP warning and evaluates to `null` (not a fatal error), so `provider_transaction_id` always falls back to `'REF_'.$transaction->provider_transaction_id`, and the array cast on `meta` throws when it receives a scalar instead of an array — this path is broken today.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/FinanceRefundTest.php`. First check the existing role/permission fixture pattern this project uses for tenant-console tests (`RoleSeeder`, `Org Superadmin`) — reuse it exactly as in `tests/Feature/Events/EventRegistrationApprovalTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function financeRefundHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $tenant->update(['features' => array_merge($tenant->features ?? [], [])]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function financeRefundSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('refunding a transaction stores the real Paystack refund id, not a mangled string', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'ref_test_456'],
        ]),
    ]);

    [$tenant, $user] = financeRefundHost('acme');
    $host = financeRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $transaction = MerchantTransaction::create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'orig_ref_123',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => 'succeeded',
        'type' => 'payment',
        'customer_email' => 'guest@example.com',
    ]);

    $response = $this->actingAs($user)->post("http://{$host}/finance/refund/{$transaction->id}", [], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Refund processed successfully.');

    $transaction->refresh();
    expect($transaction->status)->toBe('refunded');

    $refundRecord = MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'refund')->firstOrFail();
    expect($refundRecord->provider_transaction_id)->toBe('ref_test_456');
    expect($refundRecord->meta)->toBe(['refund_id' => 'ref_test_456']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FinanceRefundTest`
Expected: FAIL — `$refundRecord` is either not found under `provider_transaction_id = 'ref_test_456'` (it was created as `'REF_orig_ref_123'` instead), or the `meta` assertion fails, or the request errors while casting a non-array to the `meta` array-cast column.

- [ ] **Step 3: Fix the implementation**

In `app/Http/Controllers/Tenant/FinanceController.php`, replace lines 83-97:

```php
        try {
            $result = $merchantGateway->refund($transaction->provider_transaction_id);

            MerchantTransaction::create([
                'tenant_id' => $tenant->id,
                'provider' => $transaction->provider,
                'provider_transaction_id' => $result['id'] ?? 'REF_'.$transaction->provider_transaction_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => 'succeeded',
                'type' => 'refund',
                'description' => 'Refund for '.$transaction->provider_transaction_id,
                'customer_email' => $transaction->customer_email,
                'meta' => $result,
            ]);
```

with:

```php
        try {
            $refundId = $merchantGateway->refund($transaction->provider_transaction_id);

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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=FinanceRefundTest`
Expected: PASS.

- [ ] **Step 5: Format**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Tenant/FinanceController.php tests/Feature/Billing/FinanceRefundTest.php
git commit -m "fix: store the actual Paystack refund id instead of treating the string return as an array

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: Prevent duplicate registrations from a double form submission

**Files:**
- Modify: `app/Http/Controllers/Public/PublicEventController.php:42-100`
- Test: `tests/Feature/Events/EventRegistrationIdempotencyTest.php`

**Interfaces:**
- Consumes: `EventRegistration::STATUS_CANCELLED`, `STATUS_REJECTED`, `STATUS_PENDING_PAYMENT` constants (existing, `app/Models/EventRegistration.php:27-39`).
- Produces: nothing consumed by later tasks.

A double form submission (double-click, browser back-and-resubmit, flaky network causing a client retry) currently creates two separate `EventRegistration` rows for the same event+email — two tickets, two confirmation emails, and (for a paid event) two independent Paystack checkout sessions. Fix: before creating a new registration, check for an existing non-cancelled/non-rejected registration for the same event+email and redirect to it instead of creating a duplicate. No arbitrary time window — a cancelled or rejected registration must still allow a fresh registration.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Events/EventRegistrationIdempotencyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function idempotencyHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function idempotencySubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('submitting the public registration form twice for the same email does not create a duplicate', function () {
    Mail::fake();
    [$tenant] = idempotencyHost('acme');
    $host = idempotencySubdomain('acme');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $payload = ['full_name' => 'Dup Guest', 'email' => 'dup@example.com'];

    $this->post("http://{$host}/e/{$event->slug}/register", $payload, ['HTTP_HOST' => $host]);
    $second = $this->post("http://{$host}/e/{$event->slug}/register", $payload, ['HTTP_HOST' => $host]);

    expect(EventRegistration::where('email', 'dup@example.com')->count())->toBe(1);

    $registration = EventRegistration::where('email', 'dup@example.com')->firstOrFail();
    $second->assertRedirect(route('public.events.confirmation', [
        'subdomain' => $tenant->slug,
        'event' => $event->slug,
        'registration' => $registration->id,
    ]));
});

test('resubmitting for a pending-payment registration redirects back to checkout instead of duplicating', function () {
    [$tenant] = idempotencyHost('acme');
    $host = idempotencySubdomain('acme');
    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $existing = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $response = $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Paying Guest',
        'email' => 'guest@example.com',
    ], ['HTTP_HOST' => $host]);

    expect(EventRegistration::where('email', 'guest@example.com')->count())->toBe(1);
    $response->assertRedirect(route('public.events.checkout', [
        'subdomain' => $tenant->slug,
        'event' => $event->slug,
        'registration' => $existing->id,
    ]));
});

test('a cancelled registration does not block re-registering with the same email', function () {
    Mail::fake();
    [$tenant] = idempotencyHost('acme');
    $host = idempotencySubdomain('acme');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'again@example.com',
        'status' => EventRegistration::STATUS_CANCELLED,
    ]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Again Guest',
        'email' => 'again@example.com',
    ], ['HTTP_HOST' => $host]);

    expect(EventRegistration::where('email', 'again@example.com')->count())->toBe(2);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=EventRegistrationIdempotencyTest`
Expected: the first two tests FAIL (duplicate rows are created today); the third PASSes already (no guard exists yet, so it trivially isn't blocked).

- [ ] **Step 3: Implement the idempotency guard**

In `app/Http/Controllers/Public/PublicEventController.php`, inside `register()`, immediately after the `$validated = $request->validate([...]);` block (after line 65) and before the `$ticketType = ...` line (line 67), insert:

```php
        $existingRegistration = EventRegistration::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('email', $validated['email'])
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
            ->first();

        if ($existingRegistration) {
            return $existingRegistration->status === EventRegistration::STATUS_PENDING_PAYMENT
                ? redirect()->route('public.events.checkout', [
                    'subdomain' => $tenant->slug,
                    'event' => $eventModel->slug,
                    'registration' => $existingRegistration->id,
                ])
                : redirect()->route('public.events.confirmation', [
                    'subdomain' => $tenant->slug,
                    'event' => $eventModel->slug,
                    'registration' => $existingRegistration->id,
                ]);
        }

```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=EventRegistrationIdempotencyTest`
Expected: PASS (3/3).

- [ ] **Step 5: Format and run the full Events test group as a regression check**

Run: `vendor/bin/pint --dirty`
Run: `php artisan test --compact --filter=Events`
Expected: PASS, no regressions (in particular the existing waitlist-position and approval tests in `EventRegistrationTest.php`/`EventRegistrationApprovalTest.php` must still pass — they register with distinct emails, so the new guard should not affect them).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Public/PublicEventController.php tests/Feature/Events/EventRegistrationIdempotencyTest.php
git commit -m "fix: prevent duplicate event registrations from a double form submission

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: Harden the merchant webhook handler (failed charges, signature logging, exception safety)

**Files:**
- Modify: `app/Http/Controllers/Tenant/Commerce/WebhookController.php`
- Test: `tests/Feature/Events/PaystackWebhookHardeningTest.php`

**Interfaces:**
- Consumes: `App\Models\MerchantTransaction` (existing, `$guarded = []`, `status` is a free-text string column with no enum constraint — `app/Models/MerchantTransaction.php:20-25`).
- Produces: `WebhookController::recordTransaction()` gains a fourth parameter `string $status = 'succeeded'` — no other task calls this private method, so this is a non-breaking signature change local to this file.

Three gaps today in `app/Http/Controllers/Tenant/Commerce/WebhookController.php`:
1. Only `charge.success` is handled in `handlePaystack()` (line 87). A `charge.failed` event is silently ignored — the registration stays stuck in `pending_payment` forever with no record anywhere that a payment attempt failed, so the host has no visibility into it on the Finance dashboard.
2. An invalid Paystack signature (line 80-82) returns 400 but never logs — `handleStripe()`'s equivalent branch (line 42-46) does log. A stream of forged/misrouted webhook attempts is invisible.
3. Neither `handlePaystack()` nor `handleStripe()` wraps its event-processing logic in a try/catch. An unexpected exception (a DB constraint violation, a mail-sending failure, a malformed payload) currently produces Laravel's generic unhandled-exception response with no webhook-specific context in the log, and — depending on the exception — may or may not signal the provider to retry.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Events/PaystackWebhookHardeningTest.php`. This reuses the exact Paystack-webhook-signing pattern from the existing `'paystack webhook confirms the matching registration exactly once'` test in `tests/Feature/Events/EventRegistrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function webhookHardeningHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function signedPaystackWebhookCall(\Tests\TestCase $test, string $tenantId, string $secret, array $payload)
{
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $secret);

    return $test->call(
        'POST',
        "/webhooks/merchant/paystack/{$tenantId}",
        [], [], [],
        ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body
    );
}

test('a failed paystack charge is recorded as a failed merchant transaction, and the registration stays pending', function () {
    Mail::fake();
    [$tenant] = webhookHardeningHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $payload = [
        'event' => 'charge.failed',
        'data' => [
            'reference' => 'ref_failed_123',
            'amount' => 5000,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];

    $response = signedPaystackWebhookCall($this, $tenant->id, $secret, $payload);
    $response->assertOk();

    $transaction = MerchantTransaction::where('tenant_id', $tenant->id)
        ->where('provider_transaction_id', 'ref_failed_123')
        ->firstOrFail();
    expect($transaction->status)->toBe('failed');

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
    Mail::assertNothingSent();
});

test('an invalid paystack signature is logged', function () {
    Log::spy();
    [$tenant] = webhookHardeningHost('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_webhook_secret',
        'is_active' => true,
    ]);

    $response = $this->call(
        'POST',
        "/webhooks/merchant/paystack/{$tenant->id}",
        [], [], [],
        ['HTTP_x-paystack-signature' => 'not-a-real-signature', 'CONTENT_TYPE' => 'application/json'],
        json_encode(['event' => 'charge.success', 'data' => []])
    );

    $response->assertStatus(400);
    Log::shouldHaveReceived('warning')->once();
});

test('a paystack webhook processing exception is caught, logged, and returns 500 so the provider retries', function () {
    Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

    [$tenant] = webhookHardeningHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_boom_123',
            'amount' => 5000,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];

    $response = signedPaystackWebhookCall($this, $tenant->id, $secret, $payload);

    $response->assertStatus(500);
    Log::shouldHaveReceived('error')->once();
});

test('charge.success still confirms the registration exactly once (regression)', function () {
    Mail::fake();
    [$tenant] = webhookHardeningHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_ok_123',
            'amount' => 5000,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];

    signedPaystackWebhookCall($this, $tenant->id, $secret, $payload)->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=PaystackWebhookHardeningTest`
Expected: the first three tests FAIL (`charge.failed` is currently ignored entirely — no `MerchantTransaction` row is created; the invalid-signature branch never calls `Log::warning`; an exception during processing is not caught, so the response is not a clean 500 and no `Log::error` call happens). The fourth (regression) test currently PASSes.

- [ ] **Step 3: Implement the hardening**

Replace the full contents of `app/Http/Controllers/Tenant/Commerce/WebhookController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Commerce;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class WebhookController extends Controller
{
    /**
     * Handle Stripe Webhooks for Merchant accounts.
     */
    public function handleStripe(Request $request, Tenant $tenant)
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'stripe')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return response()->json(['error' => 'Gateway not configured'], 404);
        }

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = $gateway->webhook_secret_encrypted;

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sigHeader, $webhookSecret
            );
        } catch (Exception $e) {
            Log::error('Merchant Stripe webhook verification failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                    $session = $event->data->object;
                    $this->recordTransaction($tenant, 'stripe', $session);
                    break;

                case 'payment_intent.succeeded':
                    // Avoid double counting if using both
                    break;
            }
        } catch (Throwable $e) {
            Log::error('Merchant Stripe webhook processing failed', [
                'tenant' => $tenant->id,
                'event' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle Paystack Webhooks for Merchant accounts.
     */
    public function handlePaystack(Request $request, Tenant $tenant)
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return response()->json(['error' => 'Gateway not configured'], 404);
        }

        $signature = $request->header('x-paystack-signature');
        $secret = $gateway->api_key_encrypted; // Paystack uses secret key for hashing

        if (! $signature || $signature !== hash_hmac('sha512', $request->getContent(), (string) $secret)) {
            Log::warning('Merchant Paystack webhook signature verification failed', ['tenant' => $tenant->id]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        try {
            if ($event === 'charge.success') {
                $this->recordTransaction($tenant, 'paystack', (object) $data);
                $this->confirmEventRegistrationIfApplicable($tenant, (array) ($data['metadata'] ?? []), $data['reference'] ?? null, (int) ($data['amount'] ?? 0), mb_strtoupper((string) ($data['currency'] ?? 'GHS')));
            } elseif ($event === 'charge.failed') {
                $this->recordTransaction($tenant, 'paystack', (object) $data, 'failed');
            }
        } catch (Throwable $e) {
            Log::error('Merchant Paystack webhook processing failed', [
                'tenant' => $tenant->id,
                'event' => $event,
                'reference' => $data['reference'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * If this charge was for an event ticket (carried in the checkout
     * metadata), confirm the matching registration. Idempotent: a
     * registration already confirmed is left untouched, so Paystack's
     * at-least-once webhook delivery can't double-process it.
     */
    private function confirmEventRegistrationIfApplicable(Tenant $tenant, array $metadata, ?string $reference, int $amount, string $currency): void
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

        $registration->issueTicket();
        $registration->fill([
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]);
        $registration->save();

        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

        Mail::to($registration->email)->send(new EventRegistrationConfirmed($registration));
    }

    private function recordTransaction(Tenant $tenant, string $provider, $data, string $status = 'succeeded'): void
    {
        MerchantTransaction::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'provider' => $provider,
                'provider_transaction_id' => $provider === 'stripe' ? ($data->payment_intent ?? $data->id) : $data->reference,
            ],
            [
                'amount' => $provider === 'stripe' ? $data->amount_total : ($data->amount),
                'currency' => mb_strtoupper((string) $provider === 'stripe' ? $data->currency : ($data->currency ?? 'NGN')),
                'status' => $status,
                'type' => 'payment',
                'customer_email' => $provider === 'stripe' ? ($data->customer_details->email ?? null) : ($data->customer['email'] ?? null),
                'customer_name' => $provider === 'stripe' ? ($data->customer_details->name ?? null) : mb_trim(($data->customer['first_name'] ?? '').' '.($data->customer['last_name'] ?? '')),
                'description' => $provider === 'stripe' ? 'Stripe Checkout' : 'Paystack Charge',
                'meta' => (array) $data,
            ]
        );
    }
}
```

The only functional deltas from the current file: `handlePaystack()` now branches on `charge.failed` too and logs on invalid signature; both `handlePaystack()` and `handleStripe()` wrap their event-processing `switch`/`if` in try/catch and return 500 with a logged error on failure; `recordTransaction()` gained a `string $status = 'succeeded'` fourth parameter used by the new `charge.failed` branch. The happy-path `charge.success` and Stripe `checkout.session.completed` behavior is byte-for-byte unchanged.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PaystackWebhookHardeningTest`
Expected: PASS (4/4).

- [ ] **Step 5: Format and run the full webhook-adjacent regression suite**

Run: `vendor/bin/pint --dirty`
Run: `php artisan test --compact --filter=EventRegistrationTest`
Expected: PASS — in particular the existing `'paystack webhook confirms the matching registration exactly once'` test, which exercises the unchanged `charge.success` happy path and idempotency.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Tenant/Commerce/WebhookController.php tests/Feature/Events/PaystackWebhookHardeningTest.php
git commit -m "feat: record failed Paystack charges, log invalid signatures, and catch webhook processing exceptions

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: Automatic refund on cancellation of a paid registration

**Files:**
- Modify: `app/Http/Controllers/Tenant/EventRegistrationController.php`
- Test: `tests/Feature/Events/EventRegistrationCancelRefundTest.php`

**Interfaces:**
- Consumes: `App\Services\Payment\PaystackGateway::refund(string $transactionId, ?int $amount = null): string` (existing, throws `App\Exceptions\PaymentFailedException` on failure — `app/Services/Payment/PaystackGateway.php:114-128`), `App\Models\TenantPaymentGateway` (existing).
- Produces: nothing consumed by later tasks.

Today, `EventRegistrationController::cancel()` (lines 61-83) is a pure status flip — it never refunds a paid registration. Per the user's explicit decision ("Automatic refund (recommended)"), cancelling a paid registration (status `confirmed` or `checked_in`, `amount > 0`, and a `payment_reference` recorded — i.e. it was actually charged) must automatically call Paystack's refund API using the tenant's own gateway credentials, using the same per-tenant-credentials pattern as `EventCheckoutController::checkout()` — **not** the `App\Contracts\PaymentGateway` container binding used by `FinanceController`, which resolves against unrelated SaaS-billing context. If the refund call fails, the cancellation must abort: the registration stays in its prior status, and the response is a clear error — never a silent "cancelled" with no refund issued.

Double-refund protection comes for free from the existing status guard: only registrations whose status is in `[CONFIRMED, CHECKED_IN, WAITLISTED]` are cancellable at all (line 68-71); once cancelled, a second cancel attempt is rejected with 422 before reaching any refund logic.

A `WAITLISTED` registration is cancellable but was never charged (no `payment_reference` — Paystack only calls back once payment succeeds), so it must never trigger a refund attempt even if its `amount` is nonzero.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Events/EventRegistrationCancelRefundTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function cancelRefundHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function cancelRefundSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('cancelling a confirmed paid registration automatically refunds via the tenant Paystack gateway', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'ref_cancel_1'],
        ]),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_123',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/refund'
        && $request['transaction'] === 'paid_ref_123');
});

test('cancellation is aborted and the registration stays confirmed when the refund call fails', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Transaction not found'], 400),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_456',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(502);
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('cancellation is aborted with 422 when a paid registration has no active payment gateway configured', function () {
    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_789',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('cancelling a free confirmed registration does not attempt a refund', function () {
    Http::preventStrayRequests();

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 0,
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
});

test('cancelling a waitlisted registration never attempts a refund, even with a nonzero amount', function () {
    Http::preventStrayRequests();

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_WAITLISTED,
        'waitlist_position' => 1,
        'amount' => 5000,
        'payment_reference' => null,
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=EventRegistrationCancelRefundTest`
Expected: the first three tests FAIL — `cancel()` currently flips status unconditionally with no refund call, no gateway check, and no way to return 502/422. The last two currently PASS already (nothing today attempts a refund, so "does not attempt a refund" is vacuously true) — they exist to lock in that behavior once the refund path is added, guarding against a future regression that refunds free/waitlisted registrations.

- [ ] **Step 3: Implement automatic refund-on-cancel**

In `app/Http/Controllers/Tenant/EventRegistrationController.php`, update the imports (lines 7-16) to:

```php
use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationPaymentInvite;
use App\Mail\Events\EventRegistrationRejected;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
```

Replace `cancel()` (lines 61-83) with:

```php
    public function cancel(string $subdomain, string $event, string $registration): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $registrationModel = $eventModel->registrations()->where('id', $registration)->firstOrFail();

        $cancellable = [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN, EventRegistration::STATUS_WAITLISTED];
        if (! in_array($registrationModel->status, $cancellable, true)) {
            return response()->json(['message' => 'This registration cannot be cancelled.'], 422);
        }

        $wasPaid = $registrationModel->status !== EventRegistration::STATUS_WAITLISTED
            && $registrationModel->amount > 0
            && $registrationModel->payment_reference !== null;

        if ($wasPaid) {
            $refundFailure = $this->refundPaidRegistration($tenant, $registrationModel);
            if ($refundFailure !== null) {
                return $refundFailure;
            }
        }

        $freesUpASpot = $registrationModel->status !== EventRegistration::STATUS_WAITLISTED;
        $ticketTypeId = $registrationModel->ticket_type_id;

        $registrationModel->update(['status' => EventRegistration::STATUS_CANCELLED]);

        if ($freesUpASpot) {
            $this->promoteFromWaitlist($eventModel, $ticketTypeId);
        }

        return response()->json(['message' => 'Registration cancelled.']);
    }

    /**
     * Attempts the automatic Paystack refund for a paid registration being
     * cancelled. Returns null on success (caller proceeds to cancel); returns
     * a JsonResponse to short-circuit cancel() when the refund cannot be
     * issued, so a cancellation is never silently completed without a refund.
     */
    private function refundPaidRegistration(Tenant $tenant, EventRegistration $registrationModel): ?JsonResponse
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return response()->json(['message' => 'Cannot cancel: no active payment gateway is configured to process the refund.'], 422);
        }

        try {
            (new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]))->refund($registrationModel->payment_reference);
        } catch (PaymentFailedException $e) {
            Log::error('Automatic refund on registration cancellation failed', [
                'tenant' => $tenant->id,
                'registration' => $registrationModel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Cancellation aborted: the refund could not be processed. '.$e->getMessage()], 502);
        }

        return null;
    }
```

Leave `approve()`, `reject()`, `confirmOrInviteToPay()`, `promoteFromWaitlist()`, `findEvent()`, and `getTenant()` unchanged.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=EventRegistrationCancelRefundTest`
Expected: PASS (5/5).

- [ ] **Step 5: Format and run the full Events regression suite**

Run: `vendor/bin/pint --dirty`
Run: `php artisan test --compact --filter=EventRegistrationApprovalTest`
Expected: PASS — in particular `'cancelling a confirmed registration promotes the next waitlisted person and closes the gap'` and `'cancelling a waitlisted registration does not promote anyone'`, both of which use `amount => 0` registrations and so must remain refund-free and behave exactly as before.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Tenant/EventRegistrationController.php tests/Feature/Events/EventRegistrationCancelRefundTest.php
git commit -m "feat: automatically refund via Paystack when a paid registration is cancelled

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Self-Review Notes

**Coverage check against the 8 audit items:**
1. `charge.failed` webhook handling → Task 5.
2. Automatic refund-on-cancellation → Task 6.
3. `FinanceController::refund()` string-vs-array bug → Task 3.
4. Duplicate-registration idempotency → Task 4.
5. Non-Paystack checkout error message → Task 2.
6. Paystack/Stripe `.env.example` entries → Task 1.
7. Invalid Paystack webhook signature logging → Task 5 (bundled with #1 and #8 since all three are narrow edits to the same ~30-line `handlePaystack()`/`handleStripe()` pair — splitting them into three separate tasks would have meant three near-identical diff locations reviewed three times).
8. Try/catch wrapping of webhook processing in both `handlePaystack` and `handleStripe` → Task 5.

**Placeholder scan:** none found — every step has concrete code, exact file paths/line numbers, and runnable test commands.

**Type consistency:** `refundPaidRegistration(Tenant $tenant, EventRegistration $registrationModel): ?JsonResponse` in Task 6 matches the `Tenant` returned by `getTenant()` and the `JsonResponse` return type already used by `cancel()`. `recordTransaction()`'s new `string $status = 'succeeded'` parameter (Task 5) is called with a matching string literal (`'failed'`) at its one new call site.
