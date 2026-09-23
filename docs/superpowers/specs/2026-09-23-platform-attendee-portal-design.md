# Platform Attendee Portal — Central Identity Design

**Date:** 2026-09-23

**Status:** Approved after owner review and offline/responsive design review

**Path:** Architectural
**Supersedes:** The tenant-scoped identity, host, route, and history decisions in
`2026-09-22-attendee-portal-design.md`, and the unimplemented
`2026-09-23-attendee-portal-plan-b.md`.

## 1. Decision and goal

MiConvener will provide one passwordless attendee portal at the platform host:

```text
https://miconvener.com/my
```

An attendee proves control of an email address once, then receives one action-oriented command
centre for everything MiConvener holds for that normalized address across every organiser. The
portal supports the whole event lifecycle: preparation before an event, ticket and live-event
actions while it is happening, and records and follow-up after it ends. Upcoming and past events
are navigation groupings, not the portal's purpose.

This replaces the shipped Plan A assumption that identity and history are verified separately on
each tenant subdomain. Tenant subdomains remain the canonical home of organiser-branded public
event, registration, checkout, and console pages. The platform host becomes the canonical home of
attendee identity, history, and event workspaces, including ticket presentation.

The product outcome is:

1. Visit `miconvener.com/my`.
2. Enter an email address.
3. Receive a six-digit code at that address.
4. Confirm the code.
5. See urgent and live actions first, followed by upcoming and past events grouped by organiser.
6. Open a central event workspace containing the ticket, personal agenda, available polls, forms
   and surveys, Q&A, service requests, released materials, and post-event records that apply to
   that attendee and event.
7. Deliberately save the ticket and personal agenda for read-only offline use without suggesting
   that network-dependent live actions work offline.

No attendee account or password is introduced.

## 2. Security and privacy position

MiConvener must not tell an anonymous visitor whether an address appears in an attendee record.
Attendance can reveal association with medical, political, religious, professional, or private
events. An existence check that changes the response or delivery behavior would let an attacker
enumerate attendees.

The send endpoint therefore accepts every syntactically valid address that passes the documented
abuse controls and queues a code whether or not MiConvener currently has records for it. The
response can truthfully say:

> A code is on its way to `name@example.com`.

Only after the code proves control of the address does MiConvener query attendee records. An
address with no records verifies successfully and receives an honest empty state.

The following remain mandatory:

- identical successful response status and shape for addresses with and without records;
- no pre-send attendee lookup;
- asynchronous mail delivery so database contents cannot affect request time;
- cryptographically random, hashed, single-use codes;
- no identity value accepted by a history endpoint after verification;
- explicit cross-tenant queries scoped only by the verified session email;
- independent per-address and per-IP abuse controls.

SMTP cannot establish that a mailbox exists. Possession of the delivered code is the verification.

## 3. Canonical hosts and navigation

### 3.1 Canonical platform host

The platform portal is served only from the exact base domain derived from `SESSION_DOMAIN`:

```text
GET  https://miconvener.com/my
```

The local equivalent is `https://miconvener.test/my`. Host comparison is case-insensitive and
accepts one DNS terminal dot after normalization.

### 3.2 Redirects

- `https://www.miconvener.com/my` redirects to `https://miconvener.com/my`.
- `https://{tenant}.miconvener.com/my` redirects to
  `https://miconvener.com/my?organiser={tenant}`.
- The optional `organiser` value is presentation context only. It may preselect or highlight an
  organiser after verification, but it never supplies identity or query scope.
- Existing public event and registration pages link to the central route with “See all my events”.
- Every event workspace is canonical on
  `https://miconvener.com/my/events/{registration}`. It carries organiser branding but does not
  move identity or private attendee data back to a tenant host.
- Existing tenant registration-confirmation URLs become compatibility handoffs to the canonical
  workspace. They never continue rendering a ticket solely because the visitor knows a
  registration UUID.

### 3.3 Refused hosts

Nested subdomains, suffix-confused hosts, external custom domains, Cloud vanity domains, and
reserved subdomains other than the explicit `www` redirect return a neutral 404 for `/my`, event
workspaces, and all verification/history endpoints. Public custom-domain event pages link back to
the canonical platform host; a service worker or verified identity is never expected to cross an
unrelated custom-domain origin.

### 3.4 Pre-routing boundary

The existing pre-routing attendee host middleware is revised rather than removed. For `/my` and
`/my/*` it:

1. normalizes the trusted-proxy-resolved host;
2. recognizes the exact base domain as the platform attendee host;
3. redirects `www` and valid tenant subdomains only for the GET shell;
4. refuses every other host before route matching and tenant-aware web middleware;
5. marks accepted platform requests so `ResolveTenant` bypasses header, session, route, custom
   domain, and subdomain tenant resolution.

This prevents a console `active_tenant_id`, `X-Tenant`, or stale singleton from applying tenant
status, membership, usage limits, database configuration, or permission-team state to the central
portal. `ResetTenantContext` remains the first application middleware after trusted-proxy handling.

## 4. Platform verification model

### 4.1 Access-code storage

Create a landlord model and table dedicated to platform proof, separate from the shipped
tenant-scoped `attendee_access_codes` table:

```text
platform_attendee_access_codes
  id uuid primary key
  email_normalized varchar
  code_hash varchar
  attempts unsigned tinyint default 0
  expires_at timestamp
  consumed_at timestamp nullable
  created_at / updated_at
```

There is deliberately no `tenant_id` and no `BelongsToTenant` trait. A separate table provides a
safe rolling deployment: tenant-scoped codes already in flight are not reinterpreted as global
codes. The obsolete table and model are removed only in a later cleanup migration after the old
15-minute codes cannot still be usable.

The table has a composite lookup index on `(email_normalized, consumed_at, created_at)`.

The normalized email is `mb_strtolower(mb_trim($email))`. Stored codes remain bcrypt hashes. A new
send consumes every unconsumed platform code for the address before creating the replacement.

### 4.2 Code lifecycle

- six decimal digits generated with `random_int`;
- valid for 15 minutes;
- five guesses per code;
- single use;
- latest code only;
- atomic attempt reservation and atomic consumption;
- session ID regeneration after confirmation;
- dummy hash comparison when no usable code exists, preserving timing behavior.

Expired and consumed codes are mass-pruned daily. Consumed codes may be removed after one day;
expired unconsumed codes are immediately prunable.

### 4.3 Verified session

Successful confirmation stores one platform marker:

```php
attendee_verified_platform => [
    'email' => 'name@example.com',
    'verified_at' => 1790160000,
    'expires_at' => 1790203200,
]
```

The proof lasts 12 hours, enough for one event day without repeatedly interrupting polls or help
requests, and does not slide indefinitely. It is available on the base and tenant subdomains
through the existing secure shared session cookie and is removed on sign-out or expiry. Existing
tenant-scoped markers are not promoted: a person verifies once again on the central portal.

History middleware reads only this marker, removes expired proof, and places the verified email on
the request. Controllers never accept `email`, `tenant_id`, registration ID, or attendee ID as
identity input.

## 5. Send and confirm abuse controls

All limits are server-enforced, backed by the shared Redis cache, configurable under one
`attendee_portal` configuration key, and tested at their boundaries. UI countdowns are convenience,
not enforcement.

### 5.1 Send limits

Independent buckets must all permit the request:

| Scope | Limit | Purpose |
|---|---:|---|
| normalized email cooldown | 1 per 60 seconds | prevents rapid resend/inbox flooding |
| normalized email hourly | 5 per hour | protects one mailbox across distributed IPs |
| normalized email daily | 10 per 24 hours | caps sustained targeting |
| source IP burst | 20 per 10 minutes | limits address spraying |
| source IP daily | 100 per 24 hours | limits sustained provider-cost abuse |

Beginning with the sixth send attempt from one IP in ten minutes, a valid Cloudflare Turnstile
token is required. The challenge is based only on anonymous request volume, never on whether an
email has records. If Turnstile is unavailable, low-volume requests remain usable; challenged
requests fail closed and may retry later. The hard IP and email caps still apply after a successful
challenge.

Required configuration:

```text
TURNSTILE_SITE_KEY
TURNSTILE_SECRET_KEY
ATTENDEE_PORTAL_* limit overrides (optional)
```

No new client or server package is required; the backend verifies Turnstile with Laravel's HTTP
client. Missing production Turnstile secrets fail deployment/config validation rather than silently
disabling the challenge.

### 5.2 Confirmation limits

- five atomic attempts on the code row;
- ten confirmation attempts per normalized email per ten minutes;
- sixty confirmation attempts per IP per ten minutes, allowing shared venue Wi-Fi;
- generic mismatch response for expired, absent, consumed, exhausted, or incorrect codes;
- generic `429` without naming the bucket, remaining allowance, or precise reset timestamp.

### 5.3 Operational protection

- Each send is logged with a request correlation ID, normalized-email HMAC (not plaintext), IP
  HMAC, result category, and provider message ID when available.
- Alerts cover abnormal send volume, bounce/complaint spikes, queue failures, and repeated hard
  limits.
- Provider bounce and complaint events are monitored and may feed the provider's own suppression
  controls without changing the public response.
- The message is transactional platform authentication and does not consume any tenant's
  `email_credits`.

## 6. Mail and user-facing flow

The email is platform-branded because it is not owned by one organiser:

```text
Subject: Your MiConvener sign-in code
```

It contains the code, 15-minute expiry, and “If you did not request this, you can ignore this
email.” It names no events or organisers.

The UI states are:

1. **Address:** email field and “Email me a code”.
2. **Queued:** “A code is on its way to {email}”, code field, confirm action, resend countdown, and
   “Use a different email address”.
3. **Verified:** masked verified address, sign out, and attendee data.
4. **Empty:** “No MiConvener events were found for this address yet”, without implying an error.
5. **Challenge:** Turnstile appears only after the server reports that the anonymous-volume
   threshold requires it.

Verification feedback remains status-specific without revealing attendee existence:

- 422 syntax validation shows the server's field message at the address stage;
- 429 says too many attempts were made and asks the user to try later, without exposing a bucket
  or exact reset time;
- another server failure says the code could not be sent and offers a retry;
- a network failure says the server could not be reached;
- busy actions disable duplicate submission and keep their visible label/state accessible;
- resend exposes a real 60-second countdown driven by the server cooldown;
- after two wrong-code responses, the prompt explains that five wrong tries invalidate a code;
- feedback is announced through an accessible live region and focus moves to the first actionable
  error when appropriate.

The old unconditional “Wrong address? The organiser can correct it for you” copy is removed.
Registration-specific pages may still explain that an organiser can correct the registration's
stored address, but the platform `/my` flow always lets the user choose another address directly.

The superseded Plan B embedded verified history inside the existing registration portal. The
central design replaces that history duplication with a prominent “See all my events” link from
the public event and registration flows. The useful parts of the existing post-payment page become
the central event workspace rather than remaining a disposable confirmation screen on the tenant
host. Its ticket, My day, Get help, and Downloads panels are retained and expanded with contextual
live actions. The ticket is a first-class area of every event workspace, not merely a dashboard
link or a record hidden after payment.

Paystack returns to the central workspace. The browser session receives a 30-minute,
registration-specific checkout grant before it leaves for Paystack. A top-level return uses
that server-side grant to show only that registration; it does not create platform-wide email
proof. If the webhook has not yet confirmed the payment, the workspace shows “Confirming payment”
and polls a registration-scoped status endpoint after two seconds, then at intervals capped at five
seconds, stopping after two minutes. It moves to the issued ticket without a manual refresh once
fulfilment completes; a delayed result offers an explicit retry or email verification rather than
polling forever.

Free-registration email verification establishes the normal platform email proof because the
signed verification link already proves control of that address. Ticket emails link to the central
workspace. If no valid platform proof or checkout grant is present, the user completes the normal
email-code flow and returns to the requested workspace. Registration UUID knowledge alone never
authorizes ticket data, agenda changes, downloads, transfers, forms, or service requests.

Syntactically invalid email receives a normal 422 field error. A successfully queued job receives
202. Provider delivery is asynchronous; a later provider failure cannot change the completed HTTP
response. The user can resend after the cooldown.

## 7. Attendee dashboard and event workspace

### 7.1 Data location

The records needed by the portal use the landlord connection today:

- `EventRegistration`;
- `EventCertificate`;
- `EventAbstractAuthor` and `EventAbstract`;
- `EventSessionAttendance`;
- `Event`, `EventMaterial`, and `Tenant`.

The platform query therefore works for shared and dedicated tenants without connecting to each
tenant database. Dedicated operational database configuration must not be invoked by this route.

### 7.2 Query boundary

One `PlatformAttendeeHistory` service owns all cross-tenant reads. It:

- starts only from the verified normalized email;
- explicitly removes `TenantScope` where a model uses it;
- always qualifies joins and selects `tenant_id`;
- never reads `TenantContext`;
- never accepts an organiser or tenant as an authorization filter;
- excludes banned tenants;
- includes active and deactivated tenants so plan lapse does not erase attendee history;
- excludes cancelled and rejected registrations from event history;
- eager-loads every displayed relationship to prevent N+1 queries;
- returns DTO-shaped arrays rather than leaking Eloquent models.

An optional organiser query parameter filters the already-authorized result in memory or adds an
explicit presentation-only condition alongside the verified email. It can never broaden results.

### 7.3 Result structure

The first useful vertical slice returns actionable registrations grouped by lifecycle and
organiser:

```text
needs_attention[]
  registration_id
  reason
  action { label, url }
  organiser { id, name, slug }
  event { name, slug, starts_at, ends_at }
live_now[]
  registration_id
  registration_status
  event_workspace_url
  ticket { code, type, status, seat }
  available_actions[]
organisers[]
  id
  name
  slug
  upcoming[]
    registration_id
    registration_status
    event { name, slug, starts_at, ends_at }
    event_workspace_url
    ticket { code, type, status, seat }
    available_actions[]
    released_materials[]
  past[]
    same shape
```

Duplicate registrations are retained as separate entries. Upcoming events sort ascending by start
time and ID; past events sort descending. Ticket access moves behind the shared workspace policy.
Material release and download-limit rules remain authoritative and are composed with that policy;
the central UI never duplicates their authorization logic.

“Needs your attention” contains only actions the attendee can take now: completing an approved
payment, confirming a transfer, submitting an open required preparation form, or completing an
open post-event feedback/CME requirement. Waiting for organiser approval is a status, not an
attendee task. “Live now” contains a currently running event or an event into which the attendee
has already checked in, plus only the actions currently open to that registration. One registration
may appear in an urgency section and its normal organiser grouping; both entries use the same
stable registration ID and workspace URL.

Later panels use the same verified address and service boundary:

- certificates by registration or `recipient_email`;
- abstracts through `EventAbstractAuthor.email`;
- attendance through matching registrations, with certificate hours displayed separately and no
  manufactured attendance-hour total.

### 7.4 Dashboard UI requirements

The central dashboard preserves the useful UI requirements from the superseded Plan B:

- My events is always visible after verification, including its empty state.
- “Needs your attention” and “Live now” precede the chronological event list and appear only when
  they contain actionable items.
- Upcoming precedes past; both are grouped by organiser and each event row shows name, dates,
  registration status, a compact ticket summary, the event-workspace action, and currently
  available actions.
- Past events are deliberately de-emphasized when an event is live or an attendee action is due.
- Polls, surveys, forms, Q&A, and service requests are not permanent platform-wide tabs. They
  appear inside the event that owns them and only while relevant.
- Loading uses a stable skeleton or progress state that does not flash an empty dashboard.
- A 401 from any verified endpoint clears all private panel data and returns to verification.
- Another endpoint failure keeps proof intact, keeps successfully loaded panels, and offers a
  focused retry for the failed resource.
- Certificates appears only when non-empty. A certificate related both through registration and
  recipient email appears once; speaker and volunteer certificates without a registration remain
  visible. Each row shows event, recipient, role, issue date, CPD hours when present, and separate
  Verify and Download actions.
- Abstracts appears only when non-empty. Duplicate author rows do not duplicate an abstract, and a
  co-author with no registration still sees it. Each row shows title, event, human-readable status,
  presentation preference, submission date, and tracking action.
- Attendance appears when attended sessions or certified hours exist. “Sessions attended” and
  “Certified hours” are separate sections. No total hours or dwell-derived CPD value is displayed.
- All panels have intentional empty, loading, retry, and expired-proof states; keyboard order,
  visible focus, screen-reader labels, narrow-screen wrapping, and touch targets are tested.

### 7.5 Event workspace

Each registration has one canonical workspace at the platform host:

```text
GET https://miconvener.com/my/events/{registration}
```

The central dashboard, Paystack return, verification flow, and ticket email all converge on this
workspace, so a person does not receive competing ticket experiences. The workspace uses
organiser branding and event context without making the organiser host an identity boundary.
Legacy tenant confirmation URLs perform only an authorized handoff or a return-to verification
redirect; they no longer render private registration data directly.

Workspace authorization succeeds only when the normalized email in valid platform proof matches
the registration's current normalized email, or when the same browser holds an unexpired,
registration-specific checkout grant. A transfer immediately stops the previous email proof from
authorizing the workspace. All workspace reads and writes share this policy; hiding a UI action is
never treated as authorization.

A completed transfer transactionally rotates both the human-readable ticket code and QR token
before notifying the new holder. This makes previously downloaded PDFs, screenshots, and offline
snapshots fail online server-side check-in instead of leaving two usable copies. The new holder
receives the replacement ticket, while the old holder's next online refresh receives an
invalid/transferred state and removes the obsolete portal-managed offline copy. A future offline
staff-scanning system must separately synchronize credential rotations and resolve scans made from
a stale guest list; attendee offline display does not claim to solve offline venue validation.

The workspace is lifecycle-aware:

- **My ticket:** QR code, ticket number, ticket type, registration status, attendee name, seat or
  room assignment when present, and the existing protected transfer flow. This is always the
  primary area for a confirmed registration.
- **Before the event:** payment or approval state, programme, personal agenda, preparation forms,
  workshop signups, and released pre-event material.
- **During the event:** current and next sessions, live polls or quizzes, event Q&A/forum, open
  surveys and feedback, released session material, and Get help.
- **After the event:** outstanding feedback or CME evaluation, recordings and materials,
  certificate, attendance record, and related abstract status.

An action is rendered only when the attendee is eligible and it is open. The workspace reuses the
existing poll, forum, dynamic-form, material, agenda, and service-request domain behavior rather
than reimplementing it in React. Dynamic forms use the verified registration identity instead of
asking the attendee to type their email or ticket code again.

Service requests are durable attendee-visible records. After submission, the workspace continues
to show the latest request after refresh and displays its progression through Open,
Acknowledged, In progress, and Resolved. Urgent first-aid handling remains visibly distinct.
Creation is throttled per registration and source IP, prevents accidental duplicate open requests,
and is available only while checked in or while the event is running. Poll, form, and forum writes
retain their domain-specific duplicate rules and receive explicit registration/IP limits where
they do not already have equivalent protection.

While a request remains open, its status updates through the existing real-time channel when
available and bounded polling otherwise. Disconnecting never changes the displayed status to a
more advanced state; the last server-confirmed status and its timestamp remain visible.

The registered-workspace poll endpoint ignores any client-supplied respondent token. It derives a
stable opaque event-level token from the authorized registration so one attendee cannot submit
twice by clearing browser storage, while the organiser receives no registration ID or email from
that token. Polls remain anonymous unless their existing experience explicitly collects a display
name. The existing public poll route may continue supporting non-registered participants with its
separate anonymous identity behavior.

The payment-return state is part of this workspace rather than a separate page. It distinguishes
an attendee who has not started checkout from one whose provider result is still being confirmed,
uses bounded polling with increasing delay, stops on terminal status or loss of authorization, and
offers a focused retry when fulfilment is delayed. It never asks the browser to fulfil payment.

The dashboard displays a compact ticket summary and “View ticket” action, but the full QR and
ticket controls live in the event workspace. This keeps the central overview scannable while
ensuring ticket information is never dependent on finding the original confirmation email.

Cancellation and postponement notices outrank ordinary actions. A cancelled, rejected,
transferred, or otherwise invalid ticket is never presented as valid while online. Event dates are
rendered with the event timezone named explicitly; an attendee-local equivalent is shown as
secondary information when it differs.

### 7.6 Responsive portal behavior

The dashboard and workspace are mobile-first and remain usable at 320, 375, 768, 1024, and 1440
CSS pixels. Dashboard event rows become vertically ordered cards on narrow screens; dates,
statuses, and primary actions remain visible without a table or sideways scrolling. Record panels
use the same pattern.

The workspace does not use an ever-growing horizontal tab strip. Below 768 CSS pixels it exposes
Overview, Ticket, My day, and More through safe-area-aware bottom navigation; More contains the
contextual actions that are currently available. At 768 CSS pixels and above the same destinations
use compact side navigation. The information hierarchy and URLs remain the same at every
breakpoint.

The following are requirements rather than visual suggestions:

- no horizontal page overflow at supported widths or 200% text zoom;
- controls have at least a 44-by-44 CSS-pixel target and do not rely on hover;
- action groups wrap without shrinking labels into unreadable controls;
- the QR remains high contrast, scannable, and large enough for normal venue scanners;
- sticky controls respect device safe-area insets;
- keyboard order, visible focus, landmarks, live regions, reduced motion, and screen-reader names
  are preserved;
- event status, offline status, and request status never rely on colour alone;
- loading skeletons preserve layout and no empty state flashes before data resolves.

Responsive acceptance uses real browser rendering and interaction at the stated widths, in
addition to component tests. At least one iOS-class and one Android-class mobile browser are
covered in release smoke testing.

### 7.7 Ticket-first offline behavior

Offline support is deliberately read-only and opt-in. The central origin owns one web-app manifest,
a service worker scoped only to `/my/`, an offline shell, and a minimal attendee store; a tenant or
custom-domain service worker is not required. Installing the portal is optional: “Save ticket
offline” works in an ordinary supported browser. The manifest starts at `/my`; the service worker
does not control marketing, billing, or other platform pages.

The versioned application shell is precached, but authenticated HTML and API responses are never placed
indiscriminately into the HTTP cache. Choosing “Save ticket offline” writes one minimal snapshot to
origin-scoped browser storage:

- attendee name;
- event and organiser name;
- event date, timezone, and venue summary;
- ticket number, type, status, seat or room;
- embedded QR image/token representation;
- the attendee's personal agenda;
- `saved_at` and `last_confirmed_at` timestamps.

It excludes email, phone, certificates, abstracts, service-request notes, poll responses, forum
content, and undisclosed event data. The UI warns people using shared devices, offers “Remove
offline copy”, clears portal-managed offline records on sign-out, and purges a saved ticket seven
days after the event ends. If the event has no end date, it expires 30 days after its last online
confirmation. It makes no false encryption claim when the decryption key would live in the same
browser storage. A new deployment replaces stale shell caches without silently deleting a ticket
the attendee deliberately saved.

When offline, the workspace shows a persistent offline banner and the last-confirmed timestamp.
Ticket and agenda snapshots remain readable. Polls, quizzes, forms, surveys, Q&A, transfers,
downloads not already saved, payment checks, and service requests are disabled with “Internet
connection required”; none are queued for later replay. Connectivity restoration revalidates the
ticket before removing a stale or invalid warning.

The existing self-contained ticket PDF remains the universal fallback. The workspace adds an
authorized “Download PDF ticket” action instead of relying only on the email attachment. Once
downloaded, the file cannot be remotely erased; the UI says so, and venue check-in always validates
the scanned token against current server state whenever connectivity exists.

### 7.8 Carried-forward material UI requirements

Plan B's material-release work remains in scope because it fixes existing attendee surfaces rather
than depending on tenant-scoped identity:

- one `MaterialReleasePolicy` remains the only interpreter of explicit `release_at`, organiser
  material defaults, and the event's speaker-slide release policy;
- explicit `release_at` always wins;
- undated organiser material remains immediately released;
- speaker policy supports before, during, and after; during uses the first linked session start,
  after uses the last linked session end, and a sessionless during/after deck stays withheld;
- organiser and speaker material provenance is stored explicitly;
- the organiser Materials panel exposes the speaker policy with plain-language labels, permission
  enforcement, saving state, success feedback, failure feedback, and rollback to the previous
  value when saving fails;
- My day places each released organiser session file and speaker deck beside its applicable
  session, without duplicating a deck within one session;
- the existing Downloads panel remains available and uses the same centralized release decision;
- every material action continues to use the attendee's owning registration so missing-file,
  release, and per-registration download-limit checks remain intact.

The accepted email-identity limitation remains: a shared mailbox sees all records associated with
that mailbox, and two people using the same address cannot be distinguished without a future
person/account model.

## 8. Routes and middleware

Platform routes live on the exact base-domain group and use platform-specific names:

```text
GET   /my                                  attendee.my
POST  /my/verify/send                      attendee.my.verify.send
POST  /my/verify/confirm                   attendee.my.verify.confirm
POST  /my/verify/forget                    attendee.my.verify.forget
GET   /my/session                          attendee.my.session
GET   /my/events                           attendee.my.events
GET   /my/events/{registration}            attendee.my.events.show
GET   /my/events/{registration}/status     attendee.my.events.status
GET   /my/events/{registration}/ticket.pdf attendee.my.events.ticket
POST  /my/events/{registration}/agenda/{session}
DELETE /my/events/{registration}/agenda/{session}
GET   /my/events/{registration}/service-requests
POST  /my/events/{registration}/service-requests
POST  /my/events/{registration}/transfer
POST  /my/events/{registration}/transfer/confirm
GET   /my/events/{registration}/forms
GET   /my/events/{registration}/forms/{form}
POST  /my/events/{registration}/forms/{form}
GET   /my/events/{registration}/poll
POST  /my/events/{registration}/poll/{poll}/respond
GET   /my/events/{registration}/forum
POST  /my/events/{registration}/forum
GET   /my/certificates                     attendee.my.certificates
GET   /my/abstracts                        attendee.my.abstracts
GET   /my/attendance                       attendee.my.attendance
```

The shell and send/confirm routes are public with CSRF protection. Session and data endpoints use
`platform_attendee_verified`. The event show, status, and PDF routes use one shared workspace
authorization policy that also recognizes an unexpired registration-specific checkout grant.
Send, confirm, status polling, and live-event writes use dedicated independent rate-limit
middleware appropriate to their risk.

Central action controllers are thin adapters around extracted agenda, transfer, poll, form, forum,
material, and service-request domain services. Existing public routes may call the same services;
their validation, eligibility, idempotency, release, and duplicate-response rules are not copied
into a second implementation. Forum vote/report and material-download routes follow the same
prefix and policy even though they are omitted from the abbreviated route list above.

There is no tenant `/my` application route. The pre-routing host middleware performs the only
compatibility redirect:

```text
GET {tenant}.miconvener.com/my -> 302 central /my?organiser={tenant}
```

Old tenant verification POST endpoints are removed. An already-open old page may fail after the
deployment and must reload; no email link points to those POST endpoints, and code lifetime is only
15 minutes. This is preferable to maintaining two identity systems.

The old tenant route below remains as a permanent lightweight compatibility handoff because links
already exist in historical email. It never renders the ticket itself:

```text
GET {tenant}/e/{event}/registrations/{registration}
  -> central workspace when already authorized
  -> central verification with an opaque return target otherwise
```

The return target is server-derived or signed and relative to the platform host. Arbitrary return
URLs, email values, tenant IDs, and registration IDs supplied by the browser do not become
authorization inputs.

## 9. Delivery sequence

The implementation is delivered in reviewable stages:

1. Platform host boundary, tenant `/my` redirect, `ResolveTenant` bypass, and structural tests.
2. Platform access-code table, verification service, mail, session middleware, independent rate
   limits, Turnstile step-up, pruning, and exhaustive lifecycle tests.
3. Central `/my` verification shell with truthful queued copy, address correction, challenge, and
   empty state.
4. Platform attendee-history service and `/my/events` endpoint, including lifecycle state,
   compact ticket summaries, and eligible-action discovery.
5. Central workspace authorization, checkout grant, payment-status transition, tenant-route
   handoff, ticket-email destination, PDF download, and the first My ticket workspace.
6. Action-first dashboard with Needs your attention, Live now, Upcoming, and Past sections, plus
   “See all my events” links on public event and registration pages.
7. Responsive Overview, Ticket, My day, and More navigation; opt-in offline ticket/agenda storage,
   manifest, service worker, offline status, removal, and expiry behavior.
8. Contextual poll, Q&A, form/survey, feedback, material, and durable service-request actions with
   eligibility and abuse controls.
9. Certificates, abstracts, and attendance panels with deduplication and conditional navigation.
10. Centralized released-material policy, organiser setting UX, and My day material links.
11. Remove obsolete tenant verification code after the compatibility window, run the security
   audit, full static analysis, frontend build, and full PHP/Pest/JS suites.

No stage changes standard tenant subdomain routing for public event, registration, checkout, or
organiser-console pages. Only the private attendee workspace and its payment/email destinations
become canonical on the platform host.

Workspace deployment is additive before it is substitutive: central routes and authorization ship
first; Paystack and new email destinations change only after those routes are healthy; the old
tenant renderer becomes the permanent handoff last. Each numbered stage is a separately reviewed,
deployable change rather than one monolithic release.

## 10. Failure behavior

- Invalid email syntax: 422 field error.
- Send accepted: 202 and the same body for every valid address.
- Turnstile required: 428 with `challenge_required`, carrying no email-existence signal.
- Any hard send/confirm limit: generic 429.
- Wrong/expired/used/exhausted code: generic 422 mismatch.
- Proof expired while fetching data: 401, clear panels, return to verification.
- Queue unavailable before dispatch: generic retryable 503; do not claim the code is queued.
- Provider delivery failure after dispatch: retry job/provider policy; public response remains
  unchanged and monitoring records the failure.
- No matching records after valid proof: 200 with an empty dashboard.
- Banned organiser: omitted from platform history.
- Missing linked tenant resource: display the record without a broken action, or omit only the
  unavailable action.
- Missing or expired checkout grant without verified email proof: return to central verification
  without disclosing registration details.
- Paystack return before webhook fulfilment: bounded “Confirming payment” polling, followed by the
  issued ticket, a terminal payment state, or a retryable delayed-confirmation message.
- Offline workspace: render only an explicitly saved ticket/agenda snapshot, name its age, and
  disable network-dependent actions rather than failing them or queuing writes.

## 11. Testing and acceptance

### 11.1 Host and tenancy boundary

- exact base host works locally and in production configuration;
- uppercase and one terminal dot normalize;
- `www` and valid tenant GET `/my` redirect correctly;
- apex is no longer rejected for central `/my`;
- nested, suffix-confused, custom, vanity, and unrelated hosts return 404;
- `X-Tenant`, active tenant session, authenticated non-member, banned tenant header, and usage
  limits cannot change the platform response or populate `TenantContext`;
- standard tenant event and console routes still resolve normally.
- central workspaces never render on tenant, custom, vanity, nested, or unrelated hosts;
- legacy tenant confirmation URLs redirect through the authorized central handoff and never
  reveal a ticket from registration UUID knowledge alone.

### 11.2 Verification and abuse

- known and unknown valid addresses both queue one platform code and receive identical 202 bodies;
- response timing does not branch on attendee existence;
- email normalization, latest-only, expiry, consumption, and five-attempt behavior;
- cooldown, hourly, daily, IP, and confirmation buckets tested independently at boundaries;
- an IP crossing the soft threshold requires Turnstile, and valid Turnstile does not bypass hard
  caps;
- provider bounce handling and queue failure preserve public privacy;
- no tenant email credit is metered;
- platform session proof works across root and tenant links, expires, signs out, and regenerates
  the session ID at the 12-hour boundary;
- a checkout grant authorizes exactly one registration for 30 minutes and never exposes other
  registrations for the same email or organiser;
- a registration transfer immediately makes the previous verified email insufficient for online
  workspace access;
- transfer completion rotates the ticket code and QR token atomically, the new ticket checks in,
  and the previous PDF, screenshot, and offline QR no longer resolve at online check-in;
- service-request, forum, form, and poll limits are exercised independently and cannot be bypassed
  with a different tenant host.
- registered poll responses use the server-derived opaque event token, reject repeat submission
  after browser storage is cleared, and do not expose registration identity to organisers;

### 11.3 Cross-tenant data

- one verified address sees matching records from two organisers;
- another address's records never appear;
- request `email`, `tenant_id`, and organiser inputs cannot broaden the result;
- active and deactivated tenants appear; banned tenants do not;
- cancelled/rejected registrations are excluded;
- duplicate registrations remain distinct and ordering is stable;
- released materials only, with correct owning registration links;
- query-count assertions prevent N+1 regressions;
- certificate, abstract, and attendance semantics match the earlier approved constraints.

### 11.4 UI and release

- truthful “code is on its way” copy for every valid address;
- “Use a different email address” returns to an editable field;
- resend countdown mirrors but does not replace server enforcement;
- 422, 429, other server, and network failures use distinct, tested copy without leaking whether
  attendee data exists;
- two wrong attempts reveal the five-try explanation and a new code resets that state;
- Turnstile is accessible by keyboard and announces errors;
- verified empty state is not presented as failure;
- organiser grouping, stable loading, per-resource retry, proof-expiry clearing, and mobile layout;
- Needs your attention and Live now outrank chronological history and disappear when empty;
- every confirmed registration exposes ticket code, QR, type, status, and seat where present in
  its event workspace, and the central dashboard can always navigate back to it;
- Paystack return and ticket-email links continue to open the same event workspace;
- poll, quiz, Q&A, survey, feedback, form, and help actions appear only for the owning event and
  only while eligible;
- a submitted service request survives refresh and exposes Open, Acknowledged, In progress, and
  Resolved state without allowing another attendee's request to appear;
- certificate and abstract duplicates collapse while registration duplicates remain intentional;
- attendance and certified hours remain visibly separate with no computed total;
- speaker-policy save/rollback and session material placement work across Materials, My day, and
  Downloads;
- old tenant `/my` links reach the central portal;
- frontend unit tests, lint, build, focused Pest tests, PHPStan, Pint, and the full suite pass;
- production smoke test covers one known address, one address with no records, tenant redirect,
  sign-out, expiry, and a forced rate-limit/challenge case in a safe test environment;
- rendered browser tests cover 320, 375, 768, 1024, and 1440 CSS pixels, 200% text zoom, keyboard
  navigation, 44-pixel targets, wrapping, safe-area spacing, and no horizontal page overflow;
- offline tests prove the shell starts without a network after its first load, only explicitly
  saved ticket/agenda data appears, private API responses are absent from general caches, live
  writes are disabled, reconnection revalidates state, sign-out/removal clears the managed copy,
  and expiry purges it;
- payment-return browser coverage proves the pending state advances after webhook fulfilment
  without a manual reload and bounded polling stops in every terminal/error state.

## 12. Explicitly deferred

- permanent attendee accounts, passwords, profiles, or account recovery;
- merging multiple email addresses into one person;
- household/shared-mailbox separation;
- attendee social features;
- native support for external custom domains as the central identity host;
- moving public event, registration, or checkout pages away from tenant branding;
- offline submission or replay of polls, forms, forum posts, transfers, payments, or service
  requests;
- offline organiser/staff check-in and stale-scan conflict resolution, which is a separate on-site
  operations project;
- native Apple Wallet or Google Wallet passes beyond the downloadable PDF and saved web ticket;
- automatic organiser data export into a separate attendee warehouse.

These are not required for a safe, useful platform-wide portal and should not be smuggled into the
first implementation.
