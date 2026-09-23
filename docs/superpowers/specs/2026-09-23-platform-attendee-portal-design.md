# Platform Attendee Portal — Central Identity Design

**Date:** 2026-09-23

**Status:** Awaiting owner review

**Path:** Architectural
**Supersedes:** The tenant-scoped identity, host, route, and history decisions in
`2026-09-22-attendee-portal-design.md`, and the unimplemented
`2026-09-23-attendee-portal-plan-b.md`.

## 1. Decision and goal

MiConvener will provide one passwordless attendee portal at the platform host:

```text
https://miconvener.com/my
```

An attendee proves control of an email address once, then sees everything MiConvener holds for
that normalized address across every organiser: event registrations, released materials,
certificates, abstract authorships, and attendance records. Results are grouped by organiser and
then by upcoming or past event.

This replaces the shipped Plan A assumption that identity and history are verified separately on
each tenant subdomain. Tenant subdomains remain the canonical home of organiser-branded event,
registration, ticket, and console pages. They are no longer the identity boundary for attendee
history.

The product outcome is:

1. Visit `miconvener.com/my`.
2. Enter an email address.
3. Receive a six-digit code at that address.
4. Confirm the code.
5. See every matching MiConvener record, grouped by organiser.

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

### 3.3 Refused hosts

Nested subdomains, suffix-confused hosts, external custom domains, Cloud vanity domains, and
reserved subdomains other than the explicit `www` redirect return a neutral 404 for `/my` and all
verification/history endpoints.

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
    'expires_at' => 1790167200,
]
```

The proof lasts 120 minutes, is available on the base and tenant subdomains through the existing
secure shared session cookie, and is removed on sign-out or expiry. Existing tenant-scoped markers
are not promoted: a person verifies once again on the central portal.

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
central design deliberately replaces that duplication with a prominent “See all my events” link
from both the public event page and the registration portal. The existing ticket, My day, Get help,
and Downloads panels remain immediately usable and are never delayed or replaced by central
verification.

Syntactically invalid email receives a normal 422 field error. A successfully queued job receives
202. Provider delivery is asynchronous; a later provider failure cannot change the completed HTTP
response. The user can resend after the cooldown.

## 7. Cross-tenant attendee history

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

The first useful vertical slice returns registrations grouped by organiser:

```text
organisers[]
  id
  name
  slug
  upcoming[]
    registration_id
    registration_status
    event { name, slug, starts_at, ends_at }
    portal_url
    released_materials[]
  past[]
    same shape
```

Duplicate registrations are retained as separate entries. Upcoming events sort ascending by start
time and ID; past events sort descending. Existing ticket/material authorization stays in place;
the central portal links to tenant-hosted capability routes rather than duplicating downloads.

Later panels use the same verified address and service boundary:

- certificates by registration or `recipient_email`;
- abstracts through `EventAbstractAuthor.email`;
- attendance through matching registrations, with certificate hours displayed separately and no
  manufactured attendance-hour total.

### 7.4 Carried-forward dashboard UI requirements

The central dashboard preserves the useful UI requirements from the superseded Plan B:

- My events is always visible after verification, including its empty state.
- Upcoming precedes past; both are grouped by organiser and each event row shows name, dates,
  registration status, the tenant-hosted portal action, and released material actions.
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

### 7.5 Carried-forward material UI requirements

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
GET   /my/certificates                     attendee.my.certificates
GET   /my/abstracts                        attendee.my.abstracts
GET   /my/attendance                       attendee.my.attendance
```

The shell and send/confirm routes are public with CSRF protection. Session and data endpoints use
`platform_attendee_verified`. Send and confirm also use their dedicated independent rate-limit
middleware.

There is no tenant `/my` application route. The pre-routing host middleware performs the only
compatibility redirect:

```text
GET {tenant}.miconvener.com/my -> 302 central /my?organiser={tenant}
```

Old tenant verification POST endpoints are removed. An already-open old page may fail after the
deployment and must reload; no email link points to those POST endpoints, and code lifetime is only
15 minutes. This is preferable to maintaining two identity systems.

## 9. Delivery sequence

The implementation is delivered in reviewable stages:

1. Platform host boundary, tenant `/my` redirect, `ResolveTenant` bypass, and structural tests.
2. Platform access-code table, verification service, mail, session middleware, independent rate
   limits, Turnstile step-up, pruning, and exhaustive lifecycle tests.
3. Central `/my` verification shell with truthful queued copy, address correction, challenge, and
   empty state.
4. Platform event-history service and `/my/events` endpoint.
5. Event-history UI grouped by organiser, plus “See all my events” links on public event and
   registration pages, with loading/retry/expiry behavior.
6. Certificates, abstracts, and attendance panels with deduplication and conditional tabs.
7. Centralized released-material policy, organiser setting UX, and My day material links.
8. Remove obsolete tenant verification code after the compatibility window, run the security
   audit, full static analysis, frontend build, and full PHP/Pest/JS suites.

No stage changes standard tenant subdomain routing for event pages or the organiser console.

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
  the session ID.

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
- certificate and abstract duplicates collapse while registration duplicates remain intentional;
- attendance and certified hours remain visibly separate with no computed total;
- speaker-policy save/rollback and session material placement work across Materials, My day, and
  Downloads;
- old tenant `/my` links reach the central portal;
- frontend unit tests, lint, build, focused Pest tests, PHPStan, Pint, and the full suite pass;
- production smoke test covers one known address, one address with no records, tenant redirect,
  sign-out, expiry, and a forced rate-limit/challenge case in a safe test environment.

## 12. Explicitly deferred

- permanent attendee accounts, passwords, profiles, or account recovery;
- merging multiple email addresses into one person;
- household/shared-mailbox separation;
- attendee social features;
- native support for external custom domains as the central identity host;
- changing tenant-branded event and ticket URLs;
- automatic organiser data export into a separate attendee warehouse.

These are not required for a safe, useful platform-wide portal and should not be smuggled into the
first implementation.
