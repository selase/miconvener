# Event Registration & Check-in MVP — Design Spec

**Date:** 2026-09-04
**Timeline:** 1-2 days build time, hard deadline (an upcoming event needs live registration)
**Status:** Draft — awaiting review

## 1. Goal

Ship a working path for one upcoming event: a public registration page with paid
ticketing, a host login/dashboard to manage it, and camera-based QR check-in on the
day — inside a 1-2 day build window.

This is a hand-scoped slice of the long-term vision in [`miconvener.md`](../../../miconvener.md)
(Phases 2-4-6: event core, registration, payments, on-site check-in), sized down hard
to hit a near-term date. It deliberately reuses existing multi-tenant and payment
infrastructure rather than building the full domain model described there.

**Product framing:** MiConvener intends to charge for premium communications (SMS,
reminders, international phone verification) as part of its business model. This MVP
therefore ships **email-only** communications by design, not as an oversight — SMS and
verification are deferred as monetizable features for later, not missing pieces.

## 2. Explicitly out of scope

Multi-day programme/sessions/speakers, waitlists, registration transfers, manual
approval workflows, refunds UI (handle manually via the Paystack dashboard for now),
badges, seating, engagement tools (quizzes/polls/forum), materials/downloads, exports
beyond a guest-list CSV, multi-currency, SMS/WhatsApp, third-party webhook emissions,
self-serve host signup, and any public API. None of this blocks a working
registration + check-in flow for one event.

## 3. Tenancy & host account

- Reuses the existing shared-database multi-tenant model: `Tenant`, the
  `BelongsToTenant` trait, the `landlord` connection convention used by sibling
  models (`TenantPaymentGateway`, `MerchantTransaction`, etc.).
- Per the confirmed direction, a tenant can be a single person, not just a formal
  organization — `Event`/`EventRegistration` make no assumption of a company entity.
- The host account for the upcoming event is **provisioned directly** (via `tinker`
  or a seeder), not through the existing self-serve `/register` flow — that flow
  requires selecting a paid platform package and exists for onboarding SaaS
  customers, not spinning up a quick personal host account. Self-serve "become a
  host" signup is a fast-follow, not day-1 scope.
- A host must have the `commerce` feature flag enabled and an **active
  `TenantPaymentGateway`** (Paystack) connected via the existing `/settings/payments`
  page before they can publish a *paid* event. This mirrors Luma's "Connect Stripe"
  gate shown in the reference screenshots.

## 4. Data model

### `events` (landlord connection, tenant-scoped)

| column | notes |
|---|---|
| `id`, `uuid` | |
| `tenant_id` | FK, indexed |
| `name`, `slug` | slug unique per tenant |
| `description` | text, nullable |
| `cover_image_path` | nullable |
| `status` | enum: `draft`, `published`, `cancelled` |
| `starts_at`, `ends_at` | datetime, UTC storage |
| `timezone` | string, e.g. `Africa/Accra` |
| `location_type` | enum: `in_person`, `virtual` |
| `address` | nullable |
| `virtual_link` | nullable |
| `capacity` | nullable int |
| `ticket_price` | int, minor units (pesewas) |
| `currency` | default `GHS` |
| `created_by` | FK users |

### `event_registrations` (landlord connection, tenant-scoped)

| column | notes |
|---|---|
| `id`, `uuid` | |
| `tenant_id`, `event_id` | FKs, indexed together |
| `full_name`, `email`, `phone` | |
| `status` | enum: `pending_payment`, `confirmed`, `cancelled`, `checked_in` |
| `ticket_code` | human-readable, unique, e.g. `EVT-8F3K-221` (per `miconvener.md`'s tag-ID convention) |
| `qr_token` | signed token, **not** the same value as `ticket_code` — the brief is explicit that the QR payload must never be merely the readable tag |
| `amount`, `currency` | what was actually charged |
| `payment_reference` | Paystack transaction reference, nullable until paid |
| `checked_in_at`, `checked_in_by` | nullable |

Money follows the codebase's existing convention (see `Transaction`/`Invoice`):
integer minor units, explicit currency column, never floats.

### Models

`Event` and `EventRegistration` Eloquent models: `BelongsToTenant`, `HasUuids`,
`landlord` connection, standard casts, `Event::registrations()` hasMany,
`Event::tenant()` belongsTo.

## 5. Host-facing flows (Inertia console)

Reuses the existing Console design system and follows the Roles/Team pages as the
pattern to copy:

- **`/events`** — index table (`Table`, `StatusPill` for draft/published/cancelled),
  "New Event" opens a `Modal` form (name, dates, location, price, capacity) — same
  small-form-goes-in-a-modal convention already established for Roles/Team.
- **`/events/{event}`** — Overview (registration count, revenue, share link,
  publish/unpublish), Guests tab (`Table` + `SearchInput` + CSV export + manual
  check-in button as a fallback), Check-in tab (camera QR scanner, see §8).
- **Payment gate:** if `ticket_price > 0` and the tenant has no active gateway,
  "Publish" is disabled with an inline message linking to Settings → Payments —
  mirrors the "Accept Payments" modal from the Luma reference screenshots.
- **New permission:** add `event` to `PermissionsSeeder::modelPermissions()`
  (generates `create event`, `read event`, `update event`, `delete event`,
  category `event`), and wire it into `App\Libraries\RolePermissions` for the
  Org Owner/Org Admin roles, following the existing pattern exactly.

## 6. Public-facing flow (new, unauthenticated)

- **Route:** `{tenant}.miconvener.test/e/{slug}` — added to `routes/subdomain.php`
  outside the authenticated middleware group, resolved by the existing tenant
  subdomain resolution (same mechanism `SubdomainRoutingTest` already covers).
- **New minimal public layout** (not the Console layout) rendering `Public/Events/Show.jsx`:
  event name, date/time (rendered in the event's timezone), location, description,
  price, and a registration form (name, email, phone).
- **`EventRegistrationController@store`** validates the form and creates a
  registration: `confirmed` immediately if free, `pending_payment` if priced.
- **Confirmation page** `GET /e/{slug}/registrations/{registration}` — shows the
  ticket code and QR (generated server-side via `bacon/bacon-qr-code`, already a
  project dependency — no new package needed for this part).

## 7. Payment integration

- **New `EventCheckoutController@checkout($registration)`**: loads the tenant's
  active `TenantPaymentGateway`, constructs `new PaystackGateway(['secret_key' =>
  $gateway->api_key_encrypted])` directly (simpler and more explicit than the
  request-attribute `payment_context=commerce` trick used elsewhere), and calls the
  existing `createOneTimeCheckoutSession($customerId, $amount, $currency,
  $redirectUrl, $metadata)` with `metadata = ['event_registration_id' => ...,
  'type' => 'event_ticket']`. Redirects the browser to the returned Paystack
  authorization URL.
- **Webhook extension:** `Tenant\Commerce\WebhookController::handlePaystack` already
  verifies the signature and records a `MerchantTransaction` on `charge.success`.
  Add a small follow-up step: if the webhook payload's `data.metadata.event_registration_id`
  is present, look up that `EventRegistration` and, if not already `confirmed`,
  mark it confirmed, generate `ticket_code`/`qr_token`, and dispatch the
  confirmation email. This check is idempotent (skips if already confirmed), so
  Paystack's at-least-once webhook delivery can't double-process it.
- No new gateway integration work — this is entirely additive to what's already
  live and tested (`ProcessSuccessfulPaystackCharge`, `VerifyPaystackSignature`,
  etc.).

## 8. Check-in (day-of, camera QR scanning)

Confirmed as day-1 scope, not a fast-follow.

- **New frontend dependency required:** no JS QR-scanning library is currently in
  `package.json`. Recommend **`html5-qrcode`** (mature, handles camera-permission
  UX and works across mobile browsers without a build-step headache) — flagging
  per the project convention that dependencies aren't added without approval;
  this spec review is that approval checkpoint.
- **`Events/CheckIn.jsx`**: opens the device camera, decodes the QR payload
  (`qr_token`), and `POST`s it to `/events/{event}/checkin`. The server verifies
  the token's signature (HMAC, not just a lookup — defeats forged/guessed tokens),
  marks `checked_in_at`/`checked_in_by`, and returns the attendee's name for
  on-screen confirmation.
- **Manual fallback ships alongside** (search by name/email/ticket code, tap to
  check in) — camera permissions and venue lighting/connectivity are common
  failure points, and the brief's own guidance treats offline/manual fallback as
  non-negotiable for check-in.

## 9. Emails

- `EventRegistrationConfirmed` mailable — ticket code, inline QR image, event
  details — following the structure already used under `app/Mail/Billing/*`.
- No SMS, no automated reminders in this MVP (see §1's product framing note).

## 10. Build sequence

**Day 1:** migrations + models + factories → host Events CRUD (list/create/edit/
publish) with the payment gate → public event page + registration form, free-path
working end to end.

**Day 1→2 boundary:** Paystack checkout controller + webhook extension +
confirmation email + ticket code/QR generation — paid-path working end to end.

**Day 2:** Guests table + manual check-in fallback → camera QR check-in page → CSV
export → critical-path tests → polish.

## 11. Testing bar

Given the timeline, tests are scoped to the paths the codebase's own conventions
treat as non-negotiable (money handling, tenant isolation) rather than chasing
blanket coverage:

1. Host can create an event (feature test)
2. Public free-registration flow confirms instantly
3. Public paid-registration flow reaches Paystack checkout redirect with correct
   amount/currency/metadata
4. Webhook confirms the matching registration exactly once (idempotency)
5. Tenant isolation: an event/registration from tenant A is not reachable via
   tenant B's routes
6. Check-in marks `checked_in_at` and rejects an invalid/forged QR token

## 12. Assumptions to confirm or override

- Currency default: `GHS`.
- Public URL shape: `{tenant-subdomain}.miconvener.test/e/{slug}`.
- New permission name: `event` (→ `create/read/update/delete event`).
- QR scanning library: `html5-qrcode` (open to a different pick if you have one).
- Ticket code format: `EVT-XXXX-XXX`-style human-readable code, distinct from the
  signed QR token, per `miconvener.md`'s explicit security note on tag IDs vs. QR
  payloads.
