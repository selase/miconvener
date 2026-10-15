# Post-Feature Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close six defects found by self-review of commits `b3949af..23a38f0` — one confirmed access-control bypass, three billing/data-integrity faults in the notification gateway, one financial-reconciliation gap between two coexisting ledgers, and one uniqueness check that disagrees with its database constraint.

**Architecture:** Each defect is repaired where it originates rather than compensated for downstream. The media route stops serving files that another controller exists to gate; the notification gateway stops charging for work it has not done; the three money paths that bypass the new double-entry ledger are brought to parity with the one path that already uses it; and a uniqueness probe is widened to match the index it is protecting.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 5, Postgres (`landlord` connection), Laravel Cloud object storage (S3-compatible), Spatie Permission.

**Spec:** This plan is its own spec. The **Findings** section below states problem, root cause and consequence for each defect; every task implements exactly one finding.

## Global Constraints

- Run `vendor/bin/pint --dirty` before every commit. Never run `pint --test`.
- PHP binary is Herd's: `PHP="$HOME/Library/Application Support/Herd/bin/php"`. A bare `php` resolves to a broken Homebrew build.
- Run tests with `"$PHP" artisan test --compact` plus a path or `--filter`. Run the minimum needed.
- `Model::shouldBeStrict()` is on: mass-assignment and missing-attribute access throw.
- All landlord models use the `BelongsToTenant` trait, which applies a global `TenantScope`. That scope is a no-op when `TenantContext::activeTenantId()` is null — never rely on it in console commands, webhooks, or queued jobs.
- Do NOT delete existing tests. If one asserts behaviour this plan changes, update it and explain why in the commit message.
- Existing money amounts are integers in minor units (pesewas). Never introduce floats.
- Every task ends with a commit whose message states what was wrong, not just what changed.

---

## Findings

The six defects, each with the root cause the original implementation missed.

### F1 — `/media/{path}` bypasses every access control on event materials (CRITICAL)

**Problem.** `GET /media/{path}` and `GET /storage/{path}` are registered on both the central router (`routes/web.php:219-220`) and the subdomain router (`routes/subdomain.php:349-350`) with no middleware. `MediaController::show()` streams any file whose path starts with one of eight allowed prefixes. `event-materials/` is one of them.

`MaterialDownloadController` exists precisely to gate those files and enforces three rules — the registration must be confirmed, the material must be past its `release_at`, and the registration must have download attempts remaining. The media route enforces none of them, and applies no tenant scoping.

**Root cause.** The allowlist was written to answer *"is this path shaped like a media file?"* when the question it needed to answer was *"is this requester entitled to this file?"* Prefix matching is a traversal defence, not an authorisation one. `event-materials/` was added alongside genuinely public assets (hero images, speaker photos, sponsor logos) without noticing that materials are the one prefix with an access-control story attached.

**Consequence.** Proven with two failing tests in `tests/Feature/Security/MediaRouteBypassTest.php`, both returning HTTP 200:
- An **embargoed** material (`release_at` 30 days out) that `MaterialDownloadController` correctly refuses with 403 is served in full by `/media/event-materials/...`.
- One tenant's material is streamed from **another tenant's subdomain**.

Three factors make this worse than a theoretical hole. Filenames come from `Helper::processUploadedFile()`, which builds them as `{prefix}_{uniqid()}.{ext}` — `uniqid()` is a microsecond timestamp in hex, not random, so paths are enumerable given an approximate upload time and a fixed prefix (`material_`, `speaker_slides_`). The response sets `Cache-Control: public, max-age=31536000, immutable`, so any CDN caches the bytes publicly for a year. And this silently undoes two deliberate product decisions: materials withheld from unconfirmed registrations on private events, and materials being a paid-tier feature.

**Solution.** Remove `event-materials/` from the allowlist so those files are reachable only through `MaterialDownloadController`. Nothing builds a public URL for them today — verified: `EventMaterialController` and `SpeakerPortalController` write into the prefix, and only `MaterialDownloadController` reads it. Also remove the `users/` prefix: nothing writes to it, so it is an open door onto a directory with no legitimate traffic. `event-forum/` stays — `EventForumThread::attachmentUrl()` and `EventForumReply::attachmentUrl()` depend on it, and the public forum is already closed for private events.

### F2 — Tenants are charged for SMS and WhatsApp that are never sent

**Problem.** `NotificationGatewayService::dispatch()` calls `$cost = $settings->recordSend($channel)` at line 103, **before** attempting any delivery. For SMS and WhatsApp the code then falls into a branch (lines 164-199) that builds an `$omnichannelPayload`, writes a log row with `'status' => STATUS_STAGED`, and returns. No gateway is called; no message leaves the system. The log row nonetheless records `'cost_billed' => $cost`.

**Root cause.** Cost was treated as a property of *intent to send* rather than of *having sent*. `recordSend()` returns `sms_cost_rate` / `whatsapp_cost_rate` unconditionally, and the staged branch propagated that number into the billing column without asking whether delivery had occurred. The `STATUS_STAGED` constant is honest about the state; the `cost_billed` value beside it is not.

**Consequence.** Every staged SMS and WhatsApp accrues a real charge against the tenant for a message that was never delivered. Because SMS is the highest per-unit rate in the system, this is the most expensive possible thing to get wrong.

**Solution.** Bill nothing for a channel that was only staged. Record `cost_billed => 0` on the staged row and keep the would-be cost in `metadata['would_bill']` so the figure survives for when a real gateway is wired in.

### F3 — `sent_at` is populated on messages that were never sent

**Problem.** The staged branch sets `'sent_at' => now()` on a row whose status is `STATUS_STAGED`.

**Root cause.** `sent_at` was used as a generic "we processed this at" timestamp rather than the specific claim it makes.

**Consequence.** Two-fold. Any report or export that counts `sent_at` over-reports delivery. More subtly, `isRateLimited()` (lines 205-226) filters on `->where('sent_at', '>=', $since)`, so the anti-abuse cooldown depends on `sent_at` being set — which means the naive fix of nulling it silently disables cooldown for SMS and WhatsApp and allows a recipient to be re-messaged without limit.

**Solution.** Null `sent_at` on staged rows, and switch `isRateLimited()` to filter on `created_at`, which is set for every row regardless of outcome. The cooldown then measures "when did we last try", which is what it always meant.

### F4 — A failed email permanently consumes the tenant's monthly quota

**Problem.** `TenantNotificationSetting::recordSend('email')` runs `$this->increment('email_used_this_month')` at line 110, before delivery is attempted. When `Mail::send()` throws, the catch block (lines 137-163) writes a `STATUS_FAILED` log row with `'cost_billed' => 0` — correctly declining to bill — but never decrements the counter.

**Root cause.** Billing and quota were treated as one decision made at one moment. The failure path was written with billing in mind (`cost_billed => 0`) and quota overlooked, because the increment lives in a different class.

**Consequence.** A tenant whose SMTP is misconfigured, or who has a run of bounces, burns their monthly allowance on messages nobody received, and reaches `STATUS_SUPPRESSED_QUOTA` early. The loss is silent and unrecoverable.

**Solution.** Give `TenantNotificationSetting` a `refundSend(string $channel): void` that reverses the increment, and call it from the email failure branch. Guard against dropping below zero.

### F5 — Two ledgers record the same money, and the new one is missing three paths

**Problem.** `EventLedgerEntry` (single-row, signed net) and the new double-entry `LedgerService` (`LedgerTransaction` + `LedgerEntry` + `LedgerAccount`) both record event money. `SettlementWebhookController::confirmPlatformDefaultCharge()` writes both — `EventLedgerEntry::create()` at line 175 and `LedgerService::recordTicketSale()` at line 189. Three other paths write only the old ledger:

| Money path | File | Old | New |
|---|---|---|---|
| Own-gateway ticket sale | `app/Http/Controllers/Tenant/Commerce/WebhookController.php:175` | yes | **no** |
| Payout confirmed by webhook | `app/Http/Controllers/Public/SettlementWebhookController.php:222` | yes | **no** |
| Reconciled stuck payout | `app/Console/Commands/Events/ReconcileStuckPayouts.php:90` | yes | **no** |

**Root cause.** The new ledger was introduced at the busiest call site and the remaining writers of the old ledger were never enumerated. There is no shared seam — each site hand-rolls its `EventLedgerEntry::create()` — so adding a second ledger meant finding every one of them by hand, and three were missed.

**Consequence.** `LedgerService::getTrialBalance()` returns `is_balanced => true` while omitting entire classes of transaction. A tenant on `own_gateway` settlement has **no** double-entry record of their ticket sales at all. Payouts are debited from `ORGANIZER_PAYABLE` only when they happen to be confirmed through the one instrumented path, so the payable balance drifts upward and never clears. A balanced-looking trial balance that is missing rows is worse than an obviously broken one, because it invites trust.

**Solution.** Add the matching `LedgerService` call at all three sites, mirroring the settlement webhook exactly. Every variable the call needs is already in scope at each site. Guard each with the same idempotency the surrounding code already uses so webhook redelivery cannot double-post.

### F6 — Abstract code uniqueness is checked per tenant but enforced globally

**Problem.** `EventAbstract::generateCode()` loops `while (self::query()->where('code', $code)->exists())`. `self::query()` carries the global `TenantScope`, so the probe only sees the current tenant's rows. The database column is declared `$table->string('code')->unique()` — a **global** index (`database/migrations/landlord/2026_09_12_100000_create_event_abstracts_table.php:20`).

**Root cause.** The global scope is invisible at the call site. The loop reads as a uniqueness check and is one — against the wrong population.

**Consequence.** Two tenants can generate the same `ABS-XXXXXX`. The check passes for both; the second `INSERT` violates the unique index and throws. The only writer is `AbstractSubmissionController::store()`, a public endpoint, so the failure surfaces as a 500 to an author submitting an abstract. The collision space is `62^6` ≈ 5.7×10¹⁰ so this is rare — but the loop currently provides false assurance and the failure mode is a public crash.

**Solution.** Probe with `self::query()->withoutGlobalScopes()`, matching the population the index covers. Add a bounded retry so an exhausted loop raises a clear exception rather than spinning.

---

## File Structure

**Modified**
- `app/Http/Controllers/MediaController.php` — narrow `ALLOWED_PREFIXES` to genuinely public asset directories.
- `app/Services/Notifications/NotificationGatewayService.php` — stop billing staged channels, stop claiming `sent_at`, refund quota on email failure, move the cooldown probe onto `created_at`.
- `app/Models/TenantNotificationSetting.php` — add `refundSend()`.
- `app/Http/Controllers/Tenant/Commerce/WebhookController.php` — post own-gateway sales to the double-entry ledger.
- `app/Http/Controllers/Public/SettlementWebhookController.php` — post webhook-confirmed payouts to the double-entry ledger.
- `app/Console/Commands/Events/ReconcileStuckPayouts.php` — post reconciled payouts to the double-entry ledger.
- `app/Models/EventAbstract.php` — global uniqueness probe with a bounded retry.

**Created**
- `tests/Feature/Security/MediaRouteBypassTest.php` — already written; currently failing, turns green in Task 1.
- `tests/Feature/Security/CrossTenantAccessProbeTest.php` — already written and passing; keep as a regression guard.
- `tests/Feature/Notifications/NotificationBillingIntegrityTest.php` — Tasks 2-4.
- `tests/Feature/Finance/LedgerParityTest.php` — Task 5.

---

### Task 1: Stop `/media` serving gated documents

**Files:**
- Modify: `app/Http/Controllers/MediaController.php:19-29`
- Test: `tests/Feature/Security/MediaRouteBypassTest.php` (exists, currently failing)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Run the existing failing tests to see the bypass**

```bash
PHP="$HOME/Library/Application Support/Herd/bin/php"
"$PHP" artisan test --compact tests/Feature/Security/MediaRouteBypassTest.php
```

Expected: 2 failed. Both assert a non-200 and currently receive 200 — an embargoed material served in full, and one tenant's material served from another tenant's subdomain.

- [ ] **Step 2: Narrow the allowlist**

In `app/Http/Controllers/MediaController.php`, replace the `ALLOWED_PREFIXES` constant and its docblock with:

```php
    /**
     * Directories this route may stream from.
     *
     * Prefix matching answers "is this path shaped like a media file", which is
     * a traversal defence, not an authorisation one. Only genuinely public
     * assets belong here.
     *
     * Deliberately absent:
     *  - event-materials/ : gated by MaterialDownloadController on confirmed
     *    registration, release date and per-registration download limit. Serving
     *    it here bypassed all three and ignored tenancy.
     *  - users/           : nothing writes here; an open door onto a directory
     *    with no legitimate traffic.
     */
    private const ALLOWED_PREFIXES = [
        'events/',
        'speakers/',
        'event-sponsors/',
        'event-forum/',
        'tenant/',
        'logos/',
    ];
```

- [ ] **Step 3: Run the bypass tests again**

```bash
"$PHP" artisan test --compact tests/Feature/Security/MediaRouteBypassTest.php
```

Expected: 2 passed. Both requests now 404 at the prefix check.

- [ ] **Step 4: Prove nothing legitimate broke**

```bash
"$PHP" artisan test --compact tests/Feature/Media/MediaServeTest.php tests/Feature/Events/EventMaterialTest.php
```

Expected: all pass. `MediaServeTest` covers hero images, S3 serving, the `/storage` fallback, traversal and unwhitelisted directories — all still-allowed prefixes. `EventMaterialTest` covers the gated download path, which is now the only way to reach a material.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/MediaController.php tests/Feature/Security/
git commit -m "$(cat <<'EOF'
fix: stop /media serving documents that another controller exists to gate

MaterialDownloadController gates event materials on a confirmed registration,
the release date and a per-registration download limit. The /media route
served the same bytes with none of that, no tenant scoping, and a year-long
public cache header -- so an embargoed file that the gated route refused with
403 was downloadable in full, and one tenant's material was reachable from
another tenant's subdomain. Both are covered by tests that failed before this
change.

Filenames made the exposure practical rather than theoretical: they come from
uniqid(), a microsecond timestamp in hex, behind a fixed prefix, so paths are
enumerable given a rough upload time.

The allowlist answered "is this shaped like a media file" when the question was
"is this requester entitled to this file". event-materials/ is removed and
reachable only through the gated controller; users/ is removed because nothing
writes to it. event-forum/ stays -- the forum attachment URLs depend on it and
the public forum is already closed for private events.
EOF
)"
```

---

### Task 2: Stop billing for notifications that were never sent

**Files:**
- Modify: `app/Services/Notifications/NotificationGatewayService.php:164-199` (staged branch), `:205-226` (`isRateLimited`)
- Test: `tests/Feature/Notifications/NotificationBillingIntegrityTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `isRateLimited()` filters on `created_at` — Task 3 does not touch it.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Notifications/NotificationBillingIntegrityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\NotificationGatewayService;
use Illuminate\Support\Facades\Artisan;

/**
 * The gateway takes payment at the moment it decides to send, not at the moment
 * something is delivered. For SMS and WhatsApp nothing is delivered at all --
 * there is no gateway yet, only a staged log row -- so every one of those was a
 * real charge for a message that never left the system.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function gatewayScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    // Fields must match $fillable exactly -- Model::shouldBeStrict() is on, so
    // an invented column throws rather than being ignored. There is no
    // email_enabled column: email is always on and bounded by its monthly limit.
    $settings = TenantNotificationSetting::create([
        'tenant_id' => $tenant->id,
        'sms_enabled' => true,
        'whatsapp_enabled' => true,
        'email_monthly_limit' => 100,
        'email_used_this_month' => 0,
        'sms_cost_rate' => 500,
        'whatsapp_cost_rate' => 300,
        'email_overage_rate' => 0,
        'overage_billing_enabled' => false,
        'anti_abuse_cooldown_minutes' => 0,
        'month_reset_at' => now()->startOfMonth(),
    ]);

    return [$tenant, $event, $settings];
}

test('a staged sms is not billed', function () {
    [$tenant, $event] = gatewayScenario('bill-sms');

    app(NotificationGatewayService::class)->dispatch(
        $event,
        null,
        ['name' => 'Ama Mensah', 'email' => null, 'phone' => '233200000000'],
        ['sms'],
        ['subject' => 'Doors open', 'body' => 'See you at 9.'],
    );

    $log = EventNotificationLog::where('event_id', $event->id)->firstOrFail();

    expect($log->status)->toBe(EventNotificationLog::STATUS_STAGED);
    expect((int) $log->cost_billed)->toBe(0);
    // The rate survives for when a real gateway is wired in.
    expect((int) ($log->metadata['would_bill'] ?? -1))->toBe(500);
});

test('a staged message does not claim it was sent', function () {
    [$tenant, $event] = gatewayScenario('bill-sentat');

    app(NotificationGatewayService::class)->dispatch(
        $event,
        null,
        ['name' => 'Kwame Asare', 'email' => null, 'phone' => '233200000001'],
        ['whatsapp'],
        ['subject' => 'Reminder', 'body' => 'Tomorrow.'],
    );

    $log = EventNotificationLog::where('event_id', $event->id)->firstOrFail();

    expect($log->status)->toBe(EventNotificationLog::STATUS_STAGED);
    expect($log->sent_at)->toBeNull();
});

test('the anti-abuse cooldown still suppresses a staged repeat', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-cooldown');
    $settings->update(['anti_abuse_cooldown_minutes' => 60]);

    $recipient = ['name' => 'Ama Mensah', 'email' => null, 'phone' => '233200000002'];
    $payload = ['subject' => 'Doors open', 'body' => 'See you at 9.'];

    app(NotificationGatewayService::class)->dispatch($event, null, $recipient, ['sms'], $payload);
    $second = app(NotificationGatewayService::class)->dispatch($event, null, $recipient, ['sms'], $payload);

    // Nulling sent_at must not disable the cooldown: it now measures when we
    // last tried, which is what it always meant.
    expect($second['sms']['status'])->toBe('rate_limited');
    expect(EventNotificationLog::where('event_id', $event->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
"$PHP" artisan test --compact tests/Feature/Notifications/NotificationBillingIntegrityTest.php
```

Expected: FAIL. `cost_billed` is 500 not 0; `metadata['would_bill']` is absent; `sent_at` is a timestamp not null.

- [ ] **Step 3: Bill nothing for a staged channel**

In `app/Services/Notifications/NotificationGatewayService.php`, in the SMS/WhatsApp branch, replace the `$omnichannelPayload` assignment and the `EventNotificationLog::create([...])` call with:

```php
                // Staged for Omnichannel Gateway integration. Nothing has been
                // delivered, so nothing is billed and nothing claims to have
                // been sent -- the rate is kept so the figure survives for when
                // a real gateway is wired in.
                $omnichannelPayload = [
                    'provider' => 'omnichannel',
                    'channel' => $channel,
                    'to' => $phone,
                    'recipient_name' => $name,
                    'message' => $payload['body'],
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'staged_at' => now()->toIso8601String(),
                    'would_bill' => $cost,
                ];

                EventNotificationLog::create([
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'rule_id' => $rule?->id,
                    'recipient_name' => $name,
                    'recipient_email' => $email,
                    'recipient_phone' => $phone,
                    'channel' => $channel,
                    'status' => EventNotificationLog::STATUS_STAGED,
                    'subject' => $payload['subject'],
                    'message' => $payload['body'],
                    'cost_billed' => 0,
                    'metadata' => $omnichannelPayload,
                    'sent_at' => null,
                ]);

                $results[$channel] = [
                    'status' => EventNotificationLog::STATUS_STAGED,
                    'message' => "Staged for Omnichannel {$channel} gateway dispatch. Not billed until delivered.",
                    'cost' => 0,
                ];
```

- [ ] **Step 4: Move the cooldown probe onto `created_at`**

In the same file, in `isRateLimited()`, replace:

```php
            ->where('sent_at', '>=', $since)
```

with:

```php
            // created_at, not sent_at: a staged row has no sent_at, and the
            // cooldown means "when did we last try", not "when did we last
            // deliver".
            ->where('created_at', '>=', $since)
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test --compact tests/Feature/Notifications/NotificationBillingIntegrityTest.php
```

Expected: 3 passed.

- [ ] **Step 6: Check nothing else depended on the old behaviour**

```bash
"$PHP" artisan test --compact tests/Feature/Events/EventAutomatedNotificationsTest.php
```

Expected: all pass. If one asserts a non-zero cost on a staged SMS, update it — that assertion encoded the bug — and say so in the commit message.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Notifications/NotificationGatewayService.php tests/Feature/Notifications/
git commit -m "$(cat <<'EOF'
fix: do not bill for SMS and WhatsApp that were never sent

recordSend() runs before delivery is attempted, and SMS and WhatsApp have no
gateway behind them -- they fall into a branch that writes a staged log row and
returns. That row carried cost_billed set to the channel rate, so every staged
message was a real charge for something that never left the system, at the
highest per-unit rate in the product.

Staged rows now bill zero and keep the rate in metadata.would_bill, so the
figure is there when a gateway is wired in.

The same rows also set sent_at, which over-reported delivery in any report
counting it. sent_at is now null for staged messages. That cannot simply be
nulled, though: isRateLimited() filtered on sent_at, so doing so alone would
have disabled the anti-abuse cooldown for exactly the two channels that had no
other limit. The cooldown now filters on created_at, which is set whatever the
outcome and matches what it always meant -- when did we last try.
EOF
)"
```

---

### Task 3: Refund the email quota when sending fails

**Files:**
- Modify: `app/Models/TenantNotificationSetting.php` (add `refundSend()` after `recordSend()`, which ends at line 121)
- Modify: `app/Services/Notifications/NotificationGatewayService.php:137-163` (email failure branch)
- Test: `tests/Feature/Notifications/NotificationBillingIntegrityTest.php` (append)

**Interfaces:**
- Consumes: `NotificationBillingIntegrityTest` and its `gatewayScenario()` helper from Task 2.
- Produces: `TenantNotificationSetting::refundSend(string $channel): void`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Notifications/NotificationBillingIntegrityTest.php`:

```php
test('a failed email does not consume the monthly quota', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-refund');

    // Force the send to throw by pointing the mailer at a transport that cannot
    // exist, which is what a misconfigured SMTP looks like from here.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    app(NotificationGatewayService::class)->dispatch(
        $event,
        null,
        ['name' => 'Ama Mensah', 'email' => 'ama@example.com', 'phone' => null],
        ['email'],
        ['subject' => 'Doors open', 'body' => 'See you at 9.'],
    );

    $log = EventNotificationLog::where('event_id', $event->id)->firstOrFail();
    expect($log->status)->toBe(EventNotificationLog::STATUS_FAILED);

    // The allowance is the tenant's to spend on delivered mail. A bounce must
    // not burn it.
    expect((int) $settings->fresh()->email_used_this_month)->toBe(0);
});

test('refunding a channel never drives the counter below zero', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-floor');

    $settings->refundSend('email');
    $settings->refundSend('email');

    expect((int) $settings->fresh()->email_used_this_month)->toBe(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
"$PHP" artisan test --compact tests/Feature/Notifications/NotificationBillingIntegrityTest.php --filter="quota|below zero"
```

Expected: FAIL — `email_used_this_month` is 1, and `refundSend()` does not exist.

- [ ] **Step 3: Add `refundSend()`**

In `app/Models/TenantNotificationSetting.php`, immediately after the `recordSend()` method:

```php
    /**
     * Reverse a send that was counted but never delivered.
     *
     * recordSend() increments before delivery is attempted, so a throw leaves
     * the allowance spent on a message nobody received. Only email is metered;
     * the other channels carry a per-message rate and no counter.
     */
    public function refundSend(string $channel): void
    {
        if ($channel !== 'email') {
            return;
        }

        if ((int) $this->email_used_this_month <= 0) {
            return;
        }

        $this->decrement('email_used_this_month');
    }
```

- [ ] **Step 4: Call it from the email failure branch**

In `app/Services/Notifications/NotificationGatewayService.php`, inside `catch (Throwable $e) {`, immediately after the `Log::error(...)` call and before `EventNotificationLog::create([`:

```php
                    // The allowance was taken before the attempt; give it back.
                    $settings->refundSend(EventNotificationLog::CHANNEL_EMAIL);
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test --compact tests/Feature/Notifications/NotificationBillingIntegrityTest.php
```

Expected: 5 passed.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Models/TenantNotificationSetting.php app/Services/Notifications/NotificationGatewayService.php tests/Feature/Notifications/
git commit -m "$(cat <<'EOF'
fix: give back the email allowance when a send fails

recordSend() increments email_used_this_month before Mail::send() is attempted.
The failure branch was written with billing in mind -- it correctly sets
cost_billed to zero -- but the counter lives in another class and was missed, so
a bounce permanently consumed a slot the tenant had paid for. A run of failures
walks a tenant into suppressed_quota on messages nobody received, silently and
with no way to recover the allowance.

refundSend() reverses it, floored at zero so a double refund cannot manufacture
quota. Only email is metered; the other channels carry a rate and no counter.
EOF
)"
```

---

### Task 4: Bring all three money paths onto the double-entry ledger

**Files:**
- Modify: `app/Http/Controllers/Tenant/Commerce/WebhookController.php` (after the `EventLedgerEntry::create()` block starting line 175)
- Modify: `app/Http/Controllers/Public/SettlementWebhookController.php` (after the `EventLedgerEntry::create()` block starting line 222)
- Modify: `app/Console/Commands/Events/ReconcileStuckPayouts.php` (after the `EventLedgerEntry::create()` block starting line 90)
- Test: `tests/Feature/Finance/LedgerParityTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing later tasks depend on.
- Uses existing signatures: `LedgerService::recordTicketSale(Event $event, EventRegistration $registration, int $grossAmount, int $platformFee, string $reference): ?LedgerTransaction` and `LedgerService::recordPayout(Event $event, int $amount, string $reference, ?EventPayout $payout = null): LedgerTransaction`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/LedgerParityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;

/**
 * Two ledgers record the same money: EventLedgerEntry and the double-entry
 * LedgerService. Only the platform-settled sale writes both. Every other path
 * writes the old one alone, so the trial balance reports is_balanced while
 * entire classes of transaction are missing from it.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a payout posts to the double-entry ledger', function () {
    $tenant = Tenant::factory()->create(['slug' => 'ledger-payout', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $service = app(LedgerService::class);
    $service->ensureAccountsExist($tenant, $event, 'GHS');

    $payout = EventPayout::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 50000,
        'currency' => 'GHS',
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $service->recordPayout($event, $payout->amount, 'PAYOUT-REF-1', $payout);

    $transaction = LedgerTransaction::where('event_id', $event->id)->firstOrFail();
    $debits = $transaction->entries()->where('direction', 'debit')->sum('amount');
    $credits = $transaction->entries()->where('direction', 'credit')->sum('amount');

    expect((int) $debits)->toBe(50000);
    expect((int) $debits)->toBe((int) $credits);

    // The organizer payable must actually fall, or the balance drifts upward
    // and never clears.
    $payable = LedgerAccount::where('event_id', $event->id)
        ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
        ->firstOrFail();

    expect((int) $payable->entries()->where('direction', 'debit')->sum('amount'))->toBe(50000);
});

test('the trial balance stays balanced after a payout', function () {
    $tenant = Tenant::factory()->create(['slug' => 'ledger-trial', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $service = app(LedgerService::class);
    $service->ensureAccountsExist($tenant, $event, 'GHS');
    $service->recordPayout($event, 25000, 'PAYOUT-REF-2');

    expect($service->getTrialBalance($event)['is_balanced'])->toBeTrue();
});
```

- [ ] **Step 2: Run it to establish the baseline**

```bash
"$PHP" artisan test --compact tests/Feature/Finance/LedgerParityTest.php
```

Expected: PASS. These exercise `LedgerService` directly and prove the service is sound — the defect is that three call sites never reach it. They are the regression floor for Steps 3-5.

- [ ] **Step 3: Post own-gateway ticket sales**

In `app/Http/Controllers/Tenant/Commerce/WebhookController.php`, immediately after the `EventLedgerEntry::create([...]);` call that ends the charge block, add:

```php
        // The platform-settled path posts to both ledgers; this one recorded
        // only the old single-row entry, so an own_gateway tenant had no
        // double-entry record of their ticket sales at all.
        app(\App\Services\Finance\LedgerService::class)->recordTicketSale(
            $eventModel,
            $registration,
            $amount,
            $commissionAmount,
            $reference
        );
```

- [ ] **Step 4: Post webhook-confirmed payouts**

In `app/Http/Controllers/Public/SettlementWebhookController.php`, immediately after the `EventLedgerEntry::create([...]);` call inside the payout block, add:

```php
            // Without this the organizer payable is only ever debited on paths
            // that happen to be instrumented, so the balance drifts upward.
            app(\App\Services\Finance\LedgerService::class)->recordPayout(
                $payout->event,
                (int) $payout->amount,
                $reference,
                $payout
            );
```

- [ ] **Step 5: Post reconciled payouts**

In `app/Console/Commands/Events/ReconcileStuckPayouts.php`, immediately after the `EventLedgerEntry::create([...]);` call inside the `if (! $locked->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->exists())` guard, add:

```php
                    // Inside the same idempotency guard as the entry above, so
                    // a re-run cannot double-post.
                    app(\App\Services\Finance\LedgerService::class)->recordPayout(
                        $locked->event,
                        (int) $locked->amount,
                        (string) $locked->provider_reference,
                        $locked
                    );
```

- [ ] **Step 6: Run the money tests**

```bash
"$PHP" artisan test --compact tests/Feature/Finance/ tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php tests/Feature/Events/EventFinanceLedgerTest.php tests/Feature/Events/PayoutSendingTest.php
```

Expected: all pass.

- [ ] **Step 7: Run the webhook tests**

```bash
"$PHP" artisan test --compact tests/Feature/Events/PaystackWebhookHardeningTest.php tests/Feature/Events/PlatformDefaultCheckoutTest.php tests/Feature/Events/ChargeLedgerEntryTest.php
```

Expected: all pass. `ReconcileStuckPayouts` posts from a console command where `TenantContext` is empty and `TenantScope` is a no-op — confirm `recordPayout()` resolves the tenant from `$locked->event`, not from context.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Tenant/Commerce/WebhookController.php app/Http/Controllers/Public/SettlementWebhookController.php app/Console/Commands/Events/ReconcileStuckPayouts.php tests/Feature/Finance/
git commit -m "$(cat <<'EOF'
fix: post every money path to the double-entry ledger, not just one

The double-entry ledger was introduced at the busiest call site -- the
platform-settled ticket sale -- and the other writers of EventLedgerEntry were
never enumerated. Three were missed: own-gateway ticket sales, payouts
confirmed by the settlement webhook, and payouts recovered by
payouts:reconcile.

The effect was not a visibly broken ledger, which would have been easier to
catch, but a trial balance reporting is_balanced while whole classes of
transaction were absent from it. A tenant on own_gateway settlement had no
double-entry record of their ticket sales at all, and the organizer payable was
only ever debited on the one instrumented path, so it drifted upward and never
cleared.

Each call sits beside the EventLedgerEntry write it mirrors and inside the same
idempotency guard, so webhook redelivery and command re-runs cannot double-post.
EOF
)"
```

---

### Task 5: Check abstract code uniqueness against the index that enforces it

**Files:**
- Modify: `app/Models/EventAbstract.php:76-83`
- Test: `tests/Feature/Events/EventAbstractSubmissionTest.php` (append)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `EventAbstract::generateCode(): string` keeps its signature.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Events/EventAbstractSubmissionTest.php`:

```php
test('abstract codes are unique across tenants, not just within one', function () {
    $tenantA = App\Models\Tenant::factory()->create(['slug' => 'code-a', 'isolation_mode' => 'shared']);
    $tenantB = App\Models\Tenant::factory()->create(['slug' => 'code-b', 'isolation_mode' => 'shared']);

    $eventA = App\Models\Event::factory()->published()->create(['tenant_id' => $tenantA->id]);

    App\Models\EventAbstract::create([
        'tenant_id' => $tenantA->id,
        'event_id' => $eventA->id,
        'code' => 'ABS-TAKEN',
        'title' => 'Existing',
        'body' => 'Body',
        'status' => App\Models\EventAbstract::STATUS_SUBMITTED,
    ]);

    // The column is globally unique, so the probe must see across tenants. With
    // the tenant scope applied it cannot, and hands back a code that will
    // violate the index on insert.
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenantB);

    expect(App\Models\EventAbstract::codeExists('ABS-TAKEN'))->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
"$PHP" artisan test --compact tests/Feature/Events/EventAbstractSubmissionTest.php --filter="unique across tenants"
```

Expected: FAIL — `codeExists()` does not exist.

- [ ] **Step 3: Widen the probe and bound the retry**

In `app/Models/EventAbstract.php`, replace the `generateCode()` method with:

```php
    /**
     * Whether a code is already taken anywhere.
     *
     * The code column carries a global unique index, so this deliberately drops
     * the tenant scope: a probe that only sees the current tenant's rows would
     * approve a code another tenant already holds, and the insert would then
     * violate the index.
     */
    public static function codeExists(string $code): bool
    {
        return self::query()->withoutGlobalScopes()->where('code', $code)->exists();
    }

    public static function generateCode(): string
    {
        foreach (range(1, 10) as $ignored) {
            $code = 'ABS-'.mb_strtoupper(Str::random(6));

            if (! self::codeExists($code)) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique abstract code after 10 attempts.');
    }
```

Add `use RuntimeException;` to the imports if it is not already present.

- [ ] **Step 4: Run the test**

```bash
"$PHP" artisan test --compact tests/Feature/Events/EventAbstractSubmissionTest.php
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Models/EventAbstract.php tests/Feature/Events/EventAbstractSubmissionTest.php
git commit -m "$(cat <<'EOF'
fix: check abstract code uniqueness against the index that enforces it

generateCode() probed with self::query(), which carries the global TenantScope,
so it only ever saw the current tenant's codes. The column is declared unique
globally. The loop therefore read as a uniqueness check while checking the wrong
population -- it could approve a code another tenant already held, and the
insert would violate the index.

The only writer is the public abstract submission endpoint, so the failure
surfaced as a 500 to an author mid-submission. The collision space is 62^6 so it
is remote, but the loop was giving false assurance.

The probe now drops the global scopes to match the index, and the retry is
bounded so an exhausted loop raises something legible instead of spinning.
EOF
)"
```

---

### Task 6: Confirm the whole suite and record what changed

**Files:**
- Test: entire suite

**Interfaces:**
- Consumes: every preceding task.
- Produces: nothing.

- [ ] **Step 1: Run the full suite**

```bash
"$PHP" artisan test --compact
```

Expected: 0 failures. The baseline before this plan was 745 tests with 2 failing (the media bypass probes) and 1 skipped. After Task 1 those 2 pass; Tasks 2-5 add 8 more.

- [ ] **Step 2: Confirm nothing was silently dropped**

```bash
git log --oneline b3949af..HEAD | head -25
"$PHP" artisan test --compact 2>&1 | tail -3
```

Compare the test total against the pre-plan baseline. It must be **higher**, never lower — a falling count means a test was removed or stopped being collected, which this plan forbids.

- [ ] **Step 3: Verify no new dead references**

```bash
grep -rn "event-materials/" app/Http/Controllers/MediaController.php || echo "media route no longer serves materials"
grep -rn "'cost_billed' => \$cost" app/Services/Notifications/NotificationGatewayService.php || echo "no staged row bills a cost"
grep -c "LedgerService" app/Http/Controllers/Tenant/Commerce/WebhookController.php
```

Expected: the first two print their confirmation lines; the third prints `1` or more.

- [ ] **Step 4: Commit any stragglers**

```bash
git status --short
```

Expected: clean. If not, the working tree holds something no task accounted for — read it before committing.

---

## Notes for the original author

Five of the six defects share one shape: **a check was written against the wrong population, or at the wrong moment.**

- The media allowlist checked the *path's shape* instead of the *requester's entitlement*.
- `recordSend()` charged at the moment of *intent* instead of the moment of *delivery*.
- The quota decrement was omitted because billing and quota were treated as one decision living in one place, when they live in two classes.
- `sent_at` recorded *processing* while its name and its readers assume *delivery*.
- `generateCode()` probed the *tenant's* rows while the index covers *all* rows.

The sixth — the ledger gap — is different in kind: it is what happens when a second system is added beside a first without enumerating every writer of the first. There was no shared seam to extend, so correctness depended on finding six hand-rolled `EventLedgerEntry::create()` calls by hand, and three were missed.

Two of these were invisible to the test suite because the tests asked whether the feature *worked*, not whether it *held*. `MediaServeTest` has seven tests covering path traversal and unwhitelisted directories — real defensive thinking — but none asks whether a given requester should receive a given file. Worth carrying forward: for any feature that gates something, write the test that proves the gate holds, not only the one that proves the door opens.
