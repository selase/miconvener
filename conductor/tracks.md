# Project Tracks

The running record of major tracks, per the SDD protocol in `.agent/rules/01-sdd-protocol.md`.
Completed tracks are never deleted — this file is the history.

Things to know before you read it:

- **There are no per-track plan folders.** Earlier entries used to link to
  `conductor/tracks/<name>/plan.md`; none of those files were ever committed. The links
  were removed rather than left dangling. This file is the only record.
- **Tracks below the "Events domain" heading are the current product.** Everything above it
  is the multi-tenant SaaS starterkit MiConvener is built on (see `manual.md`), largely
  finished before the event platform work began.
- **Completed entries record what was built at the time, not a fresh test run.** For current
  behavior, follow the code and tests linked below. The event domain stores its models in
  the `landlord` database with tenant scoping (`Event` and `BelongsToTenant`); dedicated
  tenant connections remain part of the starterkit. The adopted product is a Laravel,
  Inertia and React monolith with public event pages; the API-first design in
  `miconvener.md` §1–3 remains unbuilt.

---

## [x] Track: Baseline Inventory & Safety Checks

## [x] Track: Landlord Database & Tenancy Registry

## [x] Track: Secrets Abstraction

## [x] Track: Tenant Context Switching

## [x] Track: Tenant Models & Strategy

## [x] Track: Tenant-Aware Queues & Jobs

## [x] Track: Tenant Migrations Orchestration

## [x] Track: Postgres Path

## [x] Track: Storage Foundations

## [x] Track: RBAC Tenant Scoping

## [x] Track: Feature Flags Tenant-Scoped

## [x] Track: Encryption at Rest & Field Encryption

## [x] Track: Operational Telemetry Foundations

## [x] Track: Tests & Quality Gates

## [x] Track: Convert All Tests to Pest

## [x] Track: Implement Subdomain-Based Tenant Routing

## [x] Track: Automated Tenant Provisioning

## [x] Track: Tenant-Level Settings & Branding

## [x] Track: Metered Features & Usage Limits

## [x] Track: Roles, Permissions, Feature Flags & Packages

## [x] Track: Organizational Role Management

## [x] Track: Subscription & Billing Integration

## [x] Track: API & Developer Tokens

## [x] Track: Dashboard Analytics & Reporting

## [x] Track: Onboarding Flows

## [x] Track: Deployment & CI/CD

## [x] Track: Product Page Kit

## [x] Track: Tenant API Keys + LLM Token Usage

## [x] Track: Tenancy Confidence Suite

## [x] Track: Enterprise Lead Capture

## [x] Track: Tenant Health Dashboard

## [x] Track: Premium Asset Generation

## [x] Track: Enterprise Security (2FA)

## [x] Track: Real KMS integration

## [x] Track: Audit Log Export

## [x] Track: Visual Dashboard Polish

## [x] Track: Advanced Team Roles (Multi-Role)

## [x] Track: Custom Domain Support

## [x] Track: Tenant Usage Metering & Billing

<!-- Track: Billing Module using existing user rule to append to conductor/tracks.md instead of deleting -->
## Billing Module (Completed)
- [x] Create Admin\BillingController
- [x] Register Admin Billing Routes
- [x] Create Global Transactions View
- [x] Create Global Subscriptions View
- [x] Update Sidebar with Billing module

## [x] Track: Enhanced Analytics Filtering & Drill-down

## [x] Track: PostgreSQL Migration Audit

## [x] Track: Modern Invoicing Experience

## [x] Track: Tenant Commerce & Finance

## [x] Track: Tenant-Aware Global Search

## [x] Track: Entitlement & Authorization Bridge

## [x] Track: Paystack billing integration (current plan renewals are one-off charges)

The earlier Paystack-subscription implementation has been replaced for MiConvener plans.
`billing:process-renewals` runs daily at 07:00 from `app/Console/Kernel.php` and uses
`RenewalScheduler` to charge only reusable saved authorizations. Other payment methods get
pay links and a seven-day grace period before a move to Free. `SubscriptionRenewalService`
extends from the old period end and records each payment once by Paystack reference;
`SubscriptionProvisioningService` handles initial plan payments. The callback and webhook
both report payments, and `BillingNotifier` deduplicates transactional billing emails.
Paystack subscription webhook handlers remain for legacy webhook event types, but Paystack subscriptions
do not drive new plan renewals. See `tests/Feature/Billing/RenewalSchedulerTest.php`,
`SubscriptionRenewalTest.php`, `PaymentReferenceConsistencyTest.php`, and
`PlatformWebhookRoutingTest.php`. The old `PaystackWebhookTest.php`, billing jobs,
`RequireActiveSubscription` middleware, and simulator named in the original entry are
not present in the current tree.

## [-] Track: Outgoing Webhooks — delivery engine done, no console UI

Built and wired: `WebhookEndpoint` / `WebhookCall` models, `SendWebhookJob`
(`tests/Feature/Jobs/SendWebhookJobTest.php`), and `WebhookEventSubscriber` registered in
`EventServiceProvider`. Missing: any route or page for a tenant to create, edit or inspect
an endpoint — rows can only be created directly in the database. Also unbuilt: delivery-log
replay, dead-letter queue, and the webhook simulator.

## [x] Track: Production Database Backups & Cloudflare R2 Storage

Configured Spatie backup (`config/backup.php`) to target the `landlord` database connection
(PostgreSQL on AWS RDS via Laravel Cloud) rather than the hardcoded `mysql` default, which
previously caused `backup:run` to hang and fail attempting to connect to a local MySQL instance.
Verified on production (`env-a2ae4030-63cb-41a9-8281-36cffb804842`):
- Read, write, list, and delete permissions on the attached Cloudflare R2 bucket (`miconvener-backups`)
  via the S3 driver.
- Execution of `php artisan backup:run --only-db --no-interaction` completed successfully with exit code 0,
  dumping PostgreSQL database `production` via `pg_dump`, creating an 81.8 KB archive, and storing it
  in R2 at `MiConvener/2026-09-10-17-16-54.zip` (83,761 bytes).
- Automated test verification: 2 passing tests in `tests/Feature/Console/BackupConfigTest.php`
  verifying landlord database target, excluding mysql, and s3 disk integration.

## [x] Track: Tenant Team Member Invitation & Dynamic URL Resolution

Updated team member creation (`App\Http\Controllers\Tenant\UserController::store`, `Admin\TeamController::store`,
and `Admin\UsersController::store`) and mailable `SendAccountDetails` to dynamically resolve the tenant login URL
using `$tenant->url('/login')` instead of the previous hardcoded relative `/login` path which failed in email clients.
The URL resolution is fully dynamic per tenant:
- Resolves to `https://{custom_domain}/login` if the tenant has an active custom domain.
- Resolves to the tenant's subdomain (`https://{slug}.miconvener.com/login` or `http://{slug}.localhost/login`).
- Falls back to `route('login')` for global system administrators.
- Included dynamic tenant name in the email body.
- Also updated `ResendAccountPasswordController` to pass dynamic tenant login URL.
Verified by `tests/Feature/Tenant/TeamMemberInvitationTest.php` (2 passing tests, 23 assertions) and
`tests/Feature/Tenant/TeamIndexTest.php` / `UserRoleAssignmentTest.php` (4 passing tests).

---

# Events domain (MiConvener)

The event platform itself. Built against `miconvener.md` §4–9 without per-track entries at the
time; recorded here retrospectively from the code and git history, so treat the granularity as
coarser than the starterkit tracks above.

## [x] Track: Event core, ticketing & registration

Events, ticket types, and registrations across seven statuses (`pending_payment`, `confirmed`,
`cancelled`, `checked_in`, `pending_approval`, `rejected`, `waitlisted`), with approval and
waitlist flows, capacity, and the public `/e/{event}` registration and checkout surface.

## [x] Track: Programme, speakers & venue

Sessions with reorder and per-session capacity, a reusable speaker directory, token-scoped
speaker portal (confirm, upload slides), venue rooms and seat assignment, and ICS export
for both the programme and an attendee's personal agenda.

## [x] Track: On-site operations

QR check-in with search and scan, badge tiers and print logging, and event service requests.

## [x] Track: Engagement

Live polls and quizzes with leaderboards and response moderation, a threaded event forum with
votes, reports and bans, and controlled material downloads.

## [x] Track: Communications & reporting

Scheduled event blasts with per-recipient open tracking, and ten report exports
(registrations, check-ins, forum, polls, attendee directory, session attendance,
dietary/accessibility, audit log, certificates, sponsor deliverables).

## [x] Track: Payments & settlement

The highest-risk area, and where the recent hardening commits concentrate. Two settlement
modes per tenant (`platform_default` charges through the platform's own Paystack account;
`own_gateway` uses the tenant's credentials). `LedgerService` is the sole writer of both
the flat `event_ledger_entries` settlement statement and the double-entry
`ledger_transactions` / `ledger_entries` ledger. Finance reads the flat entries for
collected amounts and available balance and the double-entry accounts for its trial
balance. Payout accounts are verified by name enquiry before use. Sending a Paystack
transfer can enter `awaiting_otp`; `finalizePayout` releases that same transfer. After
release, the transfer webhook marks it `paid`. Row locks protect payout balance checks
and sends.
`payouts:reconcile` (scheduled every 15 minutes) chases payouts whose transfer webhook never
arrived, so a lost webhook cannot strand money that has already left the platform.

## [x] Track: Tenant Owner Role Assignment, Currency Localization & Subscription Refund Policy

- **Owner Permissions & Role Assignment on Signup**: Fixed `App\Http\Controllers\Auth\RegisteredUserController` so that newly registered tenant owners are automatically assigned their `tenant_id` and the `Org Superadmin` role scoped to their tenant via `setPermissionsTeamId($tenant->id)`. This prevents 403 Forbidden ("This action is unauthorized") errors when accessing Settings, Team, Roles, and Events console screens after subscribing.
- **Production User State Repair**: Repaired `s.kwawu@yahoo.co.uk` on production (`ugmc` tenant `01a08c68-77d5-70ba-b69b-abcdf2c0023d`), assigning `Org Superadmin` in `model_has_roles` and resetting Spatie permission cache. Verified all 19 permissions active on production.
- **Currency Localization**: Updated subscription checkout (`resources/views/billing/confirm-subscription.blade.php`), `Billing/Index.jsx`, and `Billing/Pricing.jsx` to dynamically use the configured payment currency (`GHS`) instead of hardcoded `$`.
- **Subscription Refund Policy**: Disabled subscription refunds in `RefundController::store` with a 403 response, set `can_refund => false` in `BillingController::index`, and removed the refund action button and confirmation modal from `Billing/Index.jsx`.
- **Verification**: Verified with 96 passing Pest tests (`tests/Feature/Billing/` and `tests/Feature/Auth/RegistrationTest.php`) and clean asset build via Vite.

## [x] Track: Event Hero Image Upload Limit & Modal Validation UX

- **Increased Hero Image Size Limit**: Raised the hero image upload validation limit in `App\Http\Controllers\Tenant\EventController::validateEvent` from 4MB (`max:4096`) to 20MB (`max:20480`), with human-readable error messages specifying 20MB rather than kilobytes.
- **Client-Side File Validation & Instant Feedback**: Enhanced `resources/js/Pages/Tenant/Events/EventFormModal.jsx` to validate file size immediately upon selection (warning users if a selected file exceeds 20MB before form submission), showing clear file requirements (formats, 20MB max, 16:9 ratio) and image preview.
- **Auto-Scroll to Error & Visibility Banners**: Added automatic smooth scrolling to the first failing field when validation fails, along with a top error banner and a bottom alert bar directly above the submit button featuring a quick "Scroll to error" button, ensuring errors are immediately visible without manual scrolling.
- **Verification**: Verified with 233 passing event feature tests and 2 dedicated tests in `tests/Feature/Events/EventHeroUploadTest.php` testing 10MB acceptance and >20MB rejection with custom error messages.

## [x] Track: Media Serving Pipeline & Cloud Storage URL Resolution

- **Unified Media Controller**: Created `App\Http\Controllers\MediaController` to securely stream uploaded files across both local environment (`public` disk) and cloud production (`s3` disk on Cloudflare R2). Includes directory traversal protection, upload prefix whitelisting (`events/`, `speakers/`, `event-sponsors/`, `event-materials/`, `event-forum/`, `tenant/`, `logos/`, `users/`), and edge caching headers (`Cache-Control: public, max-age=31536000, immutable`) for instant Cloudflare CDN delivery.
- **Dynamic Storage URL Resolver**: Added `Helper::storageUrl(?string $path)` in `App\Libraries\Helper`, which normalizes paths and generates accessible `/media/{path}` URLs across subdomain and central environments.
- **Updated Media References**:
  - `App\Http\Controllers\Public\PublicEventController`: `hero_image_url`, speaker `photo_url`, and sponsor `logo_url` now resolve via `Helper::storageUrl()`.
  - `App\Http\Controllers\Tenant\EventController`: `hero_image_url` and speaker `photo_url` updated.
  - `App\Http\Controllers\Tenant\SpeakerController`: `photo_url` updated.
  - `App\Http\Controllers\Tenant\EventSponsorController`: `logo_url` updated.
  - `App\Models\Tenant`: `logoUrl` attribute updated to resolve cleanly on both S3 and local storage.
  - `App\Models\Event`: Added `heroImageUrl()` model helper.
  - `App\Models\EventForumThread` and `EventForumReply`: `attachmentUrl()` updated.
- **Route Registration & Legacy Fallback**: Registered `GET /media/{path}` and a `GET /storage/{path}` fallback route in both `routes/web.php` and `routes/subdomain.php`, ensuring direct media requests and any legacy `/storage/...` requests resolve seamlessly without 404s.
- **Verification**: Verified with 7 passing tests in `tests/Feature/Media/MediaServeTest.php` and 85 passing tests in `tests/Feature/Events/`.

## [x] Track: Commerce gaps vs. `miconvener.md` §5

Fulfilled across the three dedicated commerce tracks below:
1. Promo codes, discounts and complimentary passes with invite-only access codes (`EventPromoCode`, `PromoCodeService`, `PromoCodePanel.jsx`).
2. Multi-point breakout session check-in, real-time room occupancy gauges, Reverb broadcasting, and CPD accreditation reporting (`EventSessionAttendance`, `SessionOccupancyPanel.jsx`).
3. True double-entry general ledger with balanced accounts ($\sum \text{Debits} == \sum \text{Credits}$), payout-schedule reconciliation command (`app:reconcile-payout-schedules`), escrow holdback retention/release, and settlement statement export (`LedgerService`, `EventPayoutSchedule`, `FinancePanel.jsx`). The command exists but is not scheduled in `app/Console/Kernel.php`.

## [x] Track: Executive Pitch Deck & Sales Presentation Route

Interactive, minimalist, and powerful slide deck route (`/deck`, `/pitch`, `/slides`)
selling MiConvener to potential customers and subscribers. Built from the dual perspectives
of a Production Manager and Sales Tech Leader.
Shipped:
- `App\Http\Controllers\Marketing\PitchDeckController` serving `resources/views/marketing/deck.blade.php`.
- Public routes `/deck`, `/pitch`, `/slides` in `routes/web.php` and `routes/subdomain.php`.
- 12 minimalist high-impact slides covering automated notifications/reminders, pre-event email
  comms, offline-first PWA check-in, name tag printing with canvas badge designer, dedicated
  speaker portal for PowerPoint uploads and COI disclosures, food menu preference forms, in-seat
  service requests (water, audio/mic assistance, AC) with SLA triage, during-event lunch entitlement
  scanning, live quizzes with animated projector leaderboards, automated certificates of
  participation, and 10 enterprise exports.
- The deck is a marketing artifact. Its feature claims, including offline PWA scanning and
  thermal printer integration, are not evidence that those capabilities shipped.
- Interactive ROI & Cost Calculator widget with real-time sliders on Slide 11.
- Presenter Talking Points drawer (`P` key) with tailored scripts for every slide.
- Slide sorter overview grid (`O` key), keyboard navigation (`←`/`→`, `Space`, `J`/`K`, `F`, `T`),
  hash-linking (`#slide-N`), and print/PDF export stylesheet (`@media print`).
- Excluded SMS and WhatsApp messaging per instructions, and avoided "military grade" phrasing.
Verified by:
- Automated Pest feature test `tests/Feature/Marketing/PitchDeckTest.php` (3 passing tests, 22 assertions).
- Full marketing test suite `tests/Feature/Marketing/` (14 passing tests, 67 assertions).
- Clean code formatting via `vendor/bin/pint --dirty`.

## [x] Track: Tenant-Configurable Registration Forms, Conditional Fields & Dynamic Pricing

- **Mandatory Core & Configurable Fields**: Title, First Name, Last Name, and Email required on all registrations; Phone, Dietary Requirements, and Accessibility Needs configurable per event (required/optional/hidden).
- **Arbitrary Custom Form Fields**: Tenants can create unlimited custom fields with arbitrary labels, types (radio, select, text, textarea, checkbox, number), and options. Pre-built with options editor, price overrides, price additions, and sorting.
- **Conditional Visibility & Dynamic Pricing Engine**: Conditional visibility rules (show field B only when field A matches option X) and option-based pricing calculations evaluated server-side by `RegistrationPricingService`, preventing ghost pricing or inactive options from affecting the total.
- **Tenant Management UI**: `RegistrationFormPanel.jsx` in event console (`/events/{event}`) under dedicated "Form" tab with requirement toggles, interactive custom field builder, modal configuration, and live reactive preview.
- **Public Form & Reactivity**: Public registration form on `/e/{slug}` with Title, First Name, Last Name, Email, configurable requirements, dynamic custom fields, reactive condition evaluation in React state, and real-time pricing breakdown.
- **Reporting & Exports**: Added Title, First Name, Last Name, and dynamic custom form answers as exportable columns in registration CSV exports via `EventReportController`.
- **Database Migrations**: Landlord migrations `2026_09_10_213000_add_name_parts_and_form_answers_to_event_registrations_table.php`, `2026_09_10_213001_add_registration_settings_to_events_table.php`, and `2026_09_10_213002_create_event_form_fields_table.php`.
- **Verification**:
  - `tests/Feature/Events/EventCustomFormFieldTest.php`: 6 passing tests, 51 assertions.
  - `tests/Feature/Events/EventRegistrationTest.php`: 10 passing tests, 53 assertions.
  - `tests/Feature/Events/EventReportTest.php`: 11 passing tests, 27 assertions.
  - Full suite verified: 32 tests, 143 assertions passing.
  - Frontend compiled cleanly with `npm run build`.
  - Code formatted with `vendor/bin/pint --dirty`.

## [x] Track: Promo Codes, Discounts & Complimentary Tickets

- **Promo Codes & Usage Caps**: Support for fixed amount, percentage, and 100% complimentary pass coupon codes, with start/expiration dates, total redemption caps, per-attendee email limits, and ticket type constraints. Evaluated server-side by `PromoCodeService`.
- **Complimentary Passes**: Full 100% discount zeroes out ticket cost, marks attendee registration as confirmed immediately, generates ticket QR codes, and bypasses the payment gateway.
- **Invite-Only Ticket Types & Access Codes**: Ticket types can be designated with an `access_code` and hidden from the standard public event page until unlocked via `/e/{event}/unlock-tickets`.
- **Tenant Management UI**: `PromoCodePanel.jsx` added to Event Console under the "Promos" tab with code generator, active/inactive toggles, usage limits, ticket type eligibility picker, and invite-only ticket summary.
- **Public Checkout Reactivity**: Public registration panel on `/e/{event}` features collapsible access code unlocker, real-time promo code validation endpoint (`/e/{event}/validate-promo`), and dynamic price summary breakdown showing original price, discounts, and final total payable.
- **Verification**: Verified by `tests/Feature/Events/EventPromoCodeTest.php` (6 passing tests, 31 assertions) covering CRUD, toggle, percentage and fixed discount calculations, complimentary zero-out, cap exhaustion, per-attendee limits, and access code unlocking. Clean build via `npm run build` and formatting via `vendor/bin/pint --dirty`.

## [x] Track: Breakout Session & Workshop Attendance Tracking (Live Room Headcount)

- **Multi-Point Room Check-In & Headcount Engine**: Landlord migration `event_session_attendances` tracking check-in and check-out timestamps, dwell times in minutes, CPD/CME contact hours, scanning staff, and device names.
- **Room Capacity Guard & Override**: Real-time room capacity check blocks entry with clear 422 alert when maximum room capacity is reached, while allowing authorized staff to toggle an override if needed.
- **Live Broadcasting via Laravel Reverb**: `SessionAttendanceUpdated` broadcasts on private Reverb channel `event.{eventId}.sessions` as attendees enter and exit rooms, enabling instantaneous live updates without manual page reloads.
- **Command Center Live Room Occupancy Grid**: `SessionOccupancyPanel.jsx` added to Event Console under the "Occupancy" tab. Features live room cards, headcount progress gauges (green, amber at 85%, red when full), live room roster drawer with dwell times, and instant scanner launch.
- **Multi-Point Room Scanner Integration**: `CheckInPanel.jsx` upgraded with a "Breakout Room" scanning mode, room/session picker dropdown, Scan IN (Entry) vs. Scan OUT (Exit) directions, and live room capacity ticker.
- **CPD/CME Accreditation Export**: Upgraded `exportSessionAttendance` in `EventReportController` to export comprehensive session attendance reports with check-in and check-out timestamps, dwell times in minutes, and calculated CPD/CME contact hours.
- **Verification**: Verified by `tests/Feature/Events/EventSessionAttendanceTest.php` (5 passing tests, 32 assertions) covering occupancy API, room scanning, capacity blocking and override, check-out dwell time calculation, Reverb broadcast dispatch, and CPD CSV export. Clean frontend build via `npm run build` and formatting via `vendor/bin/pint --dirty`.

## [x] Track: Double-Entry Accounting Ledger & Payout Schedules

- **True Double-Entry General Ledger**: Landlord tables `ledger_accounts`, `ledger_transactions`, `ledger_entries`, and standard chart-of-accounts (`1010` Payment Gateway Clearing, `1020` Cash/Bank, `2010` Organizer Payable, `2020` Holdback Reserve Escrow, `4010` Platform Commission Revenue, `5010` Payment Gateway Processing Fees).
- **Strict Equilibrium Invariant**: `LedgerService` checks that every transaction is balanced ($\sum \text{Debits} == \sum \text{Credits}$) before posting it. It throws `InvalidArgumentException` if unbalanced, and repeated tenant/reference/type combinations return the existing transaction.
- **Wired Revenue & Refund Lifecycles**: Integrated into Paystack payment settlement webhooks (`SettlementWebhookController`), ticket cancellation & refunds (`EventRegistrationController`), and payout disbursements (`EventFinanceController`).
- **Payout Schedule & Escrow Command**: When invoked, `app:reconcile-payout-schedules` processes event payout schedules (`immediate` T+0, `post_event` T+N days, or `manual`), retains and releases holdback reserves, and creates scheduled payout records when thresholds are met. It does not send the transfer, and `app/Console/Kernel.php` does not currently schedule this command. The separately scheduled `payouts:reconcile` checks already-sent transfers.
- **Host Console Finance UI**: Enhanced `FinancePanel.jsx` with a real-time Double-Entry General Ledger card showing equilibrium status ("Equilibrium Balanced" green pill), account debit/credit breakdown, Payout Schedule & Holdback Policy overview, and a configuration modal to customize schedules, days, holdback percentages, and preferred payout accounts.
- **General Ledger CSV Export**: Upgraded `exportSettlementStatement` to append the complete double-entry general ledger trial balance below the line-item reconciliation.
- **Verification**: Verified with 6 passing tests in `tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php` (43 assertions) and 28 passing tests across the entire financial suite. Clean asset build via `npm run build` and formatting via `vendor/bin/pint --dirty`.

## [x] Track: Academic & Scientific Core (Abstracts, Peer Review & Scientific Programme)

- **Granular RBAC Permissions**: Added Spatie permissions (`create abstract`, `read abstract`, `update abstract`, `delete abstract`, `review abstract`, `decide abstract`, `assign abstract-reviewer`, `manage scientific-programme`) to `PermissionsSeeder` and assigned to Superadmin, Org Superadmin, and Org Admin in `RolePermissions.php`.
- **Database Migrations & Models**: Landlord tables `event_abstracts`, `event_abstract_authors`, `event_abstract_reviews`, and `abstract_id` foreign keys on `event_sessions` and `event_session_speakers`. Eloquent models `EventAbstract`, `EventAbstractAuthor`, `EventAbstractReview`, with structured JSON casting, collision-resistant code generation (`ABS-XXXX`), and average score calculations.
- **Public Submission Portal & Tracking**: Public route `/e/{event}/abstracts/submit` with multi-author affiliation builder, track categorization, structured abstract inputs (Background, Methods, Results, Conclusion), presentation preference (Oral / Poster / Either), conflict of interest declaration, and manuscript file upload. Authors track their submission status, committee notes, and scheduled presentations at `/e/{event}/abstracts/{code}`.
- **Peer Review & Rubric Scoring**: Peer reviewer assignment engine with `pending` and `completed` status transitions, structured 4-point rubric scoring (1 to 5 scale on Novelty, Methodology, Relevance, Clarity), recommendation (`accept_oral`, `accept_poster`, `reject`), author feedback, and confidential committee notes.
- **Scientific Decision Engine**: Decision workflow (`accepted_oral`, `accepted_poster`, `rejected`), committee feedback notes, and automated decision letter emails (`AbstractDecisionNotificationMail`). Supports single and bulk decisions.
- **Scientific Programme Expansion**: Supported scientific session types (`keynote`, `plenary`, `panel`, `workshop`, `oral_presentation`, `poster_session`, `simulation_skills`, `breakout`, `networking`, `session`), speaker role associations (`speaker`, `moderator`, `panelist`, `keynote_speaker`, `chair`, `oral_presenter`, `discussant`), and direct linkage to accepted abstracts.
- **Digital Programme & Abstract Book**: `AbstractBookService` and `EventReportController::exportAbstractBook` compiling all accepted abstracts and sessions into an index-linked, downloadable conference Abstract Book PDF (`pdf.abstract-book`).
- **Host Console UI**: `AbstractsPanel.jsx` added to Event Console with metrics cards, status and track filters, search, reviewer assignment modal, evaluation viewer drawer, decision modal, and PDF abstract book download button.
- **Verification Evidence**:
  - `tests/Feature/Events/EventAbstractSubmissionTest.php`: 3 passing tests, 14 assertions.
  - `tests/Feature/Events/EventAbstractReviewAndDecisionTest.php`: 5 passing tests, 29 assertions.
  - `tests/Feature/Events/ScientificProgrammeAndAbstractBookTest.php`: 2 passing tests, 10 assertions.
  - Full feature suite: 268 passing tests, 1,015 assertions across all event test files.
  - Clean frontend asset build via `npm run build` and formatting via `vendor/bin/pint --dirty`.

## [x] Track: Accreditation & Conference Operations (Certificates & 8-Pillar PM)

- **Granular RBAC Permissions**: Added Spatie permissions (`create event-operation`, `read event-operation`, `update event-operation`, `delete event-operation`, `manage operation-pillars`, `create certificate`, `read certificate`, `update certificate`, `delete certificate`, `issue certificates`) to `PermissionsSeeder` and `RolePermissions.php`.
- **Landlord Migrations & Models**: Landlord tables `event_operation_pillars`, `event_operation_tasks`, `event_certificate_templates`, `event_certificates`. Eloquent models `EventOperationPillar`, `EventOperationTask`, `EventCertificateTemplate`, `EventCertificate` with full relationship graphs, UUID keys, and auto-generation for verification tokens (`MC-XXXXXXXX`).
- **8-Pillar Project Management Engine**: Pre-provisions 8 default academic operational pillars (`Programme & Speakers`, `Technology & Registration`, `Finance & Procurement`, `Sponsorship & Exhibition`, `Communications & Media`, `Venue & Logistics`, `Protocol & Hospitality`, `Post-Conference Reporting`), while allowing conference hosts to create new custom pillars on the fly, color-code, rename, and reorder them.
- **Task & Budget Tracking**: Tasks with owner assignment from team members, deadlines, priority tags, status progression (`not_started`, `in_progress`, `blocked`, `done`), completion timestamps, task dependencies, and budget allocation vs. actual spend roll-up.
- **Multi-Role Electronic Certificates**: Configurable templates for Delegates, Speakers, Presenters, and Volunteers. Dynamic body text with placeholders (`{name}`, `{event_name}`, `{date}`, `{hours}`, `{role}`), accredited CPD/CME contact hours, and issuer signatures.
- **Issuance & Vector PDF Engine**: `CertificatePdfService` generating landscape vector PDFs with embedded cryptographic verification QR codes and codes. Bulk issuance actions for checked-in attendees, confirmed delegates, speakers, and presenters.
- **Public Credential Verification**: High-trust public QR verification route `/verify/cert/{uuid}` and PDF download `/verify/cert/{uuid}/download` with PII masking, institutional authentication, and revocation checking.
- **Host Console Frontend**: `OperationsPanel.jsx` (with Kanban board & List views, budget variance tracker, custom pillar builder) and `CertificatesPanel.jsx` (role template editor, issuance modal, issued certificate roster) integrated into `Show.jsx`.
- **Verification Evidence**:
  - `tests/Feature/Events/EventOperationsAndPillarsTest.php`: 4 passing tests, 32 assertions.
  - `tests/Feature/Events/EventMultiRoleCertificatesTest.php`: 6 passing tests, 57 assertions.
  - Phase 2 total: 10 passing tests, 89 assertions.
  - Clean asset build via `npm run build` and formatting via `vendor/bin/pint --dirty`.

## [x] Track: Participant Intelligence & In-Event Dynamic Forms (Engine & Stratification)

- **Granular RBAC Permissions**: Added Spatie permissions (`create dynamic-form`, `read dynamic-form`, `update dynamic-form`, `delete dynamic-form`, `manage participant-groups`) to `PermissionsSeeder` and registered in `RolePermissions.php` for Superadmin, Org Superadmin, and Org Admin.
- **Landlord Database Migrations & Models**: Landlord tables `event_dynamic_forms`, `event_dynamic_form_submissions`, `event_participant_groups`, and `event_participant_group_members`. Eloquent models `EventDynamicForm`, `EventDynamicFormSubmission`, `EventParticipantGroup`, and `EventParticipantGroupMember` with JSON casts, UUID primary keys, and relationships on `Event.php` (`dynamicForms()`, `participantGroups()`).
- **General-Purpose Dynamic Forms Engine**: Visual question schema editor supporting text, textarea, select, multiselect, radio, date, rating scale (1-5), and boolean fields. Supports active/closed states, submission limits, and access restrictions (all attendees or checked-in delegates only).
- **Public Respondent Portal**: Responsive public form page at `/e/{event}/forms/{slug}` with real-time field validation, star rating widgets, option pickers, attendee verification by ticket code/email, and confirmation state.
- **Participant Stratification & Dynamic Cohort Engine**: `ParticipantStratificationService` evaluating complex multi-parameter criteria (ticket type, registration status, breakout session attendance dwell times, CME hours earned, and dynamic form questionnaire answers). Preserves manual member pinning while automatically re-syncing dynamic memberships.
- **Host Console Frontend**: `FormsPanel.jsx` (form builder, submission viewer, CSV export), `StratificationPanel.jsx` (cohort cards, criteria rule builder, live matching members table, CSV export), and integrated tabs in `Show.jsx`.
- **Verification Evidence**:
  - `tests/Feature/Events/EventDynamicFormsTest.php`: 5 passing tests, 33 assertions.
  - `tests/Feature/Events/EventParticipantStratificationTest.php`: 5 passing tests, 27 assertions.
  - Phase 3 total: 10 passing tests, 60 assertions.
  - Full clean asset build via `npm run build` and formatting via `vendor/bin/pint --dirty`.

## [x] Track: Multi-Channel Automated Notifications & Billing Guardrails

- **Granular RBAC Permissions**: Added Spatie permissions (`create notification-rule`, `read notification-rule`, `update notification-rule`, `delete notification-rule`, `manage notification-settings`) to `PermissionsSeeder` and assigned to Superadmin, Org Superadmin, and Org Admin in `RolePermissions.php`.
- **Landlord Database Migrations & Models**: Landlord tables `event_notification_rules`, `event_notification_logs`, `tenant_notification_settings`. Eloquent models `EventNotificationRule`, `EventNotificationLog`, and `TenantNotificationSetting` with UUID primary keys, JSON casts, and relations on `Event.php` (`notificationRules()`, `notificationLogs()`).
- **Multi-Channel Notification Gateway**: `NotificationGatewayService` supporting direct Email delivery via responsive Mailable `AutomatedNotificationMail`, and multi-channel staging for SMS and WhatsApp formatted ready for plug-and-play Omnichannel API integration.
- **Automated Rule Engine & Command**: `AutomatedNotificationDispatcher` and Artisan command `app:dispatch-automated-notifications` calculate timing offsets (e.g. 7 days before event, 2 hours after event), resolve attendee and cohort recipients, and interpolate placeholders (`{name}`, `{event_name}`, `{date}`, `{time}`, `{venue}`, `{ticket_code}`, `{ticket_url}`). The command is registered in `app/Console/Kernel.php` on the fifteen-minute wake window (see the track below); before 2026-09-21 it was not, so time-based dispatch never ran on its own.
- **Quota Billing & Anti-Abuse Guardrails**: `TenantNotificationSetting` defaults to a 2,500-email monthly setting and a 60-minute recipient cooldown, with overage control. Package `email_credits` limits are separately defined in `EventPackageSeeder`; 2,500 is not a universal plan allowance. Billing emails sent through `BillingNotifier` are transactional and do not use these credits.
- **Delivery Scope**: Email is delivered by `NotificationGatewayService`. SMS and WhatsApp are staged as records for a future gateway; they are not sent or billed as delivered messages.
- **Host Console UI**: `NotificationsPanel.jsx` added to the Event Console with monthly quota progress bar, channel statuses, 1-click preset campaign deployment (7-day reminder, day-of digital pass, post-event CME feedback, speaker slide deadline), custom rule builder, live test dispatch, and channel configuration modal.
- **Verification Evidence**:
  - `tests/Feature/Events/EventAutomatedNotificationsTest.php`: 7 passing tests, 51 assertions.
  - Phase 2, 3, 4 comprehensive suite: 27 passing tests, 200 assertions.
  - Full clean asset build via `npm run build` and formatting via `vendor/bin/pint --dirty`.

## [x] Track: Current billing, fee and live-event protection

- Plan and token-pack payments use GHS and the Paystack reference as the idempotency key across callback, webhook, provisioning and `transactions.provider_transaction_id` (`PaymentReferenceConsistencyTest.php`). Billing pages, checkouts and invoices require `manage billing`; the payment callback remains open to record money already taken (`BillingAccessTest.php`).
- Ticket money uses integer pesewas. `PlatformFeeResolver` / `FeeCalculator` apply event → tenant → package → config terms, including a stored zero-percent waiver. Only the global Superadmin gate or `events:set-platform-fee` sets percentages and caps; tenant settings may choose the fee bearer (`PlatformFeeSettingsTest.php`, `PackageDefaultFeeTest.php`).
- `LedgerService` posts both ledgers once per reference. Gateway fees are booked at sale; transfer fees are passed through only when a transfer is released. OTP transfers stay parked for `finalizePayout` instead of being sent again (`LedgerIdempotencyTest.php`, `GatewayFeeLedgerTest.php`, `TransferFeePassThroughTest.php`, `PayoutOtpFlowTest.php`).
- A downgrade or plan lapse grandfathers already-published, not-yet-ended events and locks commercial terms that would worsen. `Tenant::handlesTicketMoney()` keeps finance and refunds available for money already collected (`LapsedPlanEventsTest.php`).
- `BillingNotifier` sends deduplicated transactional receipts, payment failures and plan-ending emails to the payer and tenant contact address (`BillingEmailsTest.php`).

## [x] Track: Scheduled conference reminders actually fire

Organisers could build scheduled-offset notification rules in the event console, but
`app:dispatch-automated-notifications` was never registered with the scheduler, so a rule only
ever ran when a superadmin triggered it by hand from the operational commands screen. Every
reminder a tenant configured silently never sent.

Registering the command alone would have been an incident. `EventNotificationRule::isDue()`
tested `$target->isPast()`, which is true forever, and re-armed every 12 hours, so the first
scheduled run would have mailed the attendee list of every still-published past event, and again
twice a day after that. Proven by test before the fix.

- `app/Console/Kernel.php`: `everyFifteenMinutes()->withoutOverlapping()`, sharing the existing
  wake window with the health checks and `payouts:reconcile` rather than causing its own, and
  fine-grained enough for rules whose offset is set in minutes.
- `EventNotificationRule::isDue()`: a rule is due only if it has never dispatched and its target
  passed within `CATCH_UP_HOURS = 24`. A scheduler gap of a few hours still delivers; a stale or
  long-finished reminder never does. Existing rows with a long-past target and a null
  `last_dispatched_at` fall outside the window, so no backfill was needed.
- `EventNotificationRule::booted()`: changing `trigger_type`, `offset_direction`, `offset_amount`
  or `offset_unit` clears `last_dispatched_at`, so rescheduling a fired rule arms it again while
  editing its wording does not.
- `last_dispatched_at` is now the sole one-shot guard. It is absent from the validation rules in
  `EventNotificationRuleController`, which writes only validated keys, so a tenant cannot reset it.
- `DispatchAutomatedNotificationsCommand` now selects the due-candidate rules in one query with
  their event eager-loaded, instead of walking every published event and asking each for its rules
  while `isDue()` lazy-loaded the event back. Scanning ten events fell from 21 queries to 2, and no
  longer grows with the number of events on the platform -- which matters at 96 runs a day across
  every tenant. Removed two now-obsolete `phpstan-baseline.neon` entries that the rewrite fixed.
- Console: the rule card carries a `Sent` pill and the note "sends once. Reschedule it, or use Send
  Now.", so fire-once is visible rather than something an organiser discovers by waiting. The
  `Active`/`Paused` pill still reports `is_active` on its own, because a sent rule is still active
  for rescheduling.

- **Verification Evidence**:
  - `tests/Unit/Console/ScheduledUsageResetTest.php`: scheduler registration.
  - `tests/Feature/Events/EventAutomatedNotificationsTest.php`: a sent reminder does not send
    again; a reminder missed by a short outage still sends while a 36-hour-old one does not;
    rescheduling re-arms and renaming does not; the scan holds at 2 queries for ten events; the
    rules payload exposes the two fields the console reads.
  - Full suite passing; Pint and PHPStan clean; `npm run build` clean.

### Follow-up: the reminder mail could not render at all

A controlled end-to-end proof on production (a throwaway event in `miconvener-probe`, one
registration, one rule) showed the scheduler firing correctly at 18:00:19 and then failing to
deliver: `EventNotificationLog` recorded `status=failed`, `No hint path defined for [mail]`.

`AutomatedNotificationMail::content()` passed `view:` where all 23 other mailables pass
`markdown:`, while its template opens with `<x-mail::message>` like the rest. `view:` never
registers the `mail::` namespace, so the component could not resolve and the view threw. This was
the only unsendable mail in the codebase; the one remaining mailable that does not use `markdown:`
(`SendAccountDetails`) uses the older `build()` + `$this->markdown()` form, which is fine.

Every notification test used `Mail::fake()`, which records a mailable without rendering it, so a
view that could not render at all passed CI indefinitely. The new test renders it for real and
reproduced the production error verbatim before the fix.

The lesson worth keeping: faked mail proves a message was queued, not that anyone could receive
it. A mailable wants at least one test that actually renders it.

### Follow-up: rendering every mailable found a second one

Acting on that lesson, `tests/Feature/Mail/MailableRenderTest.php` now builds and renders all 24
mailables in one pass, collecting every failure rather than stopping at the first, with a
reflection guard asserting each mailable on disk has a render case so a new one cannot inherit
the blind spot. Twenty-two rendered. One was the already-fixed `AutomatedNotificationMail`. One
was new.

`EventBlastMail` could not render on a worker: the tracking pixel calls
`route("public.blasts.open", ...)`, which needs the `subdomain` parameter, and nothing on the
queue supplied it. `ResolveTenant` sets `URL::defaults(["subdomain" => ...])` but only for an HTTP
request. The global `Queue::before` hook in `AppServiceProvider::configureQueue()` restored the
tenant, its permissions team, its database connection and its storage disk -- everything except
the URL default. `TenantAwareJob` does set it, and only `DispatchNotificationRuleJob` uses that
middleware; `SendEventBlastJob` declares none.

The existing blast tests call `(new SendEventBlastJob($blast))->handle()` directly under
`Mail::fake()`, bypassing both the queue hook and the rendering, so nothing saw it. Production has
never sent a blast (`blasts=0, recipients=0, failed_jobs=0`), so this was latent rather than
manifest: the first organiser to send one would have hit it.

Fixed in the global hook rather than on the one job, so it covers every queued job that renders a
tenant URL rather than only this one. `tests/Feature/Queue/TenantAwareQueueUrlTest.php` drives the
real path -- no URL default set, dispatched through the sync queue with the array mailer, so the
template is genuinely built.

## [x] Track: Conference materials actually download

`MaterialDownloadController` checked a confirmed registration, the release date and the
per-registration limit, recorded the attempt, and then redirected to
`Storage::disk('s3')->url(...)` -- an unsigned URL on R2's private endpoint, which answers
`HTTP 400`. Every download in production would have failed, and each failure spent one of the
attendee's attempts. Found on 2026-09-22 while designing the attendee portal; production had three
materials (all Tech Summit 2026, `nkabom-events`, limit 3 each) and no attempts yet, with the first
scheduled to release that afternoon.

- Streamed through the app with `Storage::disk()->response()`, as `MediaController` already does,
  so it works against the private bucket and there is no permanent URL to outlive the release
  date, the registration or the limit. Disk from `Event::uploadDisk()`.
- An attempt is recorded only once the file is found; a missing file is a 404 that costs nothing.
- Served inline under the material's title with its extension, so a PDF opens in a phone browser.
  `/` and `\` are stripped from the name, since `Content-Disposition` refuses them and speaker decks
  are titled `{speaker} — Slides`.
- `Cache-Control: private, no-store`.

The existing test asserted `assertRedirect()`, which proved the code handed off rather than that
anyone received the file -- locally the redirect landed on a working `/storage` URL, in production
on R2's 400, and the test could not tell them apart. It now asserts the bytes arrive.

- **Verification Evidence**: `tests/Feature/Events/EventMaterialTest.php` (bytes delivered within
  the limit; inline PDF named after the material; a missing file spends no attempt; a speaker-style
  title with an em dash and a slash still downloads -- confirmed to fail without the sanitiser).
  `MediaRouteBypassTest` still passes. Suite 1084/1084.

## [ ] Track: Platform Attendee Portal (Central Identity & Lifecycle Command Center)

Replacing the single-tenant `/my` portal with a single platform-wide passwordless attendee portal at `https://miconvener.com/my` across all organisers on the platform.

- [x] **Stage 1: Platform Host Boundary & Routing**: `TenantHostMatcher` recognizes base domain and `www`; `GuardAttendeePortalHost` handles 302 redirects for `{tenant}.miconvener.com/my` to `miconvener.com/my?organiser={tenant}` and `www` to `miconvener.com/my`, aborts 404 on invalid hosts, and marks platform requests to bypass `ResolveTenant` (ignoring session `active_tenant_id` and `X-Tenant` header). Verified with 47 passing tests across `AttendeePortalHostTest.php`, `PlatformAttendeePortalHostTest.php`, `AttendeePortalRouteSecurityTest.php`, and `TenantHostMatcherTest.php`.
- [x] **Stage 2: Platform Access Codes & Verification Infrastructure**: Landlord table `platform_attendee_access_codes` (no `tenant_id`), `PlatformAttendeeVerification` service with constant-time dummy BCrypt check, 12-hour session marker `attendee_verified_platform`, independent rate limiters (60s cooldown, 5/hr email, 10/24h email, 20/10min IP burst, 100/24h IP), Turnstile challenge step-up on 6th send from an IP in 10 minutes, and transactional platform mailable (zero tenant credits deducted). Verified with 14 passing tests across `PlatformAttendeeAccessCodePruningTest.php` and `PlatformAttendeeVerificationTest.php`.
- [x] **Stage 3: Central `/my` Verification Shell**: `PlatformAttendeeAccessController`, updated `VerifyPrompt.jsx` (60s cooldown, Turnstile challenge handling, status-specific error copy, "Use a different email address", 5-try explanation), honest empty state on `MyPortal.jsx`, and Form Requests. Verified with 6 passing tests in `PlatformAttendeeAccessControllerTest.php` and clean Vite production build.
- [x] **Stage 4: Cross-Tenant History Service**: `PlatformAttendeeHistory` service querying across all landlord event registrations, certificates, abstracts, and attendance without `TenantScope`, grouping into `needs_attention`, `live_now`, `upcoming`, and `past` with deterministic ordering, pending transfer discovery, and banned tenant isolation. JSON endpoints `/my/events`, `/my/certificates`, `/my/abstracts`, `/my/attendance` gated by `EnsurePlatformAttendeeVerified`. Verified with 5 passing tests in `PlatformAttendeeHistoryTest.php` (35 assertions).
- [x] **Stage 5: Central Event Workspace & Checkout Grant**: Central workspace `/my/events/{registration}` using `PlatformAttendeeWorkspaceAuthorizer` (30-minute `checkout_grant` session for Paystack returns without creating platform-wide email proof), bounded status polling (2s initial, 5s interval, 2-minute cap) for asynchronous webhook fulfillment, PDF ticket download via `TicketPdfService`, atomic ticket credential rotation on `EventRegistration` (`rotateTicketCredentials` with HMAC salt), service requests gated to running events or checked-in attendees, and material download streaming. Verified with 10 passing tests in `PlatformAttendeeWorkspaceTest.php` (53 assertions).
- [x] **Stage 6: Dashboard UI (Action-First Lifecycle Hierarchy)**: Action-first attendee dashboard on `/my` with lifecycle urgency hierarchy: `NeedsAttentionSection` (unpaid tickets, pending transfers with action buttons), `LiveNowSection` (pulsing status, checked-in badges, seat assignments, quick actions), `OrganiserEventsSection` (grouped by organiser, chronological upcoming events, de-emphasized collapsible past events, materials badges), filter by organiser support (`?organiser={slug}` banner with clear filter button), loading skeleton (`HistorySkeleton`), error recovery with retry, and honest empty state. Verified with 3 passing feature tests in `PlatformAttendeeDashboardTest.php` (54 assertions), 71 total passing platform and portal tests (380 assertions), clean Vite production build (2.38s), and 0 PHPStan errors.
- [ ] **Stage 7: Responsive Navigation & Offline PWA**: Overview/Ticket/My Day/More navigation, web app manifest scoped to `/my/`, service worker for shell precaching, opt-in "Save ticket offline" snapshot in browser storage, 7-day post-event expiry.
- **Stage 8: Contextual Actions**: Polls (with opaque derived tokens), Q&A, dynamic forms, durable service requests.
- **Stage 9: Record Panels**: Certificates, abstracts, and attendance.
- **Stage 10: Material Release Policy**: Unified `MaterialReleasePolicy` and organiser console controls.
- **Stage 11: Cleanup & Security Audit**: Deprecate legacy tenant verification tables, static analysis, Pint, full Pest suite.


