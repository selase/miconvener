# CSV attendee imports and event-specific hosts implementation plan

> For agentic workers, use superpowers:executing-plans after owner approval. Follow the tasks in order, write failing regression tests first, and tick steps in the same commit as the work.

**Status** — Host slice implemented locally for independent review. CSV import work remains a separate slice. Merge, deployment, production imports and messages remain unapproved.

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

- [x] Write failing tests for a CSV preview requiring `full_name,email`; allow optional `phone,ticket_type,external_reference,payment_status,external_amount,currency` through explicit column mapping. Return line numbers, row errors and a downloadable template. Default payment status is `free`; require currency for external amounts, parse decimals strictly into minor units and reject negatives or unsupported precision.
- [x] Run the new tests and verify the intended failures.
- [x] Implement UTF-8/BOM CSV parsing with quoted commas, CRLF, blank-line handling and fixed limits of 5 MiB, 2,000 rows, 20 columns and bounded cell lengths. Reject binary files, malformed records and ambiguous/duplicate headers. Treat all cell content as text, never spreadsheet expressions. Escape formula-like values in any CSV error/result export.
- [x] Resolve ticket labels against this event only; require an explicit mapping when ambiguous. Preview may operate on draft events. Confirmation rejects cancelled/ended events and changed or deleted ticket types. Existing private-event access rules remain authoritative.
- [x] Persist a private preview bound to tenant, event, actor, file digest and 24-hour expiry. Freeze canonical accepted data server-side. Confirmation accepts row identifiers/choices only, never client-supplied financial fields or recipient lists.
- [x] Classify exact in-file duplicates and existing event registrations using scoped external reference, then normalized email. Never match by name or phone alone. Existing cancelled/rejected registrations, shared emails and contradictory references are conflicts. First release supports skip or correction followed by a fresh preview, not overwrite/reactivation.
- [x] Test cross-tenant/event access, permissions, expiry, malformed files, duplicates and private responses. Run focused tests, Pint and commit.

## Task 2 — Confirmation, admission, retries and financial separation

- [x] Write failing tests for repeated confirmation, concurrent imports, import versus public registration at the final available place, cancellation, external-payment reporting and missing identity proof.
- [x] Run tests, then implement confirmation through a durable batch with a unique submission key. Freeze selected valid rows and policy options. Use bounded queued chunks with an honest progress/result screen; no request waits for thousands of registrations.
- [x] Recheck permission, tenant/event state, capacity and plan allowances for each chunk. Audit the existing registration lock order first and use one compatible order across public registration, imports and relevant ticket writers. Persist each row result in the same transaction as its registration. Concurrent retries must return the original result without consuming another place or issuing another credential.
- [x] Preserve earlier completed rows if a later row fails. Present created/skipped/failed counts and the specific rows to correct; retries operate only on unfinished rows. Never roll back successfully created attendees as an implicit batch undo.
- [x] Issue one native ticket/QR per accepted admitted attendee through the existing model behavior, without marking the email verified. Confirmed admission and verified ownership remain separate. Exercise signed access links, /my discovery and email-code access to demonstrate no identity shortcut.
- [x] Store imported amounts only in typed external provenance. Keep native amount/charge fields from creating a payable or financial receipt. Audit event revenue, exports, admission, cancellation, refunds, provider callbacks and ticket resend so external records are labelled correctly and cannot enter platform money paths.
- [x] Preserve normal after-commit webhooks exactly once per created state transition, existing event counts and anonymised Going totals. Imports never create attendance/check-in records or publish attendee profiles.
- [ ] Run focused import, admission, finance and real PostgreSQL contention tests, Pint and commit. Include existing free/paid/offline/member-benefit registration regressions appropriate to changed shared code.

## Task 3 — Explicit invitations and import management UI

- [x] Write failing endpoint/component tests for the Guests → Import attendees entry point, upload/mapping, preview, errors, confirmation totals, resumable progress and results.
- [x] Build with existing console controls. Display payment separation, capacity limits and unresolved duplicates before confirmation. Show “No invitations sent” until the organiser explicitly requests delivery.
- [x] Implement a durable transactional invitation outbox with a stable batch/registration identity and bounded workers. Reuse existing access-verification mail and ticket mail patterns after inspecting their behavior for unverified imported registrations. Dispatch failure must retain retryable work; ambiguous transport outcomes require the established reconciliation policy. Keep delivery status visible to staff, without secrets in logs.
- [x] Test zero mail before explicit confirmation, recipient isolation, authorization on retry, failed queue dispatch recovery, duplicate submission and safe resend. Marketing subscriptions, SMS credits and public-profile consent remain independent.
- [x] Expire previews after 24 hours and purge uploaded source files after parsing. Retain minimal import provenance, row outcomes and necessary financial history; remove transient parsed/error-row personal data after 30 days. Use tenant-aware private storage and existing deletion policies.
- [ ] Test retention, errors, empty states, keyboard access, dark mode and narrow layout. Rebuild and commit public/build with frontend changes; run focused tests, Pint and commit.


## Task 4 — Event-specific hosts and multiple-host presentation

- [x] Write failing tests for tenant fallback, one host, multiple ordered hosts, individual hosts, scoped edit permissions and foreign host/logo references.
- [x] Add ordered EventHost records and a tenant-scoped relationship. Preserve all existing event/tenant identities. Use explicit replace-list updates in one transaction with a stale-update guard so simultaneous edits cannot silently overwrite one another.
- [x] Add Overview host editing with name/type/logo/link, reordering and removal. Apply existing safe image-upload rules, bounded names and HTTPS URL validation. Do not fetch arbitrary website URLs on the server. Validate image ownership and safely retire replaced uploads according to existing storage rules.
- [x] Add a public `hosts` DTO and use it in the event byline and host section. Preserve the tenant's About/Follow, contact details and existing social links under managing-organisation identity. Host links do not automatically become tenant social accounts or imply endorsement/verification.
- [x] Audit event listings, structured data, calendar export, social metadata, ticket/PDF, certificates and event emails. Public event organiser credits use explicit host data where semantically appropriate; financial seller/remittance identity and existing receipt snapshots retain their authoritative source. State exactly which surfaces change in the review report.
- [x] Add component and HTTP tests for names, order, fallback, long text, missing logos, unsafe links, locked/private event payloads and unchanged tenant permissions/financial identity. Rebuild assets, run focused tests and Pint, then commit.

## Task 5 — Integrated acceptance, review and release proposal

- [ ] Use an isolated local organisation and synthetic CSV. Create a draft event with two hosts, import attendees with free and externally paid labels, repeat the submission and invite only an explicitly selected synthetic recipient through logged mail.
- [ ] Verify attendee email proof, ticket download, first/duplicate QR scan, programme, polls, Q&A, surveys, badges/certificates and normal edit/cancel behavior. Demonstrate no extra attendance, Community membership or public-profile consent. Keep any unrelated missing feature clearly identified rather than claiming it passed.
- [ ] Verify finance reports and both ledgers before/after import. Prove the external declaration creates no platform monetary posting, payout availability or platform financial receipt. Inspect cancellation/refund controls and external references for data leaks.
- [ ] Perform desktop, 390px, keyboard and dark-mode acceptance on real rendered data. Validate actual viewport dimensions; document a blocked browser check honestly. Include an actual-phone QR test when the owner is available.
- [ ] Conduct one thorough self-review after implementation. Review Focus includes admission locks, shared emails, retry recovery, permission revocation, mail durability, financial segregation, public-host identity, private payloads and every changed UI surface. Record omissions and resolve blockers.
- [ ] Update existing relevant documentation only, run the affected regression suites and a final CI gate, and submit a release proposal. Owner approval of this plan permits implementation only; merge, deployment, live imports and outbound live invitations require their applicable approval.

## Review outcome and limitations

This plan deliberately keeps the two changes independently reviewable, with the host work able to ship without the importer. Imported attendees get existing event capabilities according to their normal verification, ticket and feature settings. Source-system payments are declarations, not gateway evidence. Existing public host copy can be overridden without transferring tenant access or seller responsibilities.

The independent host slice is implemented locally. A real CSV is helpful during acceptance to validate mapping expectations, but synthetic data is sufficient to implement the approved contract. Before using a real file, confirm the organisation is authorised to upload and contact those attendees and agree the selected rows and invitation action explicitly.

## Second review amendments — 8 November 2026

These clarifications govern the tasks above and must be included in their tests.

### Admission, identity and locking

- Import writes require the existing `update event` permission and event staff scope, not merely access to Guests. Preview/results also require appropriate event read/update access; queued work rechecks the initiating actor's current authority. External monetary declarations additionally require the existing finance-reading permission, with private fields excluded from non-finance DTOs and exports.
- There is a concrete lock-order hazard to resolve before implementation. Public registration takes an event lock, while verification and member-benefit lifecycle paths take a registration lock before the event lock. Do not introduce an event-then-existing-registration import path. New-row admission locks the event and rechecks duplicate/capacity state; existing registrations are skipped without mutation. Test confirmation against verification, cancellation and competing public admission. Broader lock consolidation requires a separately justified change rather than an incidental refactor.
- Decide admission independently of public sales windows. Staff may import externally accepted attendees to a draft or published event even if public registration is closed, provided the event has not ended/cancelled and capacities, limits, event scope and restricted-access rules pass. Explicit staff import confirmation records that the organiser has accepted these attendees; never mimic a public payment or silently bypass membership checks.
- Do not call the public registration endpoint once per row. Reuse `EventRegistration::issueTicket()` for new credentials, without weakening the existing public verification flow. Test the imported registration through access invitation, verified /my access, ticket transfer, ticket regeneration and duplicate scans. Existing uploaded QR/ticket values are never accepted as platform credentials.
- Custom registration answers are not required to recreate a person's external admission. Preserve names/email and supported mapped fields, and explicitly show that remaining profile answers were not supplied. Do not fabricate required answers, dietary information or privacy/marketing consent. Later attendee edits use the existing validation. Optional phone values use the application's international normalization rules and remain strings so leading zeros survive.
- Duplicate identity is event-scoped, including recurring events. Never merge different occurrence records implicitly. Normalize email with the existing platform rule. A source namespace plus external reference identifies a row within this event; conflicting reference/email pairs require correction. Changes to names or ticket mapping on a retry cannot create a second admission.

### Durable batches and communications

- A stopped, expired or unauthorised worker leaves a resumable result with a clear staff message. Workers must not use the actor's browser session or manufacture email proofs. Define which batch states are retryable and reject changed selections under a reused operation key.
- Invitation permission does not imply mailing every address in an uploaded file. Freeze the selected registration IDs, event identity and recipient addresses for each send operation. Staff see imported, queued, sent, failed and uncertain counts separately. Explain that transport ambiguity can duplicate an email even when the registration and ticket are created only once. A hard guarantee of exactly-once SMTP delivery is outside the contract.
- Imports emit the normal existing registration webhooks, which may notify connected systems even when invitation mail is off. Show that consequence in the confirmation summary and audit the import batch/source without disclosing private QR credentials or raw upload contents.
- Preview and result exports omit private ticket credentials and sensitive attendee fields not needed to resolve row errors. Audit retention cleanup as well as ordinary event/tenant purge; financial provenance follows the applicable retained-history rules rather than the transient 30-day preview policy.

### Host credits and scope control

- Ship host presentation and CSV imports as separate PRs, starting with host presentation because it is independently useful and avoids importing attendee data while its identity presentation is unsettled. Task numbering remains a dependency guide within each slice, not a requirement to mix both into one release.
- The first host slice changes the public event byline and host section only. Audit other surfaces, but keep expansion into ticket/PDF, certificates, calendar and social-card rendering as explicit reviewed follow-ups unless their current text becomes misleading. Financial receipts keep the legal seller. Do not let a broad audit become an unbounded redesign.
- Label tenant About/Follow controls with the managing organisation so following Purpledot cannot be mistaken for following a named individual host. Logo removal and returning to tenant fallback must be reversible through the editor; host data must respect draft/private-event visibility.

### Owner choices still needed

The limits and restrictions in this plan are recommendations awaiting approval, including mandatory attendee email, handling shared family emails, membership-restricted events, and historical-payment visibility. No real CSV, real invitation send or production data change is authorised by this document. Confirm the final CSV mapping against a representative header/sample before implementation acceptance; personal attendee data is not needed for planning.

## Owner clarification — mixed registration and collection channels

An event can combine attendees imported from another registration system with new registrations and supported collections through MiConvener. Importing attendees must not disable native registration, paid ticket sales or existing event contribution/collection features. Native registration remains subject to the event's normal dates, capacities, ticket settings and financial readiness.

- Staff see an explicit “Paid elsewhere” label for imported historical payments, together with the declared original amount/currency when supplied. Buyer ticket/status views must describe external payment accurately without presenting a MiConvener payment receipt or implying that MiConvener verified or collected that money. Imported free and complimentary admission remain distinguishable.
- Preserve the native checkout, gateway verification, commercial terms, receipts, refund and settlement paths for new attendees who register and pay through MiConvener. Existing imported attendees consume capacity, so native checkout and import confirmation share the same authoritative availability checks.
- Registration source and payment source are independent facts. A later supported MiConvener contribution or other collection from an imported attendee creates its own normal payment record and receipt. It must not overwrite their “Paid elsewhere” admission provenance, mark the external payment as platform-collected or charge the admission price again automatically.
- Use existing event contribution/Giving capabilities only where already supported and enabled. A new generic balance-collection or extra-charge workflow is outside this import release and needs its own design if requested. Never reinterpret imported external amounts as unpaid balances or automatically request payment from imported attendees.
- Reports show total registered attendees across sources, with native receipts/collections and externally declared payments clearly separated by source and currency. Do not add externally collected money to platform revenue, payout availability or gateway-reconciled totals. Cancellation/refund handling uses the source of each actual payment independently.
- Preview/confirmation must not silently reopen registration or alter ticket prices, sales windows, settlement settings or collection flags. Organisers can use the existing event controls to configure future MiConvener registration and collections.

Add acceptance tests for a single event with imported paid-elsewhere attendees, a new native paid registration and an existing supported contribution from an imported attendee. Verify shared capacity, duplicate registration protection, independent receipts, retained external provenance, separate financial totals and source-correct refund controls. Invitations and public host credits remain independent of payment collection.

## Host slice self-review — 8 November 2026

### Touchpoints updated

- AGENTS.md: documented presentation-only host identity, versioned updates and logo/private-page rules.
- Plan-owned SDD ledger: implementation decisions and verification evidence recorded. No personal memory files changed.
- Navigation: editor is embedded in the existing event Overview. No new permission, role, seeder, dependency, config or environment setting is needed; existing `update event` and event-staff scope apply server-side.
- Tests: HTTP coverage includes fallback, ordering, individual/organisation types, HTTPS validation, record/logo ownership, stale versions, atomic replacement, ten-host bounds, staff scope, private/draft visibility, logo retention/removal and permanent purge. Components cover editing/reordering/removal, errors, reader state, long names, missing logos and independent organisation controls.

### UI Gap Analysis

- Discoverability/empty state: Overview → Public hosts; the empty list explicitly describes organisation fallback and offers Add host to editors.
- Permission gating: readers see credits only; saves independently require event update permission and staff scope.
- Loading/errors: disabled controls while saving, status/error announcements, explicit stale-edit reload action and network-error recovery retain unsaved inputs.
- Accessibility: labelled native fields, explicit reorder/remove button names and keyboard controls. Responsive wrapping/grid and existing theme tokens are used.
- Browser acceptance limitation: no live Herd browser session was switched to the isolated test database. Actual 390px, dark-mode and keyboard visual acceptance remains a release check; component tests do not establish viewport layout.

### Solved (auto-fixed)

- Kept tenant About, Follow, contacts and social links explicitly attributed to the managing organisation while using event hosts for public byline/host credits.
- Added logo cleanup to permanent event purge, preserving files during reversible soft deletion.
- Replaced an instance-bound tenant relationship constraint that was incorrect under eager loading with a correlated ownership constraint.
- Added bounded eager loading for the shared console event presenter to avoid relying on global automatic relationship loading for listed events.

### Recommended for user decision

- Before release, perform the isolated rendered desktop/390px/dark/keyboard acceptance check (about 20 minutes). Recommend completing it before deployment.
- Ticket/PDF, certificate, calendar and social-card host expansion remains an explicitly reviewed follow-up under the second amendment. No follow-up expansion is required to preserve current seller/issuer identity.

### Not gaps

- `org` remains the managing organisation; hosts do not alter tenant access, support, payment routing, commissions, legal seller or receipt snapshots.
- Audited surfaces: console event listings retain tenant context; no public organiser listing/structured host data is introduced. Calendar exports contain event/session facts, not an overridden host identity. Social metadata retains managing-site identity and event facts. Ticket/PDF and certificate issuer branding and transactional email sender/support identity remain tenant-based. Only the public byline/host section and their organisation labels change.
- Logo websites are HTTPS links opened with noopener/noreferrer/nofollow, never fetched by the server. SVG and arbitrary logo URLs/paths are not accepted. Removing all hosts restores the tenant fallback; logos can be removed/reuploaded through the same editor.
- No production data, outbound email, push, merge or deployment is part of this slice.


Host verification: 52 affected HTTP tests / 350 assertions passed (`EventHostsTest`, `EventVisibilityTest`, `CommunityPromptsSwitchTest`, `EventWorkspaceTest`, `EventStaffAssignmentTest`); 18 affected frontend tests passed (`EventHosts`, `eventPagePresentation`); full PHPStan passed with 1 GiB limit; Pint, production build and diff whitespace checks passed. Only the dedicated host test database was used for these backend tests. An overlapping verification attempt was discarded and rerun serially after resetting that dedicated schema. A broad frontend attempt was stopped after timeouts outside this slice; no full-suite pass is claimed. Live browser acceptance is being handled independently and is not included in these counts.


## Host independent review and local browser acceptance — 8 November 2026

Independent reviewer found no remaining P1/P2 blockers on `97acba68`. The explicit index host eager load was verified. The earlier N+1 concern was not reproduced because global automatic eager loading already applies; explicit loading removes reliance on that setting.

Root applied only the host migration to the pre-existing local acceptance database `miconvener_acceptance_codex_20261102` (mail logged), separately from the host regression database. Using the existing synthetic staff fixture and synthetic event, the actual Overview editor saved an individual and organisation host and displayed “Hosts saved.” The public byline and host section displayed both; the managing organisation section retained its separate identity. Public layout measured exactly 390px client/scroll width in light and dark themes, and screenshots were visually inspected. Temporary viewport override was reset. The console editor was checked at its actual 1280px width; no 390px console-editor claim is made. Physical-phone and expanded-text acceptance remain unverified. No production data, real attendee, payment or live email was involved.

Evidence lives under the chat visualization directory as `event-hosts-local-acceptance.png`, `event-hosts-mobile-acceptance.png` and `event-hosts-mobile-dark-acceptance.png`.

### Narrow console editor follow-up

A later actual 390px editor check exposed a real overflow hidden by the outer scrolling container: the host section was 693.906px and inputs 617.9px wide even though document scroll width was 390px. The Overview implicit grid track inherited its content minimum, including the native fieldset's `min-width: min-content`. The correction defines a single shrinkable grid column on Overview and sets the host fieldset minimum width to zero; existing full-width inputs need no shared change.

After rebuilding and reloading the same browser tab at an actual 390px viewport, the host section measured 358px at left16, the fieldset 316px with minimum0, the input 282px at left54 and the Overview track358px. The Save hosts button stayed within left37/right143. Document scroll width remained390px. Keyboard Tab moved to host type with a visible focus ring. Root inspected `event-hosts-editor-mobile-focus-fixed.png`; the earlier `event-hosts-editor-mobile-focus.png` records the failing state. Viewport override was reset after verification. This supplements, rather than replaces, the separate public-page light/dark checks above. Physical-phone and expanded-text acceptance remain unverified.

The two affected frontend suites passed18 tests again; production build, Pint and diff whitespace checks passed. No backend, permission, acceptance-data or database changes were made for this layout correction.

## Import local browser acceptance — 8 November 2026

The existing isolated acceptance database and logged-mail configuration were used, separately from automated test databases. A synthetic future published event received a three-row CSV: one externally paid admission declaring GHS 200, one free admission, and an exact duplicate of the first row. The real Guests interface showed two valid rows and one duplicate. Confirmation created two registrations and skipped the duplicate. Reopening the saved preview and confirming it also exercised retained operation-key handling.

Immediately after import, both admissions were confirmed with no email proof or attendance recorded. Their native amount total was zero. The ledger row count remained unchanged at 24, and no invitation existed until the explicit send action. Staff reviewed and selected only the synthetic paid attendee, then queued one invitation. One local worker delivered it to logged mail; no message was sent to a real recipient. Following that logged signed link opened the single-registration ticket with the external-payment label and working Q&A screen. The PDF download was verified as a valid one-page A4 document. Scoped access did not enable offline storage without the separate required proof.

The completed import modal was checked at actual 390px and 1280px viewport widths. Narrow light and dark layouts had no document overflow, and keyboard navigation reached labelled row-selection controls with visible focus. Evidence is saved in the chat visualization directory as `event-import-completed-mobile-light.png`, `event-import-completed-mobile-dark.png` and `event-import-completed-desktop.png`. Temporary viewport overrides were reset.

These checks do not establish a physical-phone QR scan, expanded-text layout, or a browser walkthrough of every existing programme, poll, survey, badge and certificate workflow. Relevant automated regressions remain the evidence for unchanged capabilities. No real CSV, production import, live invitation, payment, merge or deployment was performed.
