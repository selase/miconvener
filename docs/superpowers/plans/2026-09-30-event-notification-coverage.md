# Event Notification Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add once-only certificate, cancellation/refund, assignment, invitation, abstract, payout and organiser-approved event/session change emails.

**Architecture:** Focused notifier services create delivery claims through the notification foundation; controllers report completed workflow transitions but do not build recipients, mail content or keys. Event and session edits create mergeable change batches that an organiser explicitly sends or dismisses.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Eloquent, queued jobs, Pest 5, Inertia/React

**Spec:** `docs/superpowers/specs/2026-09-30-event-notification-coverage-design.md`

## Global Constraints

- Requires the completed notification foundation plan.
- Transactional mail bypasses tenant email credits; organiser-authored event/session updates consume credits.
- Notification failures never roll back a completed domain action.
- All dispatches occur after commit and use business-specific dedupe keys.
- No secret, OTP value, complete account number or reusable payment authorization appears in mail or logs.
- Event edits save before communication review; no save automatically blasts attendees.
- Do not add dependencies.

## Review Focus

- A successful Paystack refund must produce one accurate cancellation/refund receipt while an aborted refund sends none; Task 2.
- Assignment `updateOrCreate` and same-owner updates must not resend; Tasks 3 and 4.
- Bulk certificate issuance must use real speaker/presenter addresses and return promptly; Task 1.
- Event change batches must merge edits, remove reverted fields and snapshot recipients before send; Task 7.
- Payout emails must select only authorised tenant recipients and redact financial secrets; Task 6.

---

### Task 1: Correct certificate recipients and send certificate-ready mail

**Files:**
- Create: `app/Mail/Events/EventCertificateIssuedMail.php`
- Create: `app/Services/Notifications/EventTransactionalNotifier.php`
- Modify: `app/Http/Controllers/Tenant/EventCertificateController.php`
- Create: `resources/views/emails/events/certificate-issued.blade.php`
- Test: `tests/Feature/Events/EventCertificateNotificationTest.php`
- Modify: `tests/Feature/Mail/MailableRenderTest.php`

**Interfaces:**
- Produces `EventTransactionalNotifier::certificateIssued(EventCertificate $certificate): bool`.
- Uses key `certificate-issued:{certificate_id}` and a transactional, non-metered delivery claim.

- [ ] Write failing tests for checked-in delegates, real speaker email, `is_presenting` author selection, missing speaker email reporting, duplicate issuance and mailable rendering.
- [ ] Run `php artisan test --compact tests/Feature/Events/EventCertificateNotificationTest.php tests/Feature/Mail/MailableRenderTest.php` and verify RED.
- [ ] Correct recipient collection, wrap issuance in a landlord transaction, collect created certificate models, and notify each only after commit.
- [ ] Implement the branded mail with public verification/download URL, role, event and CPD hours only when enabled.
- [ ] Run the focused tests plus `tests/Feature/Events/EventMultiRoleCertificatesTest.php`; run `vendor/bin/pint --dirty`.
- [ ] Self-review recipient normalization, bulk response latency, missing-address behavior, tenant/event scoping and duplicate keys.
- [ ] Commit with `git commit -m "feat(certificates): email newly issued credentials"` using only Task 1 files.

### Task 2: Send accurate cancellation and refund confirmation

**Files:**
- Create: `app/Mail/Events/EventRegistrationCancelledMail.php`
- Modify: `app/Services/Notifications/EventTransactionalNotifier.php`
- Modify: `app/Http/Controllers/Tenant/EventRegistrationController.php`
- Create: `resources/views/emails/events/registration-cancelled.blade.php`
- Test: `tests/Feature/Events/EventRegistrationCancellationNotificationTest.php`
- Modify: `tests/Feature/Mail/MailableRenderTest.php`

**Interfaces:**
- Produces `registrationCancelled(EventRegistration $registration, bool $refunded, ?int $refundAmount): bool`.
- Uses key `registration-cancelled:{registration_id}`.

- [ ] Write failing tests for free cancellation, successful paid refund, already-reversed refund, failed-refund abort and repeated cancellation.
- [ ] Verify RED with the focused test.
- [ ] Return a typed refund outcome from the existing refund helper without weakening ledger invariants; call the notifier only after cancellation commits.
- [ ] Render amount in integer minor units through the existing finance formatting pattern.
- [ ] Run cancellation, refund-ledger and mailable tests; format.
- [ ] Self-review that mail never claims a refund that did not happen and no sensitive provider response is exposed.
- [ ] Commit `feat(registration): confirm cancellations and refunds`.

### Task 3: Notify task assignment and reassignment

**Files:**
- Create: `app/Mail/Events/EventTaskAssignedMail.php`
- Create: `app/Services/Notifications/EventOperationsNotifier.php`
- Modify: `app/Http/Controllers/Tenant/EventOperationController.php`
- Create: `resources/views/emails/events/task-assigned.blade.php`
- Modify: `tests/Feature/Events/EventOperationsAndPillarsTest.php`
- Modify: `tests/Feature/Mail/MailableRenderTest.php`

**Interfaces:**
- Produces `EventOperationsNotifier::taskAssigned(EventOperationTask $task, ?int $previousOwnerId): bool`.
- Uses key `task-assigned:{task_id}:{owner_id}`.

- [ ] Write failing tests for create-with-owner, unowned task, same-owner edit, reassignment and owner removal.
- [ ] Verify RED.
- [ ] Capture previous owner before update and notify only a newly assigned owner after persistence.
- [ ] Include named console route, event, pillar, priority and due date.
- [ ] Run task and mailable tests; format.
- [ ] Self-review authorization-derived URLs, null due date, deleted owner and no mail for routine edits/status changes.
- [ ] Commit `feat(operations): notify task assignees`.

### Task 4: Notify reviewers and invite speakers

**Files:**
- Create: `app/Mail/Events/AbstractReviewerAssignedMail.php`
- Create: `app/Mail/Events/EventSpeakerInvitedMail.php`
- Modify: `app/Services/Notifications/EventOperationsNotifier.php`
- Modify: `app/Http/Controllers/Tenant/EventAbstractController.php`
- Modify: `app/Http/Controllers/Tenant/EventSpeakerController.php`
- Modify: `routes/subdomain.php`
- Create: `resources/views/emails/events/reviewer-assigned.blade.php`
- Create: `resources/views/emails/events/speaker-invited.blade.php`
- Modify: `tests/Feature/Events/EventAbstractReviewAndDecisionTest.php`
- Create: `tests/Feature/Events/EventSpeakerInvitationTest.php`
- Modify: `tests/Feature/Mail/MailableRenderTest.php`

**Interfaces:**
- Produces `reviewerAssigned(EventAbstractReview $review): bool`, key `reviewer-assigned:{review_id}`.
- Produces `speakerInvited(EventSpeaker $eventSpeaker, int $invitationVersion): bool`, key `speaker-invited:{event_speaker_id}:{version}`.
- Adds authorised named route `tenant.events.speakers.resend-invitation`.

- [ ] Write failing assignment, repeated-update, missing-email, first-invite and explicit-resend tests.
- [ ] Verify RED.
- [ ] Detect whether `updateOrCreate` actually created the review; notify only then.
- [ ] Retrieve the event-speaker pivot after `syncWithoutDetaching`; notify only on initial attach.
- [ ] Add invitation-version storage with an additive migration and implement explicit resend that rotates/increments the version without changing the portal token unnecessarily.
- [ ] Run reviewer, speaker, route, permission and render tests; format.
- [ ] Self-review tenant ownership, portal token exposure only to the intended email, and repeated button safety.
- [ ] Commit `feat(events): notify reviewers and invited speakers`.

### Task 5: Send abstract receipts and migrate decision mail

**Files:**
- Create: `app/Mail/Events/AbstractSubmissionReceivedMail.php`
- Modify: `app/Services/Notifications/EventTransactionalNotifier.php`
- Modify: `app/Http/Controllers/Public/AbstractSubmissionController.php`
- Modify: `app/Http/Controllers/Tenant/EventAbstractController.php`
- Create: `resources/views/emails/events/abstract-submitted.blade.php`
- Modify: `tests/Feature/Events/EventAbstractSubmissionTest.php`
- Modify: `tests/Feature/Events/EventAbstractReviewAndDecisionTest.php`
- Modify: `tests/Feature/Mail/MailableRenderTest.php`

**Interfaces:**
- Produces `abstractSubmitted(EventAbstract $abstract): bool`, key `abstract-submitted:{abstract_id}`.
- Produces `abstractDecisionRecorded(EventAbstract $abstract): bool`, key `abstract-decision:{abstract_id}:{decided_at}`.

- [ ] Write failing corresponding-author/fallback receipt and duplicate-decision tests.
- [ ] Verify RED.
- [ ] Notify after submission transaction commits and move existing direct decision send into the once-only notifier.
- [ ] Preserve `notify_author=false` as an explicit no-send decision.
- [ ] Run abstract and render suites; format.
- [ ] Self-review tracking URL, decision version key and no duplicate from bulk/single decision paths.
- [ ] Commit `feat(abstracts): send submission and decision mail reliably`.

### Task 6: Notify authorised owners about payout state

**Files:**
- Create: `app/Mail/Events/EventPayoutStatusMail.php`
- Modify: `app/Services/Notifications/EventTransactionalNotifier.php`
- Modify: `app/Http/Controllers/Tenant/EventFinanceController.php`
- Modify: `app/Console/Commands/Events/ReconcileStuckPayouts.php`
- Create: `resources/views/emails/events/payout-status.blade.php`
- Create: `tests/Feature/Events/EventPayoutNotificationTest.php`
- Modify: `tests/Feature/Mail/MailableRenderTest.php`

**Interfaces:**
- Produces `payoutStateChanged(EventPayout $payout, string $transition): int`.
- Uses key `payout-state:{payout_id}:{transition}:{recipient_user_id}`.

- [ ] Write failing awaiting-OTP, failed, paid, permission-filter and redaction tests.
- [ ] Verify RED.
- [ ] Resolve recipients from tenant users who can manage payouts/billing; dedupe normalized email.
- [ ] Call notifier after each durable transition, including reconciliation, without exposing OTP/provider secrets.
- [ ] Run payout, finance invariant and render suites; format.
- [ ] Self-review all payout writers with `rg`, transition accuracy and financial redaction.
- [ ] Commit `feat(finance): notify owners about payout state`.

### Task 7: Build event change review and send

**Files:**
- Create: `database/migrations/landlord/2026_09_30_120000_create_event_change_batches_table.php`
- Create: `app/Models/EventChangeBatch.php`
- Create: `app/Services/Events/EventChangeDetector.php`
- Create: `app/Services/Notifications/EventChangeNotifier.php`
- Create: `app/Http/Controllers/Tenant/EventChangeBatchController.php`
- Create: `app/Http/Requests/Tenant/SendEventChangeBatchRequest.php`
- Create: `app/Mail/Events/EventDetailsChangedMail.php`
- Create: `resources/views/emails/events/details-changed.blade.php`
- Modify: `app/Http/Controllers/Tenant/EventController.php`
- Modify: `routes/subdomain.php`
- Modify: `resources/js/Pages/Tenant/Events/EventFormModal.jsx`
- Modify: `resources/js/Pages/Tenant/Events/Show.jsx`
- Create: `tests/Feature/Events/EventChangeNotificationTest.php`

**Interfaces:**
- Produces `EventChangeDetector::record(Event $event, array $original, array $changes, User $actor): ?EventChangeBatch`.
- Produces show/send/dismiss endpoints scoped through the event relationship.
- Normal changes snapshot confirmed/checked-in registrations; cancellation additionally includes pending/waitlisted and excludes rejected/cancelled.

- [ ] Write failing material/immaterial, merge, revert, draft, empty-audience, audience-status, dismiss, double-send and cancellation-warning tests.
- [ ] Verify RED.
- [ ] Implement migration/model and pure change normalization/diff service.
- [ ] Record/merge batches after successful event update without coupling save success to notification delivery.
- [ ] Implement authorised review/send/dismiss endpoints and once-only per-recipient claims.
- [ ] Add console review UI with exact old/new values, count, editable message and unresolved cancellation warning.
- [ ] Run event update, notification, authorization and frontend builds; format.
- [ ] Self-review timezone normalization, sensitive virtual links, recipient snapshot timing, double clicks and reverted changes.
- [ ] Commit `feat(events): review and send attendee change notices`.

### Task 8: Build session change review and complete verification

**Files:**
- Extend: `app/Models/EventChangeBatch.php`
- Extend: `app/Services/Events/EventChangeDetector.php`
- Modify: `app/Http/Controllers/Tenant/EventSessionController.php`
- Modify: `resources/js/Pages/Tenant/Events/SchedulePanel.jsx`
- Create: `app/Mail/Events/EventSessionChangedMail.php`
- Create: `resources/views/emails/events/session-changed.blade.php`
- Create: `tests/Feature/Events/EventSessionChangeNotificationTest.php`

**Interfaces:**
- Session batches use source type/session IDs and recipient union of assigned speakers plus saved-agenda registrations.
- Reorder produces one batch containing all changed sessions.

- [ ] Write failing speaker/agenda union, dedupe, create/update/delete, reorder consolidation and draft-event tests.
- [ ] Verify RED.
- [ ] Extend batch/detector and wire explicit session mutation boundaries.
- [ ] Add review UI reuse and session mail.
- [ ] Run all notification/event suites, `npm run build`, `vendor/bin/pint --dirty`, and PHPStan over changed PHP.
- [ ] Perform final implementation self-review covering all spec requirements, every writer/route, permissions, navigation, migrations, queues, rendering, dead code and proportional tests.
- [ ] Commit `feat(events): review and send session change notices`.
