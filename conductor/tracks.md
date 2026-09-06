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

## [ ] Track: Commerce gaps vs. `miconvener.md` §5

Not started, and each is called for by the brief: promo codes, discounts and complimentary
tickets; invite-only ticket types with access codes; `payout_schedules` (holdback,
T+N-after-event automatic payouts — payouts are manual/on-demand only today); and a true
double-entry ledger with balanced accounts, the current event ledger being single-sided.
