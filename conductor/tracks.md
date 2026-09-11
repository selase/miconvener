# Project Tracks

The running record of major tracks, per the SDD protocol in `.agent/rules/01-sdd-protocol.md`.
Completed tracks are never deleted — this file is the history.

Two things to know before you read it:

- **There are no per-track plan folders.** Earlier entries used to link to
  `conductor/tracks/<name>/plan.md`; none of those files were ever committed. The links
  were removed rather than left dangling. This file is the only record.
- **Tracks below the "Events domain" heading are the current product.** Everything above it
  is the multi-tenant SaaS starterkit MiConvener is built on (see `manual.md`), largely
  finished before the event platform work began.

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

## [x] Track: Paystack Recurring Billing & Dunning

Verified by `tests/Feature/Billing/PaystackWebhookTest.php` (5 passing): signature rejection,
`charge.success` extending `ends_at`, `invoice.payment_failed` sending dunning without
cancelling, and `subscription.disable` reverting the tenant to the `free` package.
Shipped: `VerifyPaystackSignature`, the three `app/Jobs/Billing/Process*` handlers,
the three `app/Mail/Billing/` mailables, `RequireActiveSubscription` middleware, and
`paystack:simulate` (`App\Services\Billing\PaystackSimulatorService`).

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
`own_gateway` uses the tenant's credentials), an append-only `event_ledger_entries` table
(charge / refund / payout) that the finance screen and available-balance check read from,
payout accounts verified by name-enquiry before use, and the payout send → transfer webhook →
`paid` lifecycle with row locking on both the balance check and the send.
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
3. True double-entry general ledger with balanced accounts ($\sum \text{Debits} == \sum \text{Credits}$), automated settlement reconciliation (`app:reconcile-payout-schedules`), escrow holdback retention/release, and settlement statement export (`LedgerService`, `EventPayoutSchedule`, `FinancePanel.jsx`).

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
- **Strict Equilibrium Invariant**: `LedgerService` enforces that every transaction is balanced ($\sum \text{Debits} == \sum \text{Credits}$) at database commit time. Throws `InvalidArgumentException` if unbalanced.
- **Wired Revenue & Refund Lifecycles**: Integrated into Paystack payment settlement webhooks (`SettlementWebhookController`), ticket cancellation & refunds (`EventRegistrationController`), and payout disbursements (`EventFinanceController`).
- **Automated Settlement & Escrow Engine**: Command `app:reconcile-payout-schedules` enforces event payout schedules (`immediate` T+0, `post_event` T+N days, or `manual`), automatically retains holdback reserve buffers (e.g., 10%) during dispute risk periods, automatically releases escrow reserves upon maturity, and schedules automated disbursements when balances cross the minimum threshold.
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

