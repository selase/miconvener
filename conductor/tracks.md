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

## [ ] Track: Commerce gaps vs. `miconvener.md` §5

Not started, and each is called for by the brief: promo codes, discounts and complimentary
tickets; invite-only ticket types with access codes; `payout_schedules` (holdback,
T+N-after-event automatic payouts — payouts are manual/on-demand only today); and a true
double-entry ledger with balanced accounts, the current event ledger being single-sided.

## [ ] Track: Executive Pitch Deck & Sales Presentation Route

Interactive, minimalist, and powerful slide deck route (`/deck`, `/pitch`, `/slides`)
selling MiConvener to potential customers and subscribers. Dual perspective of a
Production Manager (zero gate failure, offline-first PWA check-in, clash-proof programme,
badge printing, in-seat SLA service dispatch) and Sales Tech Leader (conversion, mobile money
and multi-currency payments, dual settlement, double-entry ledger, 65-80% software cost savings).
Includes presenter notes, interactive ROI calculator, slide sorter grid, keyboard shortcuts,
hash linking, and print/PDF formatting.

