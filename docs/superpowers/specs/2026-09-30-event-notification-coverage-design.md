# Event Notification Coverage — design

**Date:** 2026-09-30

**Status:** Approved architecture; implementation not started

**Depends on:** `2026-09-30-event-notification-foundation-design.md`

## Purpose

Add the missing transactional and operational emails identified by the notification audit, using the foundation's atomic claim and tenant-aware job. The objective is not to email on every mutation. It is to notify the person who must act, or the person whose money, access, schedule or credential materially changed.

## Delivery policy

### Transactional mail

Transactional messages bypass tenant email credits and cannot be disabled:

- certificate issued;
- registration cancelled and, when applicable, refunded;
- abstract submission receipt;
- abstract final decision, migrated from its current direct synchronous send into the once-only pipeline;
- event cancellation;
- critical payout result to authorised billing/payout owners.

### Operational mail

Operational messages are sent once by default:

- task assigned or reassigned;
- abstract reviewer assigned;
- speaker invited to an event and given a portal link;
- payout requires OTP or failed.

Routine content edits and status movements do not generate mail. A later preference centre may make non-critical operational mail configurable; this project stores stable notification types so preferences can be added without changing producers.

### Organiser-approved attendee communication

Material event/session changes are never blasted merely because a form was saved. MiConvener stores a change batch and asks the organiser to review the diff, audience and message. The organiser can send, dismiss or leave it pending.

## Shared notifier boundary

`EventTransactionalNotifier` and `EventOperationsNotifier` expose typed methods; controllers do not select templates, normalize recipients or create dedupe keys.

Representative interfaces:

```php
certificateIssued(EventCertificate $certificate): bool
registrationCancelled(EventRegistration $registration, ?Transaction $refund): bool
abstractSubmitted(EventAbstract $abstract): bool
taskAssigned(EventOperationTask $task, ?User $previousOwner): bool
reviewerAssigned(EventAbstractReview $review): bool
speakerInvited(EventSpeaker $eventSpeaker): bool
payoutStateChanged(EventPayout $payout, string $transition): int
```

Each method creates delivery claims after commit and returns whether/how many claims were created. Mail content remains in focused Mailable classes using `BrandedForTenant` where applicable.

## Workflows

### Certificate issued

Each newly created certificate sends one message to its real normalized recipient email with event name, role, CPD hours when enabled, verification code and public download URL. Skipped existing certificates do not resend. Bulk issuance queues one job per new certificate and returns immediately.

Before enabling delivery:

- speaker issuance uses `Speaker::email`, never a synthetic `.local` address;
- speakers without email are skipped and reported to the organiser;
- presenter selection uses `is_presenting` and prefers the presenting author;
- duplicate detection uses normalized email, role and event;
- certificate creation and mail scheduling occur transactionally.

### Registration cancellation and refund

The attendee receives one cancellation email after the cancellation commits. Paid registrations include the refund outcome and amount only when the refund was actually initiated/already reversed successfully. A failed refund aborts cancellation under the existing invariant and sends no cancellation confirmation.

### Task assignment

Creating a task with `owner_id` notifies that owner. Changing from owner A to B notifies B and does not send another assignment to A. Updating title, description, budget or status without changing owner sends nothing. Assigning the same owner repeatedly sends nothing. The message includes event, pillar, title, priority, due date and a named console route.

### Reviewer assignment

Creating a new pending review notifies the reviewer with abstract title/code, event and the review-console link. Repeating `updateOrCreate` for the same review does not resend. Removing a reviewer does not email in the first release.

### Speaker invitation

Adding a speaker with an email sends their portal link. An existing event-speaker pivot is not reinvited by `syncWithoutDetaching`. The console gains an explicit “Resend invitation” action whose dedupe key includes an invitation version; generating a portal link alone does not send mail.

### Abstract submission

The corresponding author receives a tracking-code receipt. If no corresponding author is marked, use the first author. The organiser may receive a configurable operational alert in a later iteration; it is not required for initial coverage.

### Payout state

Only users with `manage payouts` or `manage billing` in the tenant are eligible. Recipients are deduplicated by normalized email. Send on:

- transition to `awaiting_otp`;
- transition to `failed`;
- transition to `paid`.

Do not expose full account numbers, provider secrets, authorisation codes or OTP values.

## Event change review

### Material fields

The initial material field set is:

- status changing to cancelled;
- start or end date/time;
- timezone;
- location type;
- physical address;
- virtual link;
- attendee contact email.

Description, artwork, visibility, capacity, ticket configuration, internal finance terms and organiser-only settings do not create a change batch.

### Change batch

When a published event with affected registrations changes a material field, create `EventChangeBatch` containing:

- tenant/event/actor IDs;
- old and new normalized values;
- affected audience snapshot criteria and count;
- status `pending`, `sending`, `sent` or `dismissed`;
- timestamps for sent/dismissed state.

Saving the event succeeds independently of email. The response tells the console that a review is available. Consecutive unsent edits merge into the same pending batch, preserving the earliest old value and latest new value. If a field returns to its original value it drops from the diff; an empty batch is deleted.

The organiser reviews human-readable changes, edits the generated message, confirms recipient count and sends or dismisses it. Sending snapshots recipient registration IDs and creates once-only claims. Registrations created after that snapshot do not receive a retroactive update.

For normal material changes, the affected audience is registrations in `confirmed` or `checked_in` state. Event cancellation also includes `pending_approval` and `waitlisted` registrations because those people still need to know the event will not proceed; `rejected` and `cancelled` registrations are excluded.

Event cancellation uses the same review screen but with stronger warning copy. It is not automatically sent because organisers may need to coordinate refunds or replacement dates first. The console keeps a visible unresolved-cancellation warning until sent or dismissed by an authorised user.

### Session changes

Session create/update/delete calculates a separate session-change batch only when a published event is involved. Initial recipients are assigned speakers plus attendees who saved that session to their agenda. The same review/send behavior applies. Reordering that changes start times is represented as one consolidated batch, not one email per session update.

## Error handling

- A notification failure never rolls back the completed domain action.
- The delivery log exposes failure and retry state.
- Missing recipient email is reported in the action response for bulk certificate/speaker operations.
- A send action is idempotent; refreshing or double-clicking does not create duplicate claims.
- Removing a user, registration or certificate after claims are created does not erase delivery history.

## Testing

Each workflow receives endpoint-level tests that assert recipients and absence of unrelated mail. Required cases include:

- certificate mail only for newly issued certificates;
- real speaker email and presenting-author selection;
- no cancellation mail when refund failure aborts cancellation;
- task assignment/reassignment/no-op update behavior;
- reviewer `updateOrCreate` does not resend;
- speaker invite and explicit resend versioning;
- abstract receipt fallback recipient;
- payout permission recipient filtering and secret redaction;
- material field diff, merge, revert, dismiss and send;
- no batch for drafts, events without recipients or immaterial fields;
- event cancellation unresolved warning;
- session recipient union and deduplication;
- rollback, retry, tenant isolation and mailable rendering.

## Rollout order

1. Certificate correctness and certificate-ready email
2. Cancellation/refund confirmation
3. Task, reviewer and speaker assignment
4. Abstract receipt
5. Payout state mail
6. Event change review/send
7. Session change review/send

Each item is separately releasable and must leave existing delivery behavior intact.

## Non-goals

- Emailing every event edit
- A complete user preference centre
- Sponsor/forum/form notifications in the initial release
- Marketing consent management
- Replacing event blasts
