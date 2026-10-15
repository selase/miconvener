# Attendee Portal — Design Spec

**Date:** 2026-09-22 · **Status:** superseded for identity, host, routes, and history by `2026-09-23-platform-attendee-portal-design.md`; Plan A remains historical shipped context · **Path:** architectural

> Do not implement this document's tenant-scoped Plan B decisions. The owner approved one central,
> platform-wide attendee identity and history portal on 2026-09-23.

Every fact in section 3 was verified against the code or production on 2026-09-22. Where this spec
and the code disagree later, the code wins — re-verify before relying on a claim.

## 1. Goal

Turn the attendee's page from a confirmation screen that grew four tabs into the front door to
everything the platform already holds for them: their pass, their day, the conference materials,
their certificates, their abstracts, their attendance, and every past event they attended with the
same organiser.

The driver is attendee experience. Most of what makes the portal richer already exists in the
product and is simply unreachable from it; this is chiefly a design for reaching it safely.

## 2. Out of scope (confirmed with the owner)

- **The speaker portal.** Designed separately afterwards. It is furthest from its own brief
  (`miconvener.md:155`): no bio/headshot upload, no COI disclosure, no deadline, no session Q&A, and
  `Speaker` holds only `name, title, organization, bio, photo_path`. It will reuse this spec's
  shell and identity decisions.
- **Platform-wide history.** History is scoped to one organiser. No cross-tenant person record.
- **A person record (`attendees`).** Identity is the verified email within a tenant (section 4).
  The brief's `attendees` table stays a later option; nothing here forecloses it.
- **Accounts and passwords for attendees.**
- **Attendee-to-attendee social features.** The forum exists; the portal links to it.
- **The certificate verification page's own email masker** (`PublicCertificateVerificationController::maskEmail`).
  Left as is. It differs from the portal's masker; consolidating it is a follow-up, not this work.
- **Redesign of any existing page.** No new design tokens, no restyle, no change to how the
  organiser console is organised (section 8).

## 3. What exists today

**The attendee portal** is `resources/js/Pages/Public/Events/Confirmation.jsx` (599 lines), rendered
by `PublicEventController::confirmation` at `/e/{event}/registrations/{registration}`. Four tabs,
each shown only when actionable, all behind `email_verified`:

| Tab | Component | Shown when |
|---|---|---|
| My ticket | `TicketTab` | always (pass, QR, two-step transfer) |
| My day | `AgendaTab` | the event has sessions |
| Get help | `HelpTab` | the event is running |
| Downloads | `DownloadsTab` | a material has been released |

`AttendeePortalController` is not the page. It holds four JSON actions the page calls:
`addToAgenda`, `removeFromAgenda`, `transfer`, `confirmTransfer`.

**Access** is a capability URL: no `signed` middleware, no auth, no session. Knowing the
registration UUID is the credential. `find-ticket` re-sends the link, with an identical response
whether or not the address matched, so it cannot be used to discover a private event's guests.

**The open page already exposes personal data.** Its props include `email`, `phone`, `full_name`,
seat, room, `approval_note`, `qr_image` and `checkout_url`. A forwarded confirmation email hands
all of it over.

**Sessions are shared across every tenant.** `config/session.php:173` sets
`'domain' => '.'.env('SESSION_DOMAIN')`; production runs Redis sessions on `.miconvener.com` with
a 120-minute lifetime. One browser session spans every organiser's subdomain and is the same
session the organiser console logs in with. Nothing is scoped to a tenant by the cookie.

**Tenant resolution** is broader than public-portal scoping: `ResolveTenant` runs in the `web`
group, but it may select a tenant from the request host, the organiser console's session, an
`X-Tenant` header, or a route parameter. The attendee portal therefore adds its own exact host
boundary. For Plan B, the supported public host is the existing MiConvener tenant subdomain:
`{tenant.slug}.{SESSION_DOMAIN}`. External custom-domain portal routing is a separate capability
and is not claimed by this plan.

**Data that exists but the portal cannot reach:**

- `EventCertificate` — `registration_id`, `recipient_email`, `cpd_hours`. Certificates are public
  by design: `/verify/cert/{uuid}` shows the recipient's name, CPD hours and event dates, and
  `/download` serves the PDF. Speaker and volunteer certificates can have a null `registration_id`
  and only a `recipient_email`.
- `cpd_hours` is entered by the organiser at issuance or taken from the template's
  `default_cpd_hours`. It is **never** computed from attendance.
- `EventSessionAttendance` — per-session check-in/out, keyed by `registration_id`. Presence, not hours.
- `EventAbstract` — `user_id` is `$request->user()?->id`, so null for public submissions. Authors
  live in `EventAbstractAuthor`, each with their own `email`. A co-author may never have registered.
- Forum, polls and dynamic forms — each at its own public URL, unlinked from the portal.

**Emails are stored exactly as typed.** Nothing normalises them. `event_registrations` has an index
on `(tenant_id, email)`, and `find-ticket` matches with a case-sensitive `=`, so an attendee who
types different capitalisation cannot recover their ticket today. (Production: 7 registrations,
none mixed-case yet.)

**Materials.** `EventMaterial` has `session_id`, `release_at` and `download_limit`;
`isReleased()` treats a null `release_at` as released. Organisers' uploads accept both `session_id`
and `release_at`, and production's three materials (Tech Summit 2026, `nkabom-events`) all use a
scheduled release. The speaker portal's slide upload sets neither: a speaker's deck is released
the moment it is uploaded, is attached to the event rather than to their session, and a re-upload
overwrites the file in place. A deck is one per speaker per event (`EventSpeaker.slides_material_id`).

**Material downloads were broken in production** until `036a0af` (2026-09-22): the controller
redirected to an unsigned R2 URL that answered 400. Fixed separately ahead of this work; they now
stream through the app.

**The organiser console cannot correct a registration.** Registrations have `approve`, `reject`
and `cancel` routes only; there is no update.

**Frontend testing.** There is no JavaScript test tooling (no Vitest, Jest, Testing Library or
browser plugin). CI runs `npm run build` and `npm run lint`. `assertInertia` is an established
pattern across the tenant tests. Versions: Vite 8, React 19, `@vitejs/plugin-react` 6, Inertia 3.

## 4. Access model

The portal splits by sensitivity.

**The open half is unchanged in reach.** Pass, QR, My day, Get help and Downloads stay on the
capability URL and load in one tap at a venue gate on poor signal. A forwarded ticket reaching
someone else is acceptable there — tickets are transferable.

**What changes on the open half:** email and phone are masked — `am•••@stem.org`, and the phone
reduced to its last three digits (`•••••••234`). The portal's existing
`AttendeePortalController::maskEmail` moves to a shared helper beside a new phone masker; no third
email masker is written.

**The verified half** — My events, My certificates, My abstracts, My attendance — is unlocked by a
one-time code emailed to the attendee. What it protects is the **history**: which events a person
attended is the sensitive fact (a medical conference, a church event, a political gathering). It
does not protect certificates, which are public by design; the certificate panel lists them and
links out to the existing verify and download routes.

**Identity is the verified email within a tenant.** Proving `ama@stem.org` on one organiser's
subdomain unlocks every registration, certificate and authorship for that address with that
organiser. Matching is case-insensitive (section 6.6).

**Where the code goes:**

- From a portal page: always to the registration's own address, never one the visitor types. A
  forwarded link therefore yields the pass but not the history.
- From `/my`: to the address typed. Safe, because a code only unlocks that address's own data;
  typing someone else's address sends them a code the typist cannot read.

**Accepted limitation — email is identity.** A shared or PA-used address shows everyone registered
under it, and none of those people can reach their own history. The escape hatch is an organiser
correcting the address (section 8.1). The proper fix is the person record, out of scope.

**Transfers:** after a transfer the registration's email is the new holder's, so the new holder
sees that event's certificate. Transfers are blocked after check-in, and certificates are issued
after the event, so a certificate can never be transferred away.

## 5. Portal structure

**Verified data is never in the page payload.** Open panels render from Inertia props, so the pass
paints on first load. Verified panels fetch their own data from JSON endpoints that check the
verified session server-side — the pattern the organiser console already uses, where each panel
calls its own route. A forwarded link does not merely hide the history; the history never leaves
the server.

```
resources/js/Pages/Public/Events/AttendeePortal/
  Portal.jsx                shell: header, tabs, verified state, active panel
  VerifyPrompt.jsx          email → code → verified
  panels/
    MyTicketPanel.jsx       open      extracted from TicketTab
    MyDayPanel.jsx          open      extracted from AgendaTab; gains session materials (§7)
    GetHelpPanel.jsx        open      extracted from HelpTab
    DownloadsPanel.jsx      open      extracted from DownloadsTab
    MyEventsPanel.jsx       verified  past and upcoming with this organiser, and their materials
    MyCertificatesPanel.jsx verified  links to the public verify/download routes
    MyAbstractsPanel.jsx    verified  via EventAbstractAuthor.email
    MyAttendancePanel.jsx   verified  sessions attended; certified hours per certificate
```

`My*` names follow the existing tab labels and stay distinct from the organiser's
`CertificatesPanel` and `AbstractsPanel`.

**Two entry points, one shell.** `/e/{event}/registrations/{registration}` shows the open panels
for that registration, plus the verified panels once unlocked. `/my` shows the verified panels only
— the way in for co-authors and returning attendees without a link. The public event page's
existing "Already registered? Find my ticket" block (`Public/Events/Show.jsx:1355`) gains the `/my`
entry; nothing else on that page changes.

**Fixed constraints:**

- The URL `/e/{event}/registrations/{registration}` and the route name `public.events.confirmation`
  never change: every confirmation and ticket email ever sent links there. Only the Inertia
  component is renamed.
- The existing rule that a tab appears only when it can be acted on is kept, and extended to the
  verified tabs: no certificates, no Certificates tab. My events is the one exception that depends
  on the entry point: on a registration page it appears only when there is **another** event, since
  the attendee is already on this one; on `/my` it always appears, because there it is the way to
  reach any event at all.
- The four existing tabs are extracted, not rewritten.

## 6. Backend

### 6.1 `AttendeeAccessCode` (new, landlord, `BelongsToTenant`)

Columns: `id` (uuid), `tenant_id`, `email`, `code_hash`, `attempts`, `expires_at`, `consumed_at`,
timestamps. Mirrors `EventRegistrationTransfer` constant for constant — `TTL_MINUTES = 15`,
`MAX_ATTEMPTS = 5`, a six-digit `random_int` code stored only as a hash, `isUsable()`, `matches()`
via `Hash::check` — a pattern already covered by `TicketTransferVerificationTest`.

A separate table, not shared with transfers: a transfer code moves a ticket and this one only
proves identity, and sharing them would make one bug a ticket-theft bug. Keyed by email. Single
use. A new send invalidates any unconsumed code for that address, so only the latest works.

### 6.2 Routes

Public, in `routes/subdomain.php`, outside the console's `auth` group:

```
GET   /my                                      shell, verified panels only
POST  /my/verify/send                          throttle:public-registration + per-address limit
POST  /my/verify/confirm                       throttle:public-registration
POST  /my/verify/forget                        remove the verified marker
GET   /my/events|certificates|abstracts|attendance   attendee_verified, JSON
```

Console, inside the existing auth group:

```
PATCH events/{event}/registrations/{registration}    same permission as approve/reject/cancel
```

### 6.3 `EnsureAttendeeVerified`, aliased `attendee_verified`

The security boundary. Named to match `feature_limit` and `auth_api_key`.

- The tenant comes **only** from `TenantContext`, never from request input.
- It reads **only** `attendee_verified.{that tenant's id}`. A marker for another organiser in the
  same session is invisible to it (section 3: one session spans every subdomain).
- Missing or expired → 401 JSON; an expired marker is removed.
- It places the verified email on the request. Controllers read the email from there, **never**
  from input.

### 6.4 Send

Input is either a registration id (from a portal page) or an email (from `/my`); the code goes to
the address section 4 specifies. The response is identical whether or not anything matched,
following `find-ticket`'s reasoning. Nothing is sent to an address with no registrations,
authorships or certificates in the tenant — certificates included, because a speaker or volunteer
may hold only a certificate keyed by `recipient_email`, with no registration.

`throttle:public-registration` is 10/min **per IP**, which does not stop inbox-flooding from
rotating addresses, so a new `attendee-code` limiter also caps sends per tenant + address per hour.
Usage is recorded as `find-ticket` records it, but a quota never blocks an access code.

The mail is `AttendeeAccessCodeMail`: `markdown:`, queued.

### 6.5 Confirm

On a match: mark consumed, `$request->session()->regenerate()`, store
`attendee_verified.{tenant_id} => ['email' => …, 'expires_at' => now + 120 minutes]`. The marker
carries its own expiry rather than relying on the global session lifetime. On a miss: increment
`attempts` and return a generic message.

### 6.6 Email matching

Matched on `lower(email)`, backed by a new functional index `(tenant_id, lower(email))` on
`event_registrations`. Stored data is not rewritten: this changes no existing row on a live system.
`find-ticket` is aligned in the same step, since `/my` extends its form and the two must agree on
who someone is. `recipient_email` on certificates and `email` on abstract authors are matched the
same way; those tables are small enough not to need an index yet.

### 6.7 Verified endpoints

Every query is scoped to the tenant and the verified address.

| Endpoint | Returns |
|---|---|
| `/my/events` | registrations for the address, excluding cancelled and rejected, split past/upcoming, each with its released materials (§7) |
| `/my/certificates` | certificates for those registrations **or** with that `recipient_email`, so speaker certificates appear; each links to the public verify/download route |
| `/my/abstracts` | abstracts where an `EventAbstractAuthor` has the address, with status and tracking link |
| `/my/attendance` | `EventSessionAttendance` for those registrations, and certified hours per certificate — as separate facts, with **no summed total** |

## 7. Materials and the speaker-slide release policy

Two kinds of material, one rule, resolved in order:

1. **An explicit `release_at` always wins.** This is organisers' own material, including material
   shared before the event or before a session, and it is how an organiser releases a particular
   speaker's deck early or holds it back.
2. **A speaker's deck with no explicit date follows the event's policy**, relative to that speaker's
   sessions: **before** — released on upload; **during** — once their first session starts;
   **after** (default) — once their last session ends. First and last, because a deck is one per
   speaker per event and must never release before a talk it belongs to.
3. **Anything else with no date is released immediately** — today's behaviour for organisers'
   material, unchanged.

**Storage.** `events.speaker_slides_release` (`before` | `during` | `after`, default `after`) and
`event_materials.source` (`organiser` | `speaker`, default `organiser`). Not stored in
`registration_settings`, which has nothing to do with materials. Every existing material becomes
`organiser`, so its behaviour is unchanged; production holds no speaker decks, so the new default
alters nothing currently visible.

The speaker upload sets `source = speaker` and nothing else. A deck's sessions are **derived** —
the speaker's sessions for that event, reached through `EventSpeaker.slides_material_id` — for both
the release rule and My day. `session_id` is deliberately not stamped on speaker decks: deriving
leaves one source of truth, where stamping would add a second that drifts the moment a session is
rescheduled or a speaker gains a second talk. `session_id` remains the organiser's field for their
own session material.

**Where materials appear:**

- **This event (open half).** Downloads, as today, and beside their session in **My day** —
  session-linked organiser material, and a speaker's deck beside each of that speaker's sessions.
- **Past events (verified half).** My events lists each event's released materials through the
  existing download route, using the attendee's own registration for that event, so the download
  limit applies as it does today.

## 8. Organiser console changes

Two, both additive. No change to the event workspace's structure, navigation or look.

### 8.1 Edit a registration

An **Edit** action on each row of the registrations table (`Tenant/Events/Show.jsx`, beside
Approve / Reject / Cancel), opening a small dialog for name, email and phone. Backed by the new
`PATCH` route, a form request, and the same permission as approve/reject/cancel; changes go through
the existing activity log.

This is the escape hatch for section 4's limitation, and it ships **before** verification (section
11), so no attendee can be locked out of their history with no way back. Changing an email needs no
session handling: verified markers bind to addresses, so the old address simply stops matching.

### 8.2 Speaker-slide release setting

In the existing **Materials** panel, beside the files it governs: one setting for
`speaker_slides_release`.

## 9. Errors and edge cases

- **Code never arrives.** The prompt names the (masked) address it went to and offers a resend
  after 60 seconds, within the hourly cap. If the address is wrong, it says plainly that the
  organiser can correct it.
- **Wrong code.** A generic "that code didn't match"; attempts remaining shown only after the second
  miss. After five, the code is dead and a new one is needed.
- **Verified marker expires mid-use.** The next verified request gets a 401 and the panel shows the
  verify prompt in place.
- **Verified with one organiser, visiting another.** Treated as unverified — the explicit
  `tenant_id` check, never cookie scope.
- **Nothing to show.** The tab does not appear.
- **Cancelled or rejected registrations** are excluded from history.
- **A released material whose file is missing** is a 404 that spends no attempt (`036a0af`).
- **An organiser edits an attendee's email.** The old address stops matching that registration
  immediately.

## 10. Testing approach

**Tests assert what the person receives, not that the code handed off.** No `assertRedirect()` or
`Mail::fake()` standing in as proof of delivery — the two failures found on 2026-09-21/22 both hid
behind exactly those.

**Security boundary, written first:**

- Verified on organiser A → refused on organiser B, in the **same session**.
- The open page's Inertia props never contain history, certificates, abstracts or attendance,
  whether or not the visitor is verified.
- From a portal page, the code goes to the registration's address, never a typed one.
- `/my/verify/send` answers identically whether or not the address matched.
- Codes: hashed at rest, five-attempt cap, 15-minute expiry, single use, only the latest valid,
  per-address hourly cap.
- A request that supplies another address still returns only the session's own data.
- The session id changes on verify.

**Correctness:** case-insensitive identity, including `find-ticket`; a speaker certificate with no
registration appears; an abstract appears through its author's email with no registration;
attendance and certified hours are separate with no total; the release policy in all three modes
against first and last session, `release_at` winning, and undated organiser material unchanged;
the registration edit is permission-gated, audit-logged, and detaches the old address; open-half
email and phone are masked.

**Mail:** `AttendeeAccessCodeMail` gets a render case. `MailableRenderTest`'s reflection guard
fails until it does.

**Frontend.** Vitest and Testing Library are added as dev dependencies (approved 2026-09-22),
scoped to the portal to begin with, with an `npm test` script and a CI step. Production bundles are
unaffected. Versions are pinned at install to releases supporting Vite 8 and React 19. They prove
the extraction preserves behaviour — which tabs appear in which state, the verify prompt, masking —
and every new panel lands with tests.

## 11. Delivery order

Each step ships on its own.

| # | Step | Visible |
|---|---|---|
| 0 | Material downloads stream instead of redirecting — **done**, `036a0af` | live |
| 1 | Add Vitest; pin the portal with `assertInertia` and component tests; **then** extract the shell and four panels | no |
| 2 | Mask email and phone on the open half | yes |
| 3 | Case-insensitive matching and functional index; align `find-ticket` | fixes recovery |
| 4 | Organiser Edit on registrations | console |
| 5 | Verification backbone: model, mail, send/confirm/forget, middleware, `/my`, find-ticket entry | yes |
| 6 | My events, including past events' materials | yes |
| 7 | My certificates | yes |
| 8 | My abstracts | yes |
| 9 | My attendance | yes |
| 10 | Slide release policy, `source` on uploads, materials in My day, Materials-panel setting | yes |

**Two implementation plans, not one.** Eleven steps is too much for a single plan to stay
reviewable. Plan A is the foundation, steps 1–5: tooling, refactor, masking, matching, the
organiser escape hatch and verification — ending with a working `/my` that has nothing yet to show
behind it. Plan B is steps 6–10: the four verified panels and the materials policy, each built on
Plan A's boundary. Plan B is written only after Plan A ships.

Step 1 writes its characterisation tests **before** the refactor: today nothing would notice if the
extraction broke the page. Step 4 precedes step 5 so the escape hatch exists before anyone can need
it. Step 10 is independent of 5–9 and can move earlier if a speaker upload becomes imminent.

## 12. Rollout notes

- Migrations: `attendee_access_codes`; functional index on `event_registrations`;
  `events.speaker_slides_release`; `event_materials.source`. All additive; no data is rewritten.
- The Event Store is being built in parallel in another session, on its own domain and folders
  (`/shops/{slug}`, `Store/`, `Shop/`). No design overlap; expect merge conflicts in
  `conductor/tracks.md`, `app/Http/Kernel.php` and `package.json`.
- Each step is verified on production after deploy, as on 2026-09-21/22, not only in CI.

## 13. Assumptions to confirm

- **"Before" means released on upload**, not a fixed lead time before the session. Asked twice
  without an answer; if a lead time was meant, section 7's rule 2 gains a duration.
- **The verified marker lasts 120 minutes.** Long enough to browse, short enough on a shared device.
- **Resend after 60 seconds, and the hourly cap**, are starting values to tune from real use.

## 14. Plan A outcome and Plan B preconditions (added 2026-09-22)

Plan A (steps 1–5) shipped to main as 036a0af..3819826: full suite 1122/1122, reviewed per task and as a branch.
Beyond the plan it fixed ticket-transfer emails that went out with an empty code box, and three timing/race
weaknesses in verification (send is queue-only; attempt, consume and send-cap are atomic; the dummy hash is
cached across requests because production runs PHP-FPM, where statics reset every request).

**Preconditions before Plan B exposes any history:**

1. **Completed 2026-09-23 (`f37962a`, `938fb6f`) — exact attendee-portal host boundary.** `TenantResolver` skips reserved subdomains
   (`www`, `app`, …) and may fall back to the session's `active_tenant_id`, an `X-Tenant` header, or a
   route parameter. Plan A does not turn that into a cross-tenant authentication bypass: the verified
   marker and every query are still keyed to the same tenant, and a code is still required. It does violate
   the public portal's origin invariant. Before Plan B returns history, every `/my/*` endpoint must require
   the actual normalized request host to equal `{tenant.slug}.{SESSION_DOMAIN}`. A global pre-routing guard
   rejects non-canonical `/my/*` hosts before tenant status, membership, metering, or route middleware can
   observe session/header-selected state; request initialization also clears singleton tenant context. The
   route guard then compares the host directly with the resolved tenant as defense in depth. Neither layer
   persists resolver provenance or depends on mutable `lastSource()` state. Existing MiConvener subdomain
   routing remains unchanged, including one terminal DNS dot. External custom domains are deliberately deferred until they have dedicated route registration,
   normalized/reserved-domain validation, and ownership verification; the attendee portal must return 404 on
   such a host until that separate capability ships.
2. **Completed in `a18e287` — organiser email correction (§8.1 defect).** Correcting an unverified
   registration now re-sends the verification link to the new address and consumes transfer codes sent while
   the old address was the holder. A verified registration deliberately remains verified because the organiser
   is vouching for the correction.
3. **Completed in `a18e287` — universal case-insensitive identity.** The duplicate-registration check
   (`PublicEventController::register`) and the promo per-attendee cap (`PromoCodeService`) now use `forEmail()`,
   so they agree with `/my` about who one attendee is.

**Deferred to Plan B:** prune `attendee_access_codes` (MassPrunable); VerifyPrompt maps every non-OK send to
"Too many attempts" and counts a 429 as a miss; the five-try hint and resend timer have no automated test (owner
cut frontend test scope) but were both verified by hand in Chrome on 2026-09-22, along with the masked payload,
the conditional tabs, the neutral reply to an unknown address, the whole verify loop end to end (real code from a production worker, entered in Chrome, signed-in state shown), and the organiser Edit dialog end to end (a name change reached the public portal). Not covered in the browser: the server-side 422 path, since HTML5 validation intercepts an obviously malformed address — it is covered by a feature test and a component test; "proof lapses" and "signing out" tests lack a positive control; test gaps for unknown
or foreign registration ids and the send-cap path.

Plan B is specified in `docs/superpowers/plans/2026-09-23-attendee-portal-plan-b.md`. It adds the four
verified history endpoints inside the guarded `/my` group, then centralises speaker-slide release policy
before exposing those materials in history and My day.
