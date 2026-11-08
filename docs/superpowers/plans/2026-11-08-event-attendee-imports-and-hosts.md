# CSV attendee imports and event-specific hosts implementation plan

> For agentic workers, use superpowers:executing-plans after owner approval. Follow the tasks in order, write failing regression tests first, and tick steps in the same commit as the work.

**Status** — Prepared for owner review. Implementation, merge, deployment, production imports and messages await approval.

**Goal** — Let an organiser manage an externally registered event through MiConvener and credit one or more public hosts independently of the tenant account.

**Baseline** — Reviewed against main `412f8b3d` on 8 November 2026. Reconcile with current main before implementation.

**Architecture** — Continue using EventRegistration, existing ticket issuance, check-in and attendee verification. Add a bounded, durable import preview/confirmation workflow and ordered event-host records. Public host credits are presentation data; tenant ownership, authorisation and settlement continue through the existing tenant relationships.

**Stack** — Existing Laravel, PostgreSQL, React/Inertia, Tailwind and Pest. Use existing CSV/spreadsheet dependencies where suitable; add no dependency.

## Recommended decisions for approval

1. Import confirmed external attendees only in the first release. Pending payments, cancellations and waitlists are excluded from the template. Membership-restricted events/tickets are refused initially, with a clear explanation, until their existing identity/eligibility requirements can be satisfied through an explicitly designed import flow.
2. Require full name and valid email for each attendee. Optional phone is contact information, not identity proof. Families sharing an email are flagged for review and excluded initially rather than silently merged. Supporting attendees without email is a separate decision.
3. Uploaded addresses remain unverified. Import confirmation admits the attendee to the event; normal verified-email or narrowly scoped ticket access is still required to open private attendee features. Public-profile consent, marketing consent, Community membership and following are never inferred.
4. Default to importing without sending mail. After reviewing results, an authorised organiser can explicitly send a transactional access invitation to selected imported attendees. A confirmation shows recipients and count before dispatch. No automatic SMS.
5. Respect existing event and ticket capacities, plan limits and registration admission rules. Preview predicts availability; confirmation checks again under the appropriate locks. Capacity increases use the existing event controls before retrying.
6. CSV may label payment as `free`, `paid_elsewhere` or `complimentary`. External amounts, when supplied, are reported separately as organiser-declared historical information, with original currency. They create no platform sale, gateway transaction, commission, payout balance or platform payment receipt. Refund/charge actions for external money direct staff to the original provider.
7. Support up to ten ordered public hosts per event. Each has a name, individual/organisation type, optional logo and optional HTTPS website. Public copy uses “Hosted by” with all names. With no configured hosts, retain the existing tenant presentation.
8. Purpledot remains the managing organisation for a Purpledot event. Its support contacts, legal seller identity, access controls, payment routing and financial documents are resolved separately from public host credits. Where relevant, show “Managed by Purpledot” explicitly.

## Existing code and reuse boundaries

- `app/Http/Controllers/Public/PublicEventController.php` owns public registration, verification, public event payloads and event-level locking. Inspect `registerLocked`, `verify` and resend handling before extracting any shared admission checks.
- `app/Models/EventRegistration.php` owns ticket credentials and registration state. Reuse its ticket-issuance behavior without setting `email_verified_at` from a CSV.
- `app/Observers/RegistrationWebhookObserver.php` emits registration events after commit. Imports must use the normal observer and avoid duplicate webhook dispatch on retries.
- `app/Http/Controllers/Tenant/EventRegistrationController.php` handles existing edits, cancellations and payment actions. Imported records must behave correctly in all these paths.
- `app/Services/Community/MemberImport.php` and `MemberImportController` provide preview patterns. Reuse suitable parsing/validation patterns, not Community identity matching or membership side effects.
- `app/Http/Controllers/Public/PublicEventController.php` passes the tenant public profile as `org`. `resources/js/Pages/Public/Events/Show.jsx` displays its name under Hosted by. Keep `org` for tenant identity and introduce an explicit `hosts` payload.
- Existing event workspace lives in `resources/js/Pages/Tenant/Events/Show.jsx`, its panels and `EventFormModal.jsx`; routes live in `routes/subdomain.php`. Place import actions in Guests and host editing in Overview.

## Proposed file responsibilities

Confirm actual sibling naming and current methods when building. Keep business logic outside controllers.

- Migration, EventAttendeeImport model and import-row model — durable owner/event-scoped preview, immutable accepted row data, result IDs, expiry and confirmation state. Registration gets nullable import provenance and an indexed external identity. Keep financial metadata separate from existing platform charge fields.
- `app/Services/Events/AttendeeImport.php` — bounded parsing, validation, duplicate classification, canonical row hashes, confirmation and resumable batch processing.
- `app/Http/Controllers/Tenant/EventAttendeeImportController.php` plus Form Requests — template, preview, confirmation, progress/results and explicit invitations. Private/no-store responses, tenant/event scoping and existing guest-management permission checks on every endpoint and queued continuation.
- Existing registration model/controller and report presenters — imported provenance, external payment labels, safe resend/cancel/edit behavior and segregated reporting.
- EventHost model/migration plus an event relationship — ordered tenant/event-owned presentation records.
- Event host Form Request/controller and public presenter — validated bounded updates and safe host DTOs without changing `org` or financial DTOs.
- Guests import UI and Overview host editor — reuse existing console primitives, errors, progress states and navigation.
- `tests/Feature/Events/EventAttendeeImportTest.php`, import notification/finance tests and `EventHostsTest.php`, with focused component tests for review and multi-host display.

## Task 1 — Import contract, schema and preview

- [ ] Write failing tests for a CSV preview requiring `full_name,email`; allow optional `phone,ticket_type,external_reference,payment_status,external_amount,currency` through explicit column mapping. Return line numbers, row errors and a downloadable template. Default payment status is `free`; require currency for external amounts, parse decimals strictly into minor units and reject negatives or unsupported precision.
- [ ] Run the new tests and verify the intended failures.
- [ ] Implement UTF-8/BOM CSV parsing with quoted commas, CRLF, blank-line handling and fixed limits of 5 MiB, 2,000 rows, 20 columns and bounded cell lengths. Reject binary files, malformed records and ambiguous/duplicate headers. Treat all cell content as text, never spreadsheet expressions. Escape formula-like values in any CSV error/result export.
- [ ] Resolve ticket labels against this event only; require an explicit mapping when ambiguous. Preview may operate on draft events. Confirmation rejects cancelled/ended events and changed or deleted ticket types. Existing private-event access rules remain authoritative.
- [ ] Persist a private preview bound to tenant, event, actor, file digest and 24-hour expiry. Freeze canonical accepted data server-side. Confirmation accepts row identifiers/choices only, never client-supplied financial fields or recipient lists.
- [ ] Classify exact in-file duplicates and existing event registrations using scoped external reference, then normalized email. Never match by name or phone alone. Existing cancelled/rejected registrations, shared emails and contradictory references are conflicts. First release supports skip or correction followed by a fresh preview, not overwrite/reactivation.
- [ ] Test cross-tenant/event access, permissions, expiry, malformed files, duplicates and private responses. Run focused tests, Pint and commit.

## Task 2 — Confirmation, admission, retries and financial separation

- [ ] Write failing tests for repeated confirmation, concurrent imports, import versus public registration at the final available place, cancellation, external-payment reporting and missing identity proof.
- [ ] Run tests, then implement confirmation through a durable batch with a unique submission key. Freeze selected valid rows and policy options. Use bounded queued chunks with an honest progress/result screen; no request waits for thousands of registrations.
- [ ] Recheck permission, tenant/event state, capacity and plan allowances for each chunk. Audit the existing registration lock order first and use one compatible order across public registration, imports and relevant ticket writers. Persist each row result in the same transaction as its registration. Concurrent retries must return the original result without consuming another place or issuing another credential.
- [ ] Preserve earlier completed rows if a later row fails. Present created/skipped/failed counts and the specific rows to correct; retries operate only on unfinished rows. Never roll back successfully created attendees as an implicit batch undo.
- [ ] Issue one native ticket/QR per accepted admitted attendee through the existing model behavior, without marking the email verified. Confirmed admission and verified ownership remain separate. Exercise signed access links, /my discovery and email-code access to demonstrate no identity shortcut.
- [ ] Store imported amounts only in typed external provenance. Keep native amount/charge fields from creating a payable or financial receipt. Audit event revenue, exports, admission, cancellation, refunds, provider callbacks and ticket resend so external records are labelled correctly and cannot enter platform money paths.
- [ ] Preserve normal after-commit webhooks exactly once per created state transition, existing event counts and anonymised Going totals. Imports never create attendance/check-in records or publish attendee profiles.
- [ ] Run focused import, admission, finance and real PostgreSQL contention tests, Pint and commit. Include existing free/paid/offline/member-benefit registration regressions appropriate to changed shared code.

## Task 3 — Explicit invitations and import management UI

- [ ] Write failing endpoint/component tests for the Guests → Import attendees entry point, upload/mapping, preview, errors, confirmation totals, resumable progress and results.
- [ ] Build with existing console controls. Display payment separation, capacity limits and unresolved duplicates before confirmation. Show “No invitations sent” until the organiser explicitly requests delivery.
- [ ] Implement a durable transactional invitation outbox with a stable batch/registration identity and bounded workers. Reuse existing access-verification mail and ticket mail patterns after inspecting their behavior for unverified imported registrations. Dispatch failure must retain retryable work; ambiguous transport outcomes require the established reconciliation policy. Keep delivery status visible to staff, without secrets in logs.
- [ ] Test zero mail before explicit confirmation, recipient isolation, authorization on retry, failed queue dispatch recovery, duplicate submission and safe resend. Marketing subscriptions, SMS credits and public-profile consent remain independent.
- [ ] Expire previews after 24 hours and purge uploaded source files after parsing. Retain minimal import provenance, row outcomes and necessary financial history; remove transient parsed/error-row personal data after 30 days. Use tenant-aware private storage and existing deletion policies.
- [ ] Test retention, errors, empty states, keyboard access, dark mode and narrow layout. Rebuild and commit public/build with frontend changes; run focused tests, Pint and commit.

## Task 4 — Event-specific hosts and multiple-host presentation

- [ ] Write failing tests for tenant fallback, one host, multiple ordered hosts, individual hosts, scoped edit permissions and foreign host/logo references.
- [ ] Add ordered EventHost records and a tenant-scoped relationship. Preserve all existing event/tenant identities. Use explicit replace-list updates in one transaction with a stale-update guard so simultaneous edits cannot silently overwrite one another.
- [ ] Add Overview host editing with name/type/logo/link, reordering and removal. Apply existing safe image-upload rules, bounded names and HTTPS URL validation. Do not fetch arbitrary website URLs on the server. Validate image ownership and safely retire replaced uploads according to existing storage rules.
- [ ] Add a public `hosts` DTO and use it in the event byline and host section. Preserve the tenant's About/Follow, contact details and existing social links under managing-organisation identity. Host links do not automatically become tenant social accounts or imply endorsement/verification.
- [ ] Audit event listings, structured data, calendar export, social metadata, ticket/PDF, certificates and event emails. Public event organiser credits use explicit host data where semantically appropriate; financial seller/remittance identity and existing receipt snapshots retain their authoritative source. State exactly which surfaces change in the review report.
- [ ] Add component and HTTP tests for names, order, fallback, long text, missing logos, unsafe links, locked/private event payloads and unchanged tenant permissions/financial identity. Rebuild assets, run focused tests and Pint, then commit.

## Task 5 — Integrated acceptance, review and release proposal

- [ ] Use an isolated local organisation and synthetic CSV. Create a draft event with two hosts, import attendees with free and externally paid labels, repeat the submission and invite only an explicitly selected synthetic recipient through logged mail.
- [ ] Verify attendee email proof, ticket download, first/duplicate QR scan, programme, polls, Q&A, surveys, badges/certificates and normal edit/cancel behavior. Demonstrate no extra attendance, Community membership or public-profile consent. Keep any unrelated missing feature clearly identified rather than claiming it passed.
- [ ] Verify finance reports and both ledgers before/after import. Prove the external declaration creates no platform monetary posting, payout availability or platform financial receipt. Inspect cancellation/refund controls and external references for data leaks.
- [ ] Perform desktop, 390px, keyboard and dark-mode acceptance on real rendered data. Validate actual viewport dimensions; document a blocked browser check honestly. Include an actual-phone QR test when the owner is available.
- [ ] Conduct one thorough self-review after implementation. Review Focus includes admission locks, shared emails, retry recovery, permission revocation, mail durability, financial segregation, public-host identity, private payloads and every changed UI surface. Record omissions and resolve blockers.
- [ ] Update existing relevant documentation only, run the affected regression suites and a final CI gate, and submit a release proposal. Owner approval of this plan permits implementation only; merge, deployment, live imports and outbound live invitations require their applicable approval.

## Review outcome and limitations

This plan deliberately keeps the two changes independently reviewable, with the host work able to ship without the importer. Imported attendees get existing event capabilities according to their normal verification, ticket and feature settings. Source-system payments are declarations, not gateway evidence. Existing public host copy can be overridden without transferring tenant access or seller responsibilities.

No implementation has started. A real CSV is helpful during acceptance to validate mapping expectations, but synthetic data is sufficient to implement the approved contract. Before using a real file, confirm the organisation is authorised to upload and contact those attendees and agree the selected rows and invitation action explicitly.
