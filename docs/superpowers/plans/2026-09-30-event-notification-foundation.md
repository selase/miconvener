# Event Notification Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make event notification delivery atomically deduplicated, retry-safe, tenant-aware, and connect the registration, check-in and material-upload triggers already offered in the organiser console.

**Architecture:** A claim service writes one pending `EventNotificationLog` per business occurrence, recipient and channel using a PostgreSQL partial unique index. A tenant-aware job delivers that claimed row after commit; trigger dispatchers resolve either one triggering registration or a material audience and use stable keys. Existing scheduled rules use the same claims without changing their timing semantics.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Eloquent, queued jobs, Pest 5, Inertia/React

**Spec:** `docs/superpowers/specs/2026-09-30-event-notification-foundation-design.md`

## Global Constraints

- PostgreSQL landlord database only; all new event-domain rows carry `tenant_id` and use existing tenant context conventions.
- Email credits apply only to organiser-authored event communication.
- SMS and WhatsApp remain staged and unbilled.
- Delivery jobs dispatch explicitly with `afterCommit()` because queue connections set `after_commit` to false.
- Scheduled rules stay one-shot with a fifteen-minute scheduler and 24-hour catch-up.
- No historical event is replayed when triggers are wired.
- Do not add dependencies.

## Review Focus

- Duplicate browser/webhook/check-in calls must produce one claim and one email; covered in Tasks 2 and 4.
- A registration trigger must never expand to all confirmed registrations; covered in Task 3.
- Two different notification types sent close together must not suppress one another; covered in Task 2.
- A rolled-back transaction must queue nothing; covered in Task 4.
- A failed SMTP attempt must refund the email credit while retaining a diagnosable failed claim; covered in Task 2.

---

### Task 1: Add delivery-claim fields and model contract

**Files:**
- Create: `database/migrations/landlord/2026_09_30_090000_add_delivery_claims_to_event_notification_logs.php`
- Modify: `app/Models/EventNotificationLog.php`
- Test: `tests/Feature/Events/EventNotificationDeliveryClaimTest.php`

**Interfaces:**
- Produces statuses `pending`, `sent`, `staged_omnichannel`, `failed`, `suppressed_quota`, `unsubscribed`, `skipped`.
- Produces nullable `notification_type`, `dedupe_key`, `source_type`, `source_id`, integer `attempts`, nullable `last_attempted_at`.
- Produces a partial unique index named `event_notification_logs_delivery_dedupe_unique` over `(tenant_id, channel, dedupe_key)` where the key is not null.

- [ ] **Step 1: Create the migration with Artisan**

Run:

```bash
php artisan make:migration add_delivery_claims_to_event_notification_logs --path=database/migrations/landlord --table=event_notification_logs --no-interaction
```

- [ ] **Step 2: Write a failing schema/model test**

Create a Pest feature test that refreshes tenant databases, inserts two rows with the same tenant/channel/dedupe key using `insertOrIgnore`, and expects the second insert to return `0`. Also assert the new casts for attempts and `last_attempted_at`.

- [ ] **Step 3: Run the test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/Events/EventNotificationDeliveryClaimTest.php
```

Expected: failure because the columns do not exist.

- [ ] **Step 4: Implement the additive migration and model constants/fillable/casts**

Use nullable columns for compatibility. Add the partial index with `DB::connection('landlord')->statement(...)`; drop it by exact name in `down()`. Add `STATUS_PENDING` and `STATUS_SKIPPED` constants.

- [ ] **Step 5: Run the focused test and formatting**

Run:

```bash
php artisan test --compact tests/Feature/Events/EventNotificationDeliveryClaimTest.php
vendor/bin/pint --dirty
```

Expected: PASS.

- [ ] **Step 6: Self-review Task 1**

Confirm migration rollback drops the index before columns, legacy null keys can coexist, model field names match the spec, and no unrelated schema changed.

- [ ] **Step 7: Commit**

```bash
git add app/Models/EventNotificationLog.php database/migrations/landlord/2026_09_30_090000_add_delivery_claims_to_event_notification_logs.php tests/Feature/Events/EventNotificationDeliveryClaimTest.php
git commit -m "feat(notifications): add atomic event delivery claims"
```

### Task 2: Claim and deliver one recipient safely

**Files:**
- Create: `app/Services/Notifications/NotificationDeliveryClaimService.php`
- Create: `app/Jobs/Notifications/SendEventNotificationDeliveryJob.php`
- Modify: `app/Services/Notifications/NotificationGatewayService.php`
- Modify: `app/Models/EventNotificationLog.php`
- Test: `tests/Feature/Events/EventNotificationDeliveryClaimTest.php`
- Test: `tests/Feature/Events/EmailCreditMeteringTest.php`

**Interfaces:**
- Produces `NotificationDeliveryClaimService::claim(Event $event, ?EventNotificationRule $rule, string $notificationType, string $dedupeKey, array $recipient, string $channel, array $payload, ?Model $source = null): ?EventNotificationLog`.
- Produces `NotificationDeliveryClaimService::dispatch(EventNotificationLog $delivery): void`, which dispatches `SendEventNotificationDeliveryJob` with `afterCommit()`.
- Produces `NotificationGatewayService::deliver(EventNotificationLog $delivery): array{status:string,message:string,cost:int}`.

- [ ] **Step 1: Write failing tests**

Test that two identical claims return a log then `null`; two notification types with distinct keys to the same recipient both claim; dispatch inside a rolled-back transaction places no job on the queue; successful mail marks sent and increments credits; SMTP failure marks failed and refunds credit; retrying a sent log sends nothing.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/Events/EventNotificationDeliveryClaimTest.php tests/Feature/Events/EmailCreditMeteringTest.php
```

- [ ] **Step 3: Implement atomic claiming**

Normalize recipient email/phone in one private method. Use `insertOrIgnore` with a UUIDv7 ID and then load by ID. Never catch a PostgreSQL unique exception. Store subject/body/action metadata in the claim.

- [ ] **Step 4: Implement the tenant-aware delivery job**

Capture `tenantId` in the constructor, use `TenantAwareJob`, load the delivery under tenant context, return for terminal statuses, and call the gateway. Configure bounded attempts/backoff consistent with existing jobs.

- [ ] **Step 5: Refactor the gateway to execute a claim**

Move quota checking, synchronous email send, staged channel handling, attempts and status transitions into `deliver()`. Remove the broad event/recipient cooldown as a correctness mechanism; the dedupe key owns duplicate prevention. Preserve credit refund on mail failure.

- [ ] **Step 6: Run tests and verify GREEN**

```bash
php artisan test --compact tests/Feature/Events/EventNotificationDeliveryClaimTest.php tests/Feature/Events/EmailCreditMeteringTest.php tests/Feature/Events/EventAutomatedNotificationsTest.php
vendor/bin/pint --dirty
```

- [ ] **Step 7: Self-review Task 2**

Trace success, quota suppression, missing contact, staged channel, known SMTP failure, ambiguous worker retry and already-terminal retry. Confirm log status never claims delivery before `sendNow()` succeeds.

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/Notifications/SendEventNotificationDeliveryJob.php app/Models/EventNotificationLog.php app/Services/Notifications/NotificationDeliveryClaimService.php app/Services/Notifications/NotificationGatewayService.php tests/Feature/Events/EventNotificationDeliveryClaimTest.php tests/Feature/Events/EmailCreditMeteringTest.php
git commit -m "feat(notifications): deliver claimed event messages safely"
```

### Task 3: Split bulk and occurrence-specific rule dispatch

**Files:**
- Modify: `app/Services/Notifications/AutomatedNotificationDispatcher.php`
- Modify: `app/Jobs/Notifications/DispatchNotificationRuleJob.php`
- Modify: `app/Console/Commands/DispatchAutomatedNotificationsCommand.php`
- Test: `tests/Feature/Events/EventAutomatedNotificationsTest.php`

**Interfaces:**
- Produces `dispatchScheduledRule(EventNotificationRule $rule): array`.
- Produces `dispatchRegistrationRules(EventRegistration $registration): int`.
- Produces `dispatchCheckInRules(EventRegistration $registration, string $occurrenceKey): int`.
- Produces `dispatchMaterialRules(EventMaterial $material): int`.
- Private recipient rendering feeds `NotificationDeliveryClaimService`, never sends directly.

- [ ] **Step 1: Write failing occurrence tests**

Create two confirmed registrations, trigger registration/check-in for one, and assert only that address receives/claims a message. Add non-matching audience tests. Add a material test proving all matching recipients claim once and a repeated call claims none.

- [ ] **Step 2: Run the tests and verify RED**

```bash
php artisan test --compact tests/Feature/Events/EventAutomatedNotificationsTest.php
```

- [ ] **Step 3: Refactor scheduled dispatch**

Rename the bulk entry point, preserve stats returned to the command and manual-send job, and generate a stable key from rule ID, target timestamp, recipient and channel.

- [ ] **Step 4: Add registration/check-in/material methods**

Registration and check-in evaluate the supplied registration against `target_audience` without loading the whole audience. Material rules use the existing audience resolver. Each method queries only active rules of its exact trigger type.

- [ ] **Step 5: Update command and manual job callers**

Scheduled command calls only `dispatchScheduledRule`. Manual console send retains an explicit bulk-preview/send method and cannot masquerade as a domain occurrence.

- [ ] **Step 6: Run tests and formatting**

```bash
php artisan test --compact tests/Feature/Events/EventAutomatedNotificationsTest.php tests/Feature/Events/EmailCreditMeteringTest.php
vendor/bin/pint --dirty
```

- [ ] **Step 7: Self-review Task 3**

Confirm each trigger queries exact rule types, recipient matching handles ticket/group audiences, scheduled last-dispatched semantics remain one-shot, and per-channel keys differ.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/DispatchAutomatedNotificationsCommand.php app/Jobs/Notifications/DispatchNotificationRuleJob.php app/Services/Notifications/AutomatedNotificationDispatcher.php tests/Feature/Events/EventAutomatedNotificationsTest.php
git commit -m "feat(notifications): dispatch event occurrence rules"
```

### Task 4: Wire domain workflows after commit

**Files:**
- Create: `app/Services/Notifications/EventRuleTriggerService.php`
- Modify: `app/Http/Controllers/Public/PublicEventController.php`
- Modify: `app/Http/Controllers/Public/SettlementWebhookController.php`
- Modify: `app/Http/Controllers/Tenant/Commerce/WebhookController.php`
- Modify: `app/Http/Controllers/Tenant/EventRegistrationController.php`
- Modify: `app/Http/Controllers/Tenant/EventCheckInController.php`
- Modify: `app/Services/Events/SelfCheckIn.php`
- Modify: `app/Http/Controllers/Tenant/EventMaterialController.php`
- Test: `tests/Feature/Events/EventNotificationTriggerIntegrationTest.php`

**Interfaces:**
- Produces `registrationCompleted(EventRegistration $registration): void`.
- Produces `registrationCheckedIn(EventRegistration $registration, string $occurrenceKey): void`.
- Produces `materialPublished(EventMaterial $material): void`.
- Every method schedules dispatcher work after commit and owns occurrence-key construction.

- [ ] **Step 1: Inventory every state-transition call site in the test**

Build a Pest dataset covering public free registration after email verification, approval/waitlist promotion, paid settlement webhook, tenant-commerce webhook, host check-in, scanner check-in, platform attendee self-check-in through `SelfCheckIn`, and material upload.

- [ ] **Step 2: Write failing endpoint tests**

Assert each successful transition dispatches the corresponding trigger once, duplicate callbacks/scans do not, rollback/failure paths do not, and delayed materials do not claim an immediate-publication rule.

- [ ] **Step 3: Run the integration test and verify RED**

```bash
php artisan test --compact tests/Feature/Events/EventNotificationTriggerIntegrationTest.php
```

- [ ] **Step 4: Implement the trigger service and wire all audited paths**

Keep controller additions to one service call after the durable transition. Reuse the same registration occurrence identity in callback and webhook paths. Do not dispatch from model observers.

- [ ] **Step 5: Run affected registration/check-in/material suites**

```bash
php artisan test --compact tests/Feature/Events/EventNotificationTriggerIntegrationTest.php tests/Feature/Events/EventRegistrationTest.php tests/Feature/Events/RegistrationEmailVerificationTest.php tests/Feature/Events/PaystackWebhookHardeningTest.php tests/Feature/Events/EventMaterialTest.php tests/Feature/Events/EventAutomatedNotificationsTest.php
vendor/bin/pint --dirty
```

- [ ] **Step 6: Self-review Task 4**

Search all writes to registration status `confirmed`/`checked_in` and all material creates; account for every route or explicitly document why it cannot trigger. Confirm no event fires before payment/email verification reaches its final state.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Notifications/EventRuleTriggerService.php app/Http/Controllers/Public/PublicEventController.php app/Http/Controllers/Public/SettlementWebhookController.php app/Http/Controllers/Tenant/Commerce/WebhookController.php app/Http/Controllers/Tenant/EventRegistrationController.php app/Http/Controllers/Tenant/EventCheckInController.php app/Services/Events/SelfCheckIn.php app/Http/Controllers/Tenant/EventMaterialController.php tests/Feature/Events/EventNotificationTriggerIntegrationTest.php
git commit -m "feat(notifications): wire event automation triggers"
```

### Task 5: Console copy, regression verification and release audit

**Files:**
- Modify: `resources/js/Pages/Tenant/Events/panels/NotificationsPanel.jsx`
- Modify: `app/Http/Controllers/Tenant/EventNotificationRuleController.php` if API history fields need exposure
- Modify: `tests/Feature/Events/EventAutomatedNotificationsTest.php`
- Test: `tests/Feature/Console/ScheduledNotificationTest.php` or existing scheduler test

**Interfaces:**
- Console explains occurrence-specific audience behavior and staged channels.
- Delivery history exposes notification type, source, attempts and truthful status.

- [ ] **Step 1: Write failing API/scheduler assertions**

Assert history fields and scheduler registration remain present.

- [ ] **Step 2: Implement console/API copy and states**

Do not add real SMS/WhatsApp controls. Mark staged channels accurately.

- [ ] **Step 3: Run backend and frontend verification**

```bash
php artisan test --compact tests/Feature/Events/EventAutomatedNotificationsTest.php tests/Unit/Console/ScheduledUsageResetTest.php
npm run build
vendor/bin/pint --dirty
```

- [ ] **Step 4: Run static analysis on changed PHP files**

```bash
php vendor/bin/phpstan analyse --no-progress app/Models/EventNotificationLog.php app/Services/Notifications app/Jobs/Notifications app/Console/Commands/DispatchAutomatedNotificationsCommand.php
```

- [ ] **Step 5: Perform final implementation self-review**

Ask: “Is there anything overlooked, and is this optimal?” Verify dependencies, all trigger entry points, queue configuration, tenant boundaries, dead code, test proportionality, release behavior for existing active rules, UI truthfulness and developer readability. Record findings before claiming completion.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Tenant/Events/panels/NotificationsPanel.jsx app/Http/Controllers/Tenant/EventNotificationRuleController.php tests
git commit -m "feat(notifications): expose reliable trigger delivery"
```
