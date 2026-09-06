# MiConvener — Standalone Event Platform (API-First) — Build Brief

> **Purpose of this document:** a complete build brief to hand to an LLM (or an engineering team) to design and implement a standalone, headless event management platform exposed entirely through APIs, which other products can integrate with.

> ⚠️ **Scope note — read before planning any work from this document.**
> The API-first architecture in **§1–3** was considered and **deliberately not adopted**. MiConvener
> is being built as a server-rendered multi-tenant Inertia + React monolith. None of the following
> exists or is planned: a versioned `/api/v1` product surface, OAuth2 client-credentials or PKCE
> scopes, the public webhook event catalogue in §3.3, generated SDKs, or the embeddable JS widgets
> in §3.4. Read §1–3, §9's "the management console is a client of the public API", and §5.4's
> `/v1/...` finance endpoints as *aspirational framing only*.
>
> **§4–9 remain authoritative** for the domain itself — entities, registration modes, payments and
> settlement, on-site operations, engagement, communications and exports — and are what the build
> actually follows, delivered through the console and public event pages rather than a public API.
> See `README.md` for the real architecture and `conductor/tracks.md` for current status.

---

## 1. Product positioning

MiConvener is a **standalone, API-first event management platform**. It is not a feature of any single product. It is a service that owns the entire event domain — events, programmes, speakers, registration, payments, settlement, attendance, engagement, and reporting — and exposes that domain over a versioned public API, webhooks, and embeddable widgets.

Consumers of this API include:

- The form builder product (renders registration forms and posts registrations to MiConvener)
- The research intelligence product (conferences, abstract sessions, CPD attendance)
- Health/EMR products (CME/CPD events, clinical training days)
- Education products (PTA meetings, school events, parent sessions)
- Third-party customers integrating directly
- MiConvener' own first-party web app and mobile check-in app

**Design consequence:** no consumer product may reach into the database directly. Every capability must be reachable through the public API. The first-party web app is a client of the same API that external customers use — no privileged private endpoints except platform administration.

### 1.1 Relationship to the form builder

Two integration modes must both be supported:

- **Delegated forms (preferred):** the form builder owns field definitions and rendering; MiConvener stores a `registration_form_ref` and receives submitted answers as a JSON payload validated against a schema it fetched from the form builder.
- **Native forms:** MiConvener has its own minimal question schema (text, select, multi-select, number, date, file, consent/terms with signature) so it works standalone with no form builder present.

Implement this as a `RegistrationFormProvider` interface with `NativeFormProvider` and `ExternalFormProvider` implementations. Never hard-code a dependency on the form builder.

---

## 2. Technical baseline

- **Backend:** Laravel 12 (PHP 8.3+), API-only, no Blade for product surfaces
- **Database:** PostgreSQL 16 with `pgvector` (for forum/summary embeddings and semantic search)
- **Frontend:** React (first-party admin console, attendee portal, check-in PWA) — separate repo/app, consuming the public API only
- **Queues/cache:** Redis; Laravel Horizon
- **Realtime:** Laravel Reverb (WebSockets) with Laravel Echo clients
- **Storage:** S3-compatible object storage with signed URLs
- **Infra:** AWS, provisioned via Laravel Forge
- **Search:** PostgreSQL full-text + pgvector; no external search dependency in v1

---

## 3. API design requirements

### 3.1 Conventions

- Versioned base path: `/api/v1/...`; breaking changes only in a new version
- REST + JSON. Resource-oriented naming, plural nouns
- Cursor pagination on every collection; `limit`, `cursor`, `has_more`, `next_cursor`
- Consistent envelope: `{ "data": ..., "meta": ..., "links": ... }`
- Errors: RFC 7807 problem+json with a stable machine-readable `code`
- All timestamps ISO 8601 with explicit timezone; all money as integer minor units plus ISO 4217 `currency`
- `Idempotency-Key` header required on all POST endpoints that create money movement or registrations; store and replay responses for 24h
- Sparse fieldsets (`?fields=`) and relationship expansion (`?expand=speakers,sessions`) on read endpoints
- `ETag`/`If-None-Match` on heavy read endpoints

### 3.2 Authentication and authorization

Three credential types:

1. **Machine tokens (server-to-server):** OAuth2 client credentials, scoped per organization. Scopes are granular: `events.read`, `events.write`, `registrations.read`, `registrations.write`, `payments.read`, `payouts.read`, `payouts.write`, `checkin.write`, `exports.read`, `engagement.write`, `forum.read`, `forum.write`, `admin.*`
2. **User tokens:** OAuth2 authorization code + PKCE for first-party and partner apps acting on behalf of a human, mapped to RBAC roles
3. **Attendee tokens:** short-lived, single-registration-scoped tokens issued via magic link or QR deep link, granting access only to that attendee's own resources

RBAC roles: `platform_admin`, `org_owner`, `org_admin`, `event_manager`, `finance`, `check_in_staff`, `speaker`, `usher/service_staff`, `attendee`. Enforce with Laravel Policies; every policy has a test.

Rate limiting per token per scope class, with `X-RateLimit-*` headers and `429` + `Retry-After`.

### 3.3 Webhooks

Every meaningful state change emits a webhook so consumer products can react without polling. Requirements: per-endpoint signing secret, HMAC-SHA256 signature header with timestamp, replay protection, exponential backoff retries for 72h, dead-letter queue, delivery log with manual replay from the console, and a webhook simulator in the console.

Minimum event catalogue:

```
event.published            registration.created
event.updated              registration.confirmed
event.cancelled            registration.rejected
session.created            registration.waitlisted
session.updated            registration.cancelled
speaker.confirmed          registration.transferred
programme.published        checkin.completed
payment.succeeded          badge.printed
payment.failed             quiz.session.ended
payment.refunded           poll.response.created
payout.scheduled           material.downloaded
payout.paid                forum.post.created
payout.failed              service_request.created
settlement.reconciled      export.completed
```

### 3.4 Embeddable widgets

Ship a small JS bundle exposing drop-in widgets that authenticate with a publishable key: event page, registration/checkout, attendee ticket + QR, live poll/quiz participant view, and programme/agenda view. Widgets talk to the public API only.

### 3.5 SDKs and docs

- OpenAPI 3.1 spec generated from code and published; keep it as the contract of record
- Auto-generated SDKs: PHP, TypeScript/JS, Python
- Interactive docs (Scalar or Redoc), sandbox environment with seeded test data and PSP test mode
- Postman/Insomnia collection

---

## 4. Domain model

Multi-tenant with `organization` as the tenant root. Choose one isolation strategy and apply it consistently: shared schema with a mandatory `organization_id` and a global query scope, or schema-per-tenant. Whichever is chosen, tenant leakage must be impossible to produce by forgetting a `where` clause — enforce at the model/base-repository level and test it.

### 4.1 Core entities

**Organization / Team**
`organizations`, `organization_members`, `roles`, `permissions`, `api_clients`, `webhook_endpoints`, `audit_logs`

**Event**
`events` — title, slug, description (rich text), cover media, type (in-person / virtual / hybrid), status (draft / published / live / ended / cancelled / archived), start/end datetime, timezone, capacity, visibility (public / private / member-only), custom URL, SEO metadata, tags, series/parent event reference, language(s)

`event_settings` — registration mode, payment config, badge config, check-in config, engagement toggles, download limits, forum toggle, AI feature toggles

`venues`, `rooms` — physical location, address, geo coordinates, capacity, floor/layout reference
`virtual_endpoints` — Zoom/Meet/Teams/custom, join URLs, per-attendee unique links where the provider supports it

**Programme (multi-day, this is a first-class domain, not a text field)**

- `programme_days` — event day N, date, theme, notes
- `sessions` — belongs to a day; title, abstract, type (keynote / plenary / breakout / workshop / panel / poster / break / networking / meal), start/end, room, track, capacity, virtual endpoint, status, parent session (for nested sub-sessions)
- `tracks` — named parallel streams with colour coding
- `session_speakers` — pivot with role (speaker / chair / moderator / panellist / facilitator / discussant) and ordering
- `session_registrations` — optional per-session sign-up when a session has its own capacity (workshops)
- `session_materials` — slides, handouts, papers attached to a specific session
- `session_attendance` — separate from event check-in; scan in/out per session, needed for CPD/CME credit
- `programme_versions` — published snapshots with a changelog, so attendees can be notified of schedule changes and prior versions can be exported

Requirements: drag-and-drop agenda builder over the API (bulk reorder endpoint), clash detection (same speaker or room double-booked), timezone-correct rendering for remote attendees, personal agenda per attendee (`attendee_agenda_items`), and printable/exportable programme (PDF and XLSX).

**Speakers / Faculty**

- `speakers` — name, title, organization, bio (rich text), headshot, social/professional links, contact email, country, disclosure statement (conflict-of-interest, needed for CPD-accredited events), dietary and accessibility needs, travel/accommodation notes, status (invited / confirmed / declined / cancelled)
- `speaker_invitations` — invitation token, sent/opened/accepted timestamps, reminder log
- Speaker portal (scoped attendee-style token): confirm participation, upload bio/headshot, upload slides against a deadline, view their own sessions, view questions asked in their sessions, submit disclosure
- `faculty_groups` — for organizing large faculties (e.g. by department or committee)
- Speakers are reusable across events within an organization (a speaker directory), with per-event participation records

**Sponsors / Exhibitors** (include in v1 data model, UI can be thin)
`sponsors` — tier, logo, blurb, links, booth number, contact; `sponsor_deliverables` for tracking what was promised

**Registration & attendees**

- `ticket_types` — name, description, price (minor units), currency, quantity, per-order min/max, sales window, visibility (public / hidden / invite-only), access code, approval requirement, group/table allocation, transferable flag
- `registrations` — event, ticket type, purchaser, status (`pending`, `awaiting_payment`, `confirmed`, `rejected`, `waitlisted`, `cancelled`, `refunded`, `checked_in`, `no_show`), source, form answers (JSONB), tag ID, seat assignment, QR token, timestamps for each transition
- `attendees` — the person; deduplicated across events within the organization by email/phone; consent flags
- `registration_categories` / `attendee_segments` — categorization such as dietary requirement, role, accessibility need, institution, membership status; both explicit tags and saved dynamic filters ("all vegetarians", "all first-time attendees", "all unpaid registrations")
- `waitlists` — position, auto-promotion rules on cancellation
- `guest_registrations` — one purchaser registering several attendees
- `registration_transfers` — reassign a ticket to another person, with audit trail

**Tag IDs:** every confirmed registration gets a human-readable, collision-free, non-guessable identifier (e.g. `EVT24-8F3K-221`) plus a separate cryptographically signed QR token. The tag ID is safe to read aloud and type at a desk; the QR token is the authentication material. Never make the QR payload merely the tag ID.

### 4.2 Registration modes

1. **Auto-confirm** — instant confirmation on submission
2. **Manual approval** — organizer reviews; bulk approve/reject with reason; approval can be delegated per ticket type
3. **Payment-gated** — confirmation on successful payment; hold inventory during checkout with a TTL
4. **Payment + approval** — approve first, then send a pay link with expiry (common for scholarship/abstract-based events)
5. **Invite-only** — access code or personalized invite token required

Every transition is recorded in `registration_status_history` with actor, timestamp, and reason.

---

## 5. Payments and settlement

This is the highest-risk subsystem. Treat it as its own bounded context with its own service layer, its own tests, and no shortcuts.

### 5.1 Collection (money in)

- **PSP abstraction layer.** Define a `PaymentGateway` interface with implementations for the gateways relevant to the target markets. For Ghana and West Africa the realistic set is Paystack, Flutterwave, and Hubtel; add Stripe for international card collection. No gateway-specific logic outside its adapter.
- **Methods:** card (Visa/Mastercard), mobile money (MTN MoMo, Telecel Cash, AirtelTigo Money), bank transfer, Apple Pay / Google Pay where the gateway supports it, and USSD where available.
- **Hosted checkout or gateway-hosted fields only.** Card data must never touch the application servers — target PCI DSS SAQ-A. Store gateway tokens and references, never PANs.
- **Multi-currency:** GHS, NGN, USD, GBP, EUR at minimum. Store the presentment currency and the settlement currency separately, plus the FX rate applied and its source.
- **Order lifecycle:** `orders` → `order_items` → `payment_intents` → `payments` → `refunds`. Inventory held on intent creation with TTL release. Idempotency on every write.
- **Gateway webhooks** are the source of truth for payment state, not the client redirect. Verify signatures, dedupe by gateway event ID, and reconcile nightly against the gateway's transaction list to catch missed webhooks.
- **Fees:** platform fee (percentage and/or fixed, per organization or per event), gateway fee, tax. Configurable as absorbed by organizer or passed to buyer. Show the full breakdown at checkout.
- **Refunds:** full and partial, with reason codes, approval workflow, and gateway-specific constraints (mobile money refunds often behave differently from card refunds — the adapter must expose capability flags).
- **Invoices and receipts:** auto-generated PDF, sequential per-organization numbering, tax fields, downloadable by buyer and organizer, emailable.
- **Discounts:** promo codes (percentage/fixed/free), usage caps, per-attendee caps, validity windows, complimentary tickets, group rates, sliding-scale/pay-what-you-want with a floor.

### 5.2 Payout accounts (organizer onboarding)

Paid-event organizers must complete a payout profile before their event can accept money. Model:

- `payout_accounts` — belongs to organization; `type` = `bank_account` | `mobile_money`; status (`unverified`, `pending_verification`, `verified`, `rejected`, `suspended`); default flag; currency
- **Bank account fields:** account holder name, bank name, bank code/SWIFT/sort code, branch, account number/IBAN, country
- **Mobile money fields:** network/provider (MTN, Telecel, AirtelTigo), wallet MSISDN, registered account name, country
- **Verification:** name-enquiry/account-resolution API against the gateway to confirm the account name matches the organization record before any payout is permitted; store the resolved name and match result. Micro-deposit verification as fallback where resolution APIs are unavailable.
- **KYC/KYB:** `organization_kyc` — business registration number, TIN, certificate upload, director/owner ID document, proof of address, contact details, verification status, reviewer, decision timestamp, expiry/re-verification date. Gate payouts (not registrations) on KYC status.
- **Security:** payout account numbers and MSISDNs encrypted at rest with a dedicated key (envelope encryption via KMS), masked in all API responses (last 4 only), full value visible only to `finance` role with re-authentication, and every read written to the audit log.
- Changing a payout account triggers a mandatory cool-down (e.g. 24–72h) plus notification to all org owners, to blunt account-takeover payout fraud.

### 5.3 Settlement (money out)

Decide explicitly between two models — this is the single most consequential architectural decision in the payments area:

**Model A — Gateway-managed split settlement (recommended for v1).**
Use gateway subaccounts/split payments (Paystack subaccounts, Flutterwave subaccounts, Stripe Connect). Funds settle from the gateway directly to the organizer's bank or mobile money account; the platform's fee is split off automatically. The platform never takes custody of organizer funds. Lower regulatory burden, far less reconciliation code, faster to ship. Constraint: you inherit each gateway's payout schedule and country coverage.

**Model B — Platform-managed settlement.**
Funds land in a platform account; the platform runs its own ledger and initiates transfers to organizers. Gives full control over payout timing, holdback, multi-account splits, and cross-gateway consolidation — and means the platform is holding third-party funds, which carries meaningful licensing, trust-account, and audit obligations that vary by jurisdiction. Ghana in particular has specific Bank of Ghana rules around payment service providers and e-money. Take proper legal and regulatory advice before choosing this model; do not treat this document as legal or financial advice.

Build the abstraction so Model A ships first and Model B remains implementable behind the same interfaces.

Regardless of model, implement a **double-entry ledger** for reporting integrity:

- `ledger_accounts` (platform revenue, gateway clearing, organizer payable, refunds payable, tax payable, per-organization)
- `ledger_entries` — immutable, append-only, every entry balanced, referencing a source document (payment, refund, payout, fee, adjustment)
- Reconciliation jobs: gateway settlement file vs. internal ledger, daily, with a variance report and an exceptions queue
- `payouts` — amount, currency, destination payout account, gateway reference, status (`scheduled`, `processing`, `paid`, `failed`, `reversed`), failure reason, retry count
- `payout_schedules` — per organization: on-demand, daily, weekly, or T+N after event end; minimum payout threshold; holdback percentage retained until N days post-event to cover refunds/chargebacks
- **Statements:** per-event and per-period settlement statements showing gross sales, refunds, gateway fees, platform fees, tax, net payable, amounts paid out, and closing balance — downloadable as XLSX and PDF

### 5.4 Finance APIs

```
GET  /v1/organizations/{id}/balance
GET  /v1/organizations/{id}/payout-accounts
POST /v1/organizations/{id}/payout-accounts
POST /v1/organizations/{id}/payout-accounts/{id}/verify
GET  /v1/payouts
POST /v1/payouts                      # on-demand payout request
GET  /v1/payouts/{id}
GET  /v1/events/{id}/financials
GET  /v1/events/{id}/settlement-statement
GET  /v1/payments
POST /v1/payments/{id}/refund
GET  /v1/ledger/entries
```

---

## 6. Attendance and on-site operations

### 6.1 QR and check-in

- Signed, rotating QR tokens (JWT or HMAC payload with event ID, registration ID, nonce, issued-at) to defeat screenshot sharing; support an offline-verifiable static mode for venues with poor connectivity
- Bidirectional: staff scan attendee codes, or attendees scan a station code
- Manual check-in by tag ID, name, email, or phone — with fuzzy search and duplicate disambiguation
- Multi-point check-in: main entrance, session rooms, meals, exhibition hall — each a `checkin_point` with its own device tokens and rules
- Session-level scan in/out for CPD credit calculation
- **Offline-first check-in PWA:** pre-download the guest list, queue scans locally, sync on reconnect, conflict resolution for double check-in, and a visible sync status
- Anti-passback rules (configurable): block or warn on duplicate check-in
- Real-time attendance dashboard over WebSockets: arrivals per minute, total in venue, per-gate throughput, per-session occupancy vs. capacity
- Walk-in registration at the door, including on-the-spot payment

### 6.2 Badges

- Badge template designer: canvas-based, drag-and-drop, layers, snap-to-grid, rulers
- Elements: text (with data bindings), images, shapes, QR/barcode, background artwork
- Data bindings to any registration/attendee field, category, ticket type, session list, seat number, table number
- Conditional styling by segment (e.g. speakers get a coloured band, VIPs a different template)
- Precise print setup: physical dimensions in mm/inch, bleed, safe area, DPI, front/back, portrait/landscape, common stock presets (e.g. 4×3 in, A6, A7, CR80), N-up sheet layouts with crop marks, and single-badge output for thermal/card printers (Zebra, Evolis, Dymo)
- Output: print-ready PDF (CMYK where feasible), PNG preview, and ZPL for thermal printers
- Batch generation as a queued job; bulk print by filter/segment; reprint tracking with a reprint counter and audit entry
- Live on-demand badge printing at the check-in desk

### 6.3 Seating, tables, and service requests

- Venue layout builder: rows/seats for auditoria, tables/seats for banquets, zones for open floors
- Seat assignment at confirmation: automatic (by segment, group, arrival order, or accessibility need), manual, or attendee self-selection from a live map
- Group seating — keep a registration group together
- `service_requests` — raised by attendee from their portal: type (refreshment, assistance, technical, accessibility, medical, other), note, location (seat/table number, or free-text description plus optional zone selection), priority, status (`open`, `acknowledged`, `in_progress`, `resolved`, `cancelled`), assignee, SLA timers
- Staff console with real-time queue, floor-plan pin view, and per-staff assignment; escalation on SLA breach
- Medical/emergency requests flagged and routed separately with immediate push notification

---

## 7. Engagement

### 7.1 Live quizzes

Question types: single choice, multiple choice, true/false, numeric, ordering, short text (auto-graded against accepted answers). Per-question timer, points, and optional negative marking. Participants join via QR/short URL; anonymous or identity-bound to a registration. Presenter controls: launch, lock, reveal answer, next question, pause. Live dashboard: response count, distribution, correct/incorrect split, per-question timing, leaderboard, and a presentation mode for projection. Results exportable; optional certificate issuance on passing score.

### 7.2 Live polls and open questionnaires

Open-ended and closed questions with real-time response streaming. Visualizations: word cloud, bar/pie, ranked list, live text ticker. Moderation queue — organizer approves responses before they hit the projection screen (essential; assume some responses will be inappropriate). Presenter view for reading responses aloud, marking a response as "read", starring, and switching between questions live. Anonymous mode with abuse controls (rate limiting, profanity filter, per-token limits).

### 7.3 Q&A and forum

- Event-level forum and per-session Q&A threads
- Threaded replies, upvoting to surface priority questions, attachments
- **Organizer response tagging:** organizers can tag any post or reply — `official_answer`, `important`, `action_item`, `decision`, `faq`, plus custom tags. These tags feed both filtered views and the AI summarizer's weighting
- Moderation: pin, lock, hide, delete, ban, report, pre-moderation mode
- Anonymous questions (toggleable), with organizer-only identity visibility
- Notifications: in-app, email digest, push

### 7.4 AI summarization

- Summarize forum and Q&A activity for: today, yesterday, a custom date range, a specific session, or a specific thread
- Output structure: executive summary, key topics, questions answered, unanswered questions, tagged important points (weighted heavily), action items with owners where inferable, and overall sentiment
- Multi-provider LLM abstraction (Anthropic, OpenAI, Gemini, Groq, Ollama) with per-organization provider/model selection and per-organization key support
- Store embeddings in `pgvector` for semantic search across forum content and for retrieval-augmented summaries on long threads
- Cache summaries keyed by (scope, range, content hash); regenerate only when underlying content changes
- Cost controls: token budgets per organization, chunking with map-reduce for long ranges, model tiering (cheap model for chunk summaries, stronger model for the final pass)
- Every summary is labelled as AI-generated, cites the posts it drew from, and is exportable as PDF/Markdown
- Do not send content to an external provider when the organization has selected a local/self-hosted model; make the data-flow explicit in the console

### 7.5 Materials and controlled downloads

- Upload against the event or a specific session; versioning; scheduled release (embargo until session start or event end)
- Access rules by segment, ticket type, session attendance, or individual
- **Download attempt limits:** default 2–3 per participant per material, configurable per material and per event. Organizers can grant additional attempts to an individual, a segment, or everyone. Every attempt logged with timestamp, IP, and user agent
- Signed, short-lived, single-use download URLs; watermarking with attendee name/tag ID on PDFs where enabled
- In-browser preview that does not consume a download attempt
- Download analytics per material and per attendee

---

## 8. Communications

- Segmented recipient targeting from any saved filter or category (e.g. all vegetarians, all unpaid, all speakers, all Day 2 workshop attendees, all no-shows)
- Channels: email (transactional + blasts), SMS, WhatsApp (where a provider is configured), push (mobile app), in-app dashboard feed
- The attendee dashboard shows the full thread of communications relating to their registration, in addition to the email/SMS delivery
- Template library with variable interpolation and per-organization branding; rich text editor; multi-language variants
- Scheduling and triggered automations: on registration, on confirmation, on payment, N days before event, on session start, on check-in, on no-show, post-event feedback
- Delivery tracking: sent, delivered, opened, clicked, bounced, complained; suppression list; unsubscribe handling; consent capture and proof
- Reply-to routing back into the event inbox so organizer replies stay attached to the registration
- Hard rule: no emailing people who have not consented; enforce at the API level, not just the UI

---

## 9. Management backend and exports

The management console is a first-party React app over the public API. It must cover the full operational surface: event setup, programme builder, speaker management, registration review, finance, on-site operations, engagement control, and reporting.

### 9.1 Export engine

Exports are asynchronous jobs, not synchronous downloads. `POST /v1/exports` creates a job; the client polls `GET /v1/exports/{id}` or waits for the `export.completed` webhook; the result is a signed, expiring URL.

**Formats:** XLSX (primary, multi-sheet, formatted headers, frozen panes, auto-filters, typed columns, summary sheet), CSV, PDF (for print-oriented outputs like the programme, badge sheets, and statements), JSON, and ICS for calendar/programme.

**Export catalogue (minimum):**

| Export                | Contents                                                                                                             |
| --------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Registrations         | Full registration records, all form answers as columns, status, categories, tag ID, seat, payment status, timestamps |
| Attendees             | Deduplicated person directory with cross-event history                                                               |
| Guest list (print)    | Alphabetical check-in sheet, PDF and XLSX                                                                            |
| Payments              | Every transaction, method, gateway reference, fees, net                                                              |
| Refunds               | Refund records with reasons and approvers                                                                            |
| Settlement statement  | Gross, fees, tax, refunds, net payable, payouts, balance                                                             |
| Payouts               | Payout history with destination (masked), status, references                                                         |
| Ledger                | Raw double-entry export for accounting import                                                                        |
| Invoices              | Bulk invoice/receipt PDFs as a ZIP                                                                                   |
| Programme             | Full multi-day schedule, per-day sheets, per-track view                                                              |
| Speakers/faculty      | Contact details, sessions, disclosure status, materials submitted                                                    |
| Session attendance    | Per-session scan in/out, duration, CPD credits earned                                                                |
| Check-in log          | All scans with point, device, staff member, timestamp                                                                |
| Certificates          | Bulk attendance/CPD certificates as a ZIP                                                                            |
| Quiz results          | Per-participant answers, scores, ranking                                                                             |
| Poll responses        | Raw and aggregated, with word-frequency sheet                                                                        |
| Forum export          | Threads, replies, tags, and AI summaries                                                                             |
| Downloads             | Per-material and per-attendee download attempts                                                                      |
| Service requests      | All requests with SLA timings and resolution                                                                         |
| Dietary/accessibility | Catering and access requirements summary with counts                                                                 |
| Badges                | Print-ready PDF batches                                                                                              |
| Feedback              | Survey responses with aggregate scoring (NPS/CSAT)                                                                   |
| Audit log             | All administrative actions                                                                                           |

**Additional requirements:**

- **Custom report builder:** choose entity, columns (including any custom form field), filters, grouping, sort, and aggregations; save as a reusable named report; share within the organization
- **Scheduled exports:** deliver on a cron to email, S3, SFTP, or a webhook — for organizers who reconcile daily during a multi-day conference
- Large exports must stream and chunk (never load the full set into memory); test with 100k+ registrations
- Exports respect the caller's RBAC scope — a `check_in_staff` token must not be able to export payment data
- PII-redacted export variant for sharing with sponsors or partners
- Every export logged: who, what, when, which filters, how many rows

### 9.2 Dashboards

Real-time event control room: registrations over time, revenue vs. target, check-in throughput, current occupancy, session capacity heatmap, live engagement participation, open service requests, unanswered forum questions. Historical analytics across events: attendance trends, repeat-attendee rate, revenue by ticket type, no-show rate, channel attribution, geographic distribution.

---

## 10. Non-functional requirements

**Performance targets**

- p95 API response < 300 ms for reads, < 800 ms for writes
- Check-in scan-to-confirmation < 2 s end to end, and functional offline
- Live engagement: 5,000 concurrent participants per session, response-to-dashboard latency < 1 s
- Export of 100,000 registrations completes in < 5 minutes
- Registration/checkout handles a 10,000-request burst on ticket release without overselling

**Reliability**

- 99.9% uptime; degrade gracefully — if the payment gateway is down, registrations still queue; if the network is down, check-in still works
- No overselling: inventory decrements must be transactional with row-level locking or an atomic counter, and load-tested for concurrency
- Idempotency everywhere money or a registration is created
- Zero-downtime migrations; feature flags for risky rollouts

**Security and compliance**

- Encryption at rest and in transit; separate KMS keys for payout and identity data
- PCI DSS SAQ-A posture (no card data on our servers)
- GDPR and Ghana Data Protection Act alignment: lawful basis tracking, consent records, data subject access export, right to erasure with financial-record retention carve-outs, configurable retention per organization, data processing agreement support
- Comprehensive immutable audit log for every administrative and financial action
- Optional data residency configuration per organization
- 2FA for organizer accounts, mandatory for `finance` and `org_owner`
- Signed URLs everywhere; no public object storage buckets

**Internationalization**

- Multi-language content per event with fallbacks; RTL support
- Per-attendee timezone rendering of a programme stored in the venue's timezone
- Localized currency, number, date formatting; localized email templates

---

## 11. Delivery plan

**Phase 1 — Foundation.** Multi-tenancy, auth (OAuth2 + scopes + RBAC), organizations, audit log, webhook infrastructure, OpenAPI pipeline, sandbox environment.

**Phase 2 — Event and programme core.** Events, venues, rooms, multi-day programme, sessions, tracks, clash detection, speakers/faculty and the speaker portal, programme publishing and versioning, public event page API.

**Phase 3 — Registration.** Ticket types, all five registration modes, native and delegated form providers, categories and segments, tag ID generation, waitlists, group registration, transfers, approval workflows.

**Phase 4 — Payments.** Gateway abstraction, Paystack/Flutterwave/Hubtel/Stripe adapters, checkout, mobile money and card, inventory holds, refunds, invoices, discounts, gateway webhook ingestion, reconciliation.

**Phase 5 — Settlement.** Payout accounts (bank + mobile money) with name resolution, KYC/KYB, split-settlement integration, ledger, payout scheduling, settlement statements, finance console.

**Phase 6 — On-site.** QR tokens, check-in APIs, offline PWA, multi-point and session-level check-in, badge designer, badge rendering and print pipeline, seating, service requests.

**Phase 7 — Engagement.** Live quizzes, polls, presentation mode, forum and Q&A, response tagging, materials with download limits.

**Phase 8 — AI and reporting.** Summarization service with multi-provider abstraction and pgvector, export engine and full catalogue, custom report builder, scheduled exports, dashboards.

**Phase 9 — Ecosystem.** SDKs, embeddable widgets, published docs, first-party integrations for the form builder and other in-house products, Zapier/Make connector, calendar sync, Zoom/Meet/Teams integration.

---

## 12. Deliverables per phase

1. Domain model and ERD for the phase
2. OpenAPI additions, reviewed before implementation
3. Migrations with indexes, constraints, and foreign keys
4. Eloquent models with relationships, scopes, casts, and factories
5. Service classes holding business logic; controllers stay thin
6. Form Requests for validation; API Resources for serialization
7. Policies for every resource, with tests
8. Events, listeners, and queued jobs for async work
9. Webhook emissions for all state changes
10. Feature tests covering happy path, authorization, validation, and tenant isolation; unit tests for money math and ledger balancing
11. Seeders producing a realistic multi-day conference for demos
12. Migration/runbook notes and updated docs

**Code standards:** PSR-12, strict types, full type hints, no N+1 queries (assert with a query-count test), all money handled in integer minor units with a Money value object (never floats), UTC storage with explicit timezone conversion at the boundary, structured logging with correlation IDs, and 80%+ coverage with payments/ledger at 95%+.

---

## 13. Decisions to confirm before building

1. **Settlement model:** gateway split settlement (Model A) or platform-held funds (Model B)? This determines the regulatory posture and roughly a third of the payments work.
2. **Tenancy:** shared schema with tenant scoping, or schema-per-tenant? Affects exports, migrations, and per-tenant data residency.
3. **Launch markets and gateways:** Ghana only at first, or Ghana + Nigeria + international from day one?
4. **Form ownership:** does the form builder remain the canonical form engine, or does MiConvener own registration forms outright with the form builder as one client?
5. **Attendee identity:** one global attendee identity across all organizations, or attendee records scoped per organization? Affects cross-event history and privacy.
6. **CPD/CME accreditation:** is credit issuance and certification in v1? It pulls session attendance, disclosures, and certificates onto the critical path.
7. **Mobile apps:** native attendee app in scope, or PWA plus a native check-in app only?
8. **Abstract submission and peer review:** in scope for academic conferences, or a later module?
9. **White-labelling:** custom domains and full branding per organization from launch?

---

## 14. Instruction to the implementing model

Work phase by phase. For the phase requested, produce: the ERD and migrations, the OpenAPI fragment, models, services, controllers, requests, resources, policies, jobs, webhooks, and tests, in that order — and state the assumptions made wherever this brief is ambiguous rather than silently choosing. Flag anything in this brief that appears technically or commercially unwise instead of implementing it as written. Do not generate payment or settlement code that holds funds without first confirming decision 13.1.
