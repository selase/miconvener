# Event Notification Foundation — design

**Date:** 2026-09-30

**Status:** Approved architecture; implementation not started

**Scope:** Reliable delivery infrastructure and the three event-rule triggers already exposed in the organiser console

## Purpose

MiConvener already sends registration, billing, blast and scheduled-rule emails, but event notification delivery does not have an atomic once-only claim. Its anti-abuse cooldown is scoped only to recipient, event and channel, so one email can incorrectly suppress a different email. The organiser console also accepts `on_registration`, `on_checkin` and `on_materials_uploaded` rules although no domain workflow invokes them.

This project makes delivery retry-safe, preserves truthful delivery logs and email-credit accounting, and connects those three advertised triggers. It does not add the broader set of transactional emails; those are specified separately.

## Existing constraints

- The application is a PostgreSQL-only, single-landlord-database multi-tenant monolith.
- Event communication is tenant-scoped through `BelongsToTenant` and `TenantContext`.
- Email credits apply to organiser-authored event communication, not platform billing/security mail.
- SMS and WhatsApp remain staged, not delivered, until a real gateway exists. Their records must not claim delivery or bill the tenant.
- `NotificationGatewayService` uses `sendNow()` inside a queued rule job so a `sent` log means SMTP accepted the message.
- Queue connections have `after_commit = false`; event jobs must explicitly dispatch after commit.
- Scheduled rules are one-shot, run every fifteen minutes and allow a 24-hour catch-up window.

## Notification classes

The system distinguishes three classes:

1. **Transactional:** required consequences of a user's action or money/credential state. They bypass tenant email credits and cannot be disabled.
2. **Operational:** messages to tenant team members about assigned work. They are once-only and will gain preferences in the coverage project, not this foundation.
3. **Tenant communication:** organiser-authored rules, event updates and blasts. These consume email credits and obey channel settings.

This project handles the third class. It must not silently route transactional mail through a quota that can block it.

## Delivery claim

`event_notification_logs` gains:

- `notification_type` — stable machine name such as `rule.on_registration`.
- `dedupe_key` — business identity of one recipient/channel delivery.
- `source_type` and `source_id` — nullable source reference for diagnosis.
- `attempts` — delivery attempts, initially zero.
- `last_attempted_at` — nullable timestamp.
- `pending` as a valid status.

A PostgreSQL partial unique index covers `(tenant_id, channel, dedupe_key)` where `dedupe_key IS NOT NULL`. Historical rows remain valid with null keys. A claim uses `insertOrIgnore`, following `BillingEmailSender`; catching a unique violation inside a transaction is forbidden because PostgreSQL would abort that transaction.

The dedupe key includes the rule, triggering entity and recipient. Examples:

```text
rule-registration:{rule_id}:{registration_id}:{channel}
rule-checkin:{rule_id}:{registration_id}:{checkin_version}:{channel}
rule-material:{rule_id}:{material_id}:{recipient_identity}:{channel}
rule-scheduled:{rule_id}:{target_timestamp}:{recipient_identity}:{channel}
```

Recipient identities use normalized lowercase email for email, and normalized phone for SMS/WhatsApp. Missing contact information records a skipped result without consuming credit.

## Components

### `NotificationDeliveryClaimService`

Creates the pending log atomically and returns either the claimed log or `null` when that exact delivery already exists. It does not resolve audiences or send messages.

### `SendEventNotificationDeliveryJob`

Receives a log ID, restores tenant context through `TenantAwareJob`, and asks the gateway to deliver that single claimed record. It is explicitly dispatched with `afterCommit()`.

The job:

- loads the pending record under its tenant;
- returns without sending if it is already `sent`, `staged_omnichannel` or `suppressed_quota`;
- increments attempts and timestamps the attempt;
- sends email synchronously inside the worker;
- marks the real result;
- refunds a pre-recorded email credit if SMTP throws;
- throws after recording failure so normal queue retry policy applies;
- never creates a second claim during retry.

### `NotificationGatewayService`

The gateway becomes the channel executor for an already claimed log. It retains quota and channel enforcement, but no longer decides deduplication from recent unrelated logs.

The anti-abuse query is scoped by `rule_id` or `notification_type`, not only event/recipient/channel. Distinct messages to the same person are allowed. Repeated attempts for the same business event are prevented by the unique dedupe key.

### `AutomatedNotificationDispatcher`

Audience resolution is split into bulk and subject-specific paths:

- Scheduled rules retain bulk audience resolution.
- `on_registration` receives one registration and may send only to that registration when it matches the rule audience.
- `on_checkin` receives one registration/check-in occurrence and may send only to that attendee when the rule matches.
- `on_materials_uploaded` receives one material and resolves the configured eligible audience because publication concerns multiple attendees.

The dispatcher renders one immutable payload per recipient, creates claims, and dispatches one delivery job per successful claim. A large campaign therefore does not make one worker hold hundreds of SMTP round trips.

## Trigger integration

Trigger calls are explicit at successful workflow boundaries, not Eloquent observers. Controllers and services know whether an action is meaningful, who caused it and whether its transaction committed.

- Public and organiser registration completion invokes `on_registration` only after the final registration state is known. Payment webhooks must use the same dedupe identity as browser callbacks.
- Host, scanner and attendee self-check-in paths invoke `on_checkin` after a transition into checked-in state. Replaying a scan does not create a new occurrence.
- Material upload invokes `on_materials_uploaded` after the material is persisted. A future scheduled release requires a separate release scheduler and is outside this project; upload rules fire only when the material is immediately available.

Every entry point that can produce the same state uses the same trigger service and key. Controllers must not construct keys independently.

## Failure and concurrency behavior

- Two simultaneous webhooks or scans can create only one claim per recipient/channel.
- A database rollback dispatches no job.
- A worker crash after claiming but before sending leaves a pending/failed record eligible for retry.
- A worker crash after SMTP accepts but before the database update cannot be made exactly-once without provider idempotency. The practical mitigation is a short attempt lock and no automatic retry after an ambiguous transport response; the failure is surfaced for operator review.
- Quota suppression is terminal for that occurrence and visible in the log. Raising the quota does not silently replay old campaigns.
- Rule deletion leaves its logs through the existing nullable foreign key.

## Console behavior

The existing rule editor remains. Trigger descriptions must explicitly state:

- registration and check-in triggers concern the person causing the event;
- material upload can notify the selected audience;
- SMS/WhatsApp are unavailable for real delivery while the gateway remains staged.

Delivery history adds notification type, source, attempts and pending/failed state. No new global preference screen is introduced in this project.

## Testing

Tests must prove:

- atomic duplicate suppression under repeated trigger calls;
- two distinct notification types to the same person are not cooldown-suppressed;
- registration/check-in rules notify only the triggering registration;
- audience filters reject a non-matching triggering registration;
- material rules resolve the intended audience once per material;
- browser callback and webhook paths share a key;
- transaction rollback queues nothing;
- tenant context is restored in the delivery job;
- email credits increment only for successful tenant email attempts and are refunded on failure;
- quota suppression and staged channels remain truthful;
- retries reuse the claim;
- existing scheduled-rule, blast and mailable-render tests remain green.

## Release

The migration is additive. Deploy the schema and code together, then monitor pending/failed counts and queue failures. The three newly wired triggers should be announced because existing active rules will begin working; before deployment, identify active non-scheduled rules and show their tenants exactly what will start firing. No historical trigger is replayed.

## Non-goals

- Real SMS/WhatsApp delivery
- A visual workflow builder
- Replaying historical registration/check-in/material events
- Per-user notification preferences
- New transactional mail types
