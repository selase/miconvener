# Platform Attendee Portal — Audit and Remediation Ledger

**Date:** 2026-09-24
**Audited work:** `15226a2..654632f` on `main` (17 unpushed commits) implementing
`docs/superpowers/specs/2026-09-23-platform-attendee-portal-design.md`.
**Purpose of this file:** hand back to the agent that built the work, so its memory records
what was wrong, why it was wrong, and how it was resolved.

Each finding is written as **Problem → Why it matters → Resolution**. Findings verified by a
throwaway Pest test are marked **[verified by test]**; those tests were deleted after the audit
and, where the fix needed one, replaced by a permanent regression test named in the resolution.

---

## What the audit confirmed as sound

State this first, because the remediation below should not be read as a verdict on the whole
body of work. These were checked and found correct:

- `TenantHostMatcher` rejects suffix-confused (`evilmiconvener.com`), nested
  (`a.b.miconvener.com`), and external hosts; accepts one DNS terminal dot; case-insensitive.
- Global middleware order is `TrustProxies → ResetTenantContext → GuardAttendeePortalHost`,
  so the host is proxy-resolved and tenant singletons are cleared before routing.
- **All 20** workspace endpoints load the registration with `withoutGlobalScopes()` and call
  `PlatformAttendeeWorkspaceAuthorizer::canAccess()`. No endpoint omits it.
- Every child object (poll, poll option, session, dynamic form, forum thread, material) is
  resolved **through the event relationship**, not by bare ID. `TenantScope` is a no-op when
  no tenant is in context, so this is what prevents cross-tenant IDOR — and it holds
  everywhere. No cross-tenant read or write path was found.
- History queries start only from the verified email, exclude banned tenants, and deduplicate.
- Verification keeps atomic attempt reservation, atomic consumption, session regeneration, and
  the cached dummy hash (the PHP-FPM lesson from Plan A survived the rewrite).
- Poll and forum respondent tokens are server-derived HMACs; a client-supplied token is ignored.
- The PHPStan baseline shrank rather than grew.
- Full Pest suite at audit time: **1218 passed, 5197 assertions.**

**After remediation, every gate CI runs is green locally:**

| Gate | Before | After |
|---|---|---|
| `pint --test` | 1 file dirty | passed |
| `phpstan` | 1 error | 0 errors |
| `npm run lint` | not run | passed |
| `npm test` (Vitest) | **6 of 10 failing** | 10 passed |
| `php artisan test` | 1218 passed | **1228 passed, 5281 assertions** |

---

## Findings

### F1 — The work was never pushed, so CI never ran it
**Problem.** `main` was 17 commits ahead of `origin/main`. The last CI run was `15226a2`
(2026-09-23); nothing from `eae535e` onward had been through CI or deployed.

**Why it matters.** `conductor/tracks.md` recorded "Full test suite passing (110 feature tests,
625 assertions)" as the completion evidence. That was a subset, not the suite — the real suite
is 1218 tests. More importantly, CI runs five gates the local run did not: `pint --test`,
`phpstan`, `npm run lint`, `npm test` (Vitest), and `php artisan test`. Two of them were red.

**Resolution.** F2, F3 and F15 fixed the three red gates; all five now pass locally. Pushing
remains the owner's call and has not been done. **Before pushing, run all five gates, not just
Pest** — two of the three failures were invisible to `php artisan test`.

---

### F2 — PHPStan failed on `main`
**Problem.** `app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php:1105` used
`$event->tenant?->slug ?? $registration?->tenant?->slug`. `Event::tenant()` is a non-nullable
`BelongsTo`, so the nullsafe was unnecessary and the fallback unreachable
(`nullsafe.neverNull`).

**Why it matters.** CI runs `phpstan` and would have failed the push.

**Resolution.** Reduced to `$event->tenant->slug`. Safe: the sole caller (`show()`) eager-loads
`event.tenant`, which is what `d4f310a` added. PHPStan: 0 errors.

---

### F3 — Pint failed on `main`
**Problem.** `tests/Feature/Events/AttendeePortalHostTest.php` imported
`App\Services\Events\AttendeeVerification`, a class deleted in the same body of work.

**Why it matters.** CI runs `pint --test`. An import of a deleted class also misleads the next
reader into thinking the old service still exists.

**Resolution.** Import removed. Pint: passed.

---

### F4 — Cloudflare Turnstile was undeployable
**Problem.** The spec requires Turnstile from the sixth send per IP per ten minutes, and
requires that missing production secrets fail config validation. `TURNSTILE_SITE_KEY` and
`TURNSTILE_SECRET_KEY` were absent from `.env.example`, and no config validation exists.

**Why it matters.** `verifyTurnstileToken()` fails closed when the secret is empty. Deployed
without the keys, every visitor past the fifth code request from one IP in ten minutes is
refused — and conference Wi-Fi and mobile CGNAT put thousands of attendees behind one IP. The
portal would appear broken exactly at an event.

**Resolution.** Both keys added to `.env.example` with a comment stating the failure mode.
Config validation is **not** added; the keys must be set in Laravel Cloud before deploy.
Recorded as a deploy precondition rather than code.

---

### F5 — Working-tree debris re-broke a committed fix
**Problem.** An uncommitted edit to `MyDayPanel.jsx` added a second "Add my day to calendar"
link calling `route('public.events.agenda.ics')` **without** the `subdomain` parameter —
precisely the Ziggy defect that `d4f310a` had just fixed, next to the corrected copy.

**Why it matters.** Duplicate control, and the new one throws on a host where the subdomain
default is absent.

**Resolution.** Reverted (`git checkout --`). Lesson for the builder: after a fix commit,
re-check the working tree for a stale editor buffer writing the pre-fix version back.

---

### F6 — The legacy tenant confirmation URL still handed out the ticket **[verified by test]**
**Problem.** `PublicEventController::confirmation` still rendered the full portal — `ticket_code`
and the QR image — on an unauthenticated route, to anyone holding the registration UUID. The
spec's §3.2 and §8 required that URL to become a compatibility handoff that "never continues
rendering a ticket solely because the visitor knows a registration UUID". That was never built;
stage 11 removed the old *verification* code but left the old *renderer*.

**Why it matters.** It is the promise the whole redesign rests on: identity is a proven address,
not a URL. Worse, it silently defeated the transfer credential rotation built in the same body
of work. Verified by test: after a transfer rotates the ticket code and QR, the **previous**
holder — using only the URL from their old email, with no session — was served the **new**
holder's ticket code and QR.

**Resolution.** `confirmation()` now resolves the registration and redirects to the canonical
workspace, rendering nothing itself. The workspace applies the real policy: it opens for proven
identity or a checkout grant, and otherwise redirects to verification. The URL and the route
name `public.events.confirmation` are unchanged, because every ticket email ever sent points at
them.

The free-ticket path needed care, or the handoff would ask people to prove an address they had
just proved. `PublicEventController::verify` — a signed link delivered to the address itself —
now issues a **registration-scoped** grant before handing off, so the visitor lands on their
ticket with no second challenge.

Note the near-miss here, because it is the interesting part. The obvious move was to treat that
link as proof of the address and mint full platform proof: `storeVerifiedSession()` existed,
uncalled, with a docblock naming exactly this use (F12), so the wiring had clearly been designed
that way. It was built that way first, then changed. The link is `temporarySignedRoute(...,
now()->addDays(7))` — valid for a week, and a link can be forwarded, archived, or read over a
shoulder in a way a typed code cannot. Platform proof would have turned any leaked verification
email into 12 hours of cross-organiser history, which is precisely what §2 of the spec argues
must never be casually reachable. A grant scoped to the one registration buys the same UX at a
fraction of the exposure. `storeVerifiedSession()` was deleted rather than wired.

Regression tests: `tests/Feature/Events/LegacyAttendeeHandoffTest.php` — the link no longer
renders the ticket; a proven attendee passes straight through; the signed verify link proves the
address and opens the workspace.

---

### F7 — The tenant checkout URL minted a workspace grant with no proof **[verified by test]**
**Problem.** `EventCheckoutController::checkout` called `grantCheckoutAccess()` unconditionally,
on a route with no authentication. Verified by test: an anonymous visitor holding only the
registration UUID received a 30-minute grant that opened the private workspace — ticket, PDF,
forms, poll writes, service requests.

**Why it matters.** It is the bypass that would have survived the F6 fix. Closing the front door
while this stayed open would have achieved nothing.

**Resolution.** Paying and seeing are now separated. Anyone holding the link may still pay — a
colleague settling an invoice is a real case — but the grant is minted only for a browser that
already holds a claim, via a new `canClaimWorkspace()`:

1. it already passes `canAccess()` (proven address, or a grant it holds);
2. the request carries a signature this application issued — `EventRegistrationPaymentInvite`
   now links with `URL::signedRoute`, so following the invite from the inbox it was sent to is
   itself the claim;
3. it created the registration in this session — `PublicEventController::register` now calls
   the new `PlatformAttendeeWorkspaceAuthorizer::rememberRegistered()`.

All three are held by the browser rather than typed into the URL.

Regression tests: in `LegacyAttendeeHandoffTest.php` — the checkout link alone does not open the
workspace; a signed payment invite still does.

---

### F8 — The transfer recipient was shown an action they cannot open **[verified by test]**
**Problem.** `PlatformAttendeeHistory::getHistory()` added a "Ticket transfer awaiting your
confirmation" card, labelled "Review & accept transfer", to the **recipient's** needs-attention
list. But `canAccess()` authorizes on the registration's *current* email — still the sender's —
and the confirmation code is mailed to the sender, who completes the handover. Verified by test:
the card rendered, and following it was refused.

**Why it matters.** The portal's organising idea is that "needs your attention" contains only
what the attendee can do now. The spec says so explicitly: "Waiting for organiser approval is a
status, not an attendee task." A pending incoming transfer is the same kind of thing.

**Resolution.** The block was removed, with a comment recording why, so it is not
reintroduced as a missing feature. The ticket appears in the recipient's ordinary organiser
grouping once it is actually theirs. `PlatformAttendeeHistoryTest` was updated: it now asserts
the transfer is **not** offered, and adds a general guard that every action offered belongs to a
registration whose email matches the viewer.

---

### F9 — Records follow the registration's *current* email
**Problem.** `getCertificates()` and `getAttendance()` reach records through
`registration.email`. A transferred ticket therefore carries the previous holder's certificate
(bearing their name) and their session check-ins into the new holder's portal.

**Why it matters.** Cross-person disclosure inside a legitimate feature — and not a contrived
one. A two-day conference where a colleague takes over the ticket on day two is ordinary, and
day one's check-ins belong to the person who was in the room.

**Resolution.** A completed transfer is now the dividing line between one holder's records and
the next.

- **Certificates.** `recipient_email` turned out to be `NOT NULL`, so the `orWhereHas(registration)`
  branch could only ever have matched certificates naming *somebody else* — it was the leak and
  nothing else. It now survives only where no transfer has been consumed since `issued_at`,
  which preserves the legitimate case it was presumably added for: an organiser correcting a
  misspelt address must not cut the holder off from a certificate already issued.
- **Attendance.** Check-ins with `checked_in_at` earlier than a consumed transfer are excluded.

This needed a `transfers()` `HasMany` on `EventRegistration`, which did not exist.

Regression test: `tests/Feature/Events/TransferredTicketRecordsTest.php` — the previous holder's
certificate does not follow the ticket (with a positive control that it still reaches the person
it names); day-one attendance stays with the day-one attendee while day-two attendance moves;
and an untransferred ticket keeps everything it always had.

---

### F10 — Spec §5.3 operational protection was not built
**Problem.** No send logging with correlation ID, normalized-email HMAC, IP HMAC, result
category or provider message ID; no alerting on send volume, bounce/complaint spikes, or
repeated hard limits. Only Turnstile errors are logged.

**Why it matters.** The send endpoint mails any syntactically valid address by design. Without
this telemetry there is no way to see it being abused, and no way to answer a deliverability
complaint.

**Resolution.** Partially built. `PlatformAttendeeVerification::logSend()` now records every
send attempt — allowed, rate-limited, challenged or failed — with a correlation ID and the
address and IP as `hash_hmac` digests keyed by `app.key`. Digests rather than plaintext: enough
to group a repeat offender and answer a complaint, without writing attendees' addresses into the
application log.

**Still outstanding:** alerting on send volume, bounce/complaint spikes, queue failures and
repeated hard limits, and provider message IDs (the mail goes out from a queued job, so the
message ID is not available at the point of logging). These are monitoring configuration rather
than application code.

---

### F11 — Two tests assert less than they claim
**Problem.** (a) `AttendeePortalRouteSecurityTest` is titled "every platform attendee route is
protected by global GuardAttendeePortalHost" but only asserts that some routes exist and the
legacy ones do not; it fetches `$globalMiddleware` and never uses it. (b)
`PlatformAttendeeWorkspaceTest` asserts the unauthorized redirect contains `return_to=`, but
nothing in the application ever reads `return_to`.

**Why it matters.** This is the failure mode the Plan A work was built to avoid: a test that
asserts hand-off rather than arrival. A reader believes the host guard is covered by that test
and that verification returns you where you were going. Neither was true.

**Resolution.**

(a) `AttendeePortalRouteSecurityTest` was rewritten to exercise the boundary instead of
describing it. It now asserts `GuardAttendeePortalHost` is in the **global** stack and ordered
after `TrustProxies` (the host must be proxy-resolved before it is judged), then walks every
`attendee.my*` route, issues a real request to it from `evil.example.com`, and requires a 404 —
naming the offending method and URI if one answers. It checks 25 routes; if a route is ever
added outside the guard's reach, this fails.

(b) `return_to` is now honoured. `MyPortal.jsx` reads it after verification and follows it only
when it matches `^/my(/|$)` and is not protocol-relative, so a crafted value cannot bounce a
just-verified visitor off-site. Assets rebuilt.

---

### F12 — Dead code
**Problem.** (a) `EnsureTenantFromHost` and its `tenant_from_host` alias are registered but no
route uses them (superseded by `GuardAttendeePortalHost`). (b)
`PlatformAttendeeVerification::storeVerifiedSession()` — which grants 12 hours of platform
proof **with no code** — has zero callers. (c) `AttendeePortalRateLimiter::clearSendLimits()`
and `clearConfirmLimits()` have zero callers.

**Why it matters.** (b) is a loaded foot-gun: an uncalled public method that mints identity.
Its docblock says "e.g. from signed free ticket verification link", which shows the wiring was
designed and then never finished — and that unfinished wiring is what F6's fix needed.

**Resolution.** (a) `EnsureTenantFromHost` and its alias deleted. (b) `storeVerifiedSession()`
deleted. It looked like unfinished wiring for the verify link, and was nearly used that way
during this remediation, but wiring it would have granted cross-organiser proof from a
seven-day forwardable link — see F6. The narrower mechanism already existed. (c) Both
`clear*Limits()` removed, along with a dead `$cooldown` variable in `checkSend()`.

---

### F13 — The service worker caches the dashboard with history in it
**Problem.** `/my` now ships `initialHistory`, `initialCertificates`, `initialAbstracts` and
`initialAttendance` as Inertia props. `attendee-sw.js` precaches `/my` at install, with
credentials. Sign-out clears localStorage tickets (`clearAllOfflineTickets`) but not Cache
Storage. The service worker is also registered from the tenant-host portal, where `/my` no
longer exists.

**Why it matters.** On a shared device, an offline navigation after sign-out can serve the
previous person's cross-tenant history from cache. Plan A's spec had an explicit rule —
"verified data is never in the page payload" — which this design reversed without replacing the
protection it provided.

**Resolution.** The shell is now fetched with `credentials: 'omit'` and stored under an explicit
`SHELL_REQUEST`, so the cached copy is the **anonymous** shell: the same URL, no cookies, no
history in it. That removes the disclosure at its root rather than trying to clear caches on the
way out, and still gives an offline boot the shell it needs — the saved ticket is read from
local storage once the app is running. The cache was renamed to `miconvener-attendee-v2`, which
makes the activate handler evict any v1 cache holding a credentialed copy.

The stray registration from the tenant-host portal resolved itself: after F6 that page is only
ever rendered on the platform workspace.

---

### F14 — The speaker-deck backfill can hide material that is live today
**Problem.** `2026_09_24_100100_add_provenance_to_event_materials_table` marks every material
referenced by `event_speakers.slides_material_id` as `provenance = speaker`. Under the default
`after` policy those decks become withheld until the speaker's last session ends — or
permanently, if the deck has no linked session.

**Why it matters.** The original spec justified the default with "production holds no speaker
decks, so the new default alters nothing currently visible". That premise was never re-checked
against production for this rewrite. If any tenant has one, attendees lose access silently.

**Resolution.** *Not changed — this is a deploy-time check, not a code defect.* Before running
the migration on production, confirm whether any tenant has an `event_speakers.slides_material_id`
pointing at a material. If any do, either set an explicit `release_at` on those rows first
(explicit dates always win, so their current visibility is preserved) or set the affected events'
`speaker_slide_policy` to `before`.

---

### F15 — The frontend test suite was red, and had never been run
**Problem.** `npm test` failed: **6 of 10** Vitest tests. `Portal.jsx` and `VerifyPrompt.jsx`
were rewritten for the platform portal and their Plan A component tests were never updated.
`node_modules` was also incomplete locally, so the suite could not even start without `npm ci` —
which is consistent with it never having been run during this work.

**Why it matters.** CI runs `npm test` and `npm run lint`. Neither had been exercised, and the
recorded completion evidence ("110 feature tests") came only from Pest.

**Resolution.** Three distinct causes, all in the tests rather than the components:
- `Portal.jsx` now renders Inertia's `<Head>`, which needs an app context a component test does
  not mount. `resources/js/test/setup.js` now mocks `@inertiajs/react` with minimal stand-ins
  for `Head`, `Link`, `usePage` and `router` (written with `createElement`, since setup.js is
  plain `.js`).
- The portal renders two `<nav>` elements now (desktop tab strip and fixed mobile bar), so
  `getByRole('navigation')` was ambiguous. The helper names the one it means — both already
  carried `aria-label`s, so no component change was needed.
- Copy moved: "The address you registered with" → "Your email address", "Code" → "Verification
  code", and the Downloads tab now carries a count, `Downloads (1)`.

`npm test` 10/10, `npm run lint` clean, `npm run build` regenerated and the new bundle committed.

---

## Lessons worth carrying into the next piece of work

1. **Run every gate the pipeline runs.** Three of five were red while the recorded evidence said
   "full test suite passing". `php artisan test` is not the suite; `pint --test`, `phpstan`,
   `npm run lint` and `npm test` are part of it. When a body of work rewrites components,
   assume their tests need rewriting too.
2. **Removing the old thing is part of building the new one.** The central portal was built
   correctly and the tenant-host renderer was left standing beside it, still answering with the
   data the new design existed to protect. A migration is finished when the old path stops
   working, not when the new path starts.
3. **An uncalled method that mints identity is a bug, not dead weight.** `storeVerifiedSession()`
   was the missing half of the handoff. Where a method exists with no callers, ask whether
   something was left unfinished before deleting or ignoring it.
4. **A test named after a guarantee must exercise it.** Two tests asserted less than their names
   promised; one fetched a variable and never used it. Both would have read as coverage.
5. **When identity is an address and a record hangs off a mutable `email` column, ask what
   happens when it changes.** Transfer was built to rotate credentials, but the records reached
   through the registration were not considered.
6. **Check the column before writing the query.** The certificate fix started as "match unnamed
   certificates through the ticket"; `recipient_email` is `NOT NULL`, so that branch would have
   matched nothing and the leak would have remained.

---

## Deploy preconditions

Nothing here has been pushed. Before it goes out:

1. Set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` in Laravel Cloud (F4). Without them the
   portal hard-fails past five code requests per IP per ten minutes — which one conference
   Wi-Fi network reaches immediately.
2. Check production for existing speaker decks before migrating (F14).
3. Run all five CI gates.
4. Expect the attendee-visible change: every historical ticket email now leads to
   `miconvener.com/my`, and anyone without a signed verify link proves their address with a code
   before seeing a ticket. That is the intended product change in the approved spec, but it is
   the first thing attendees will notice, and support should know before it ships.

---

## Appendix: what the remediation touched

Application code:

- `app/Http/Controllers/Public/PublicEventController.php` — `confirmation()` is now a handoff;
  `verify()` issues a registration-scoped grant and hands off; `register()` remembers the
  registering browser and returns `Inertia::location()` to the workspace.
- `app/Http/Controllers/Public/EventCheckoutController.php` — `canClaimWorkspace()`; the grant is
  conditional.
- `app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php` — nullsafe fix.
- `app/Services/Events/PlatformAttendeeWorkspaceAuthorizer.php` — `rememberRegistered()`,
  `registeredInThisSession()`, `REGISTERED_SESSION_KEY`.
- `app/Services/Events/PlatformAttendeeVerification.php` — `logSend()`; `storeVerifiedSession()`
  removed.
- `app/Services/Events/AttendeePortalRateLimiter.php` — dead helpers and variable removed.
- `app/Services/Events/PlatformAttendeeHistory.php` — pending-transfer card removed; certificate
  and attendance queries bounded by transfer.
- `app/Models/EventRegistration.php` — `transfers()` relation.
- `app/Mail/Events/EventRegistrationPaymentInvite.php` — signed checkout link.
- `app/Http/Middleware/EnsureTenantFromHost.php` — deleted; alias removed from `Kernel`.
- `public/attendee-sw.js` — anonymous shell caching, cache bumped to v2.
- `resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx` — honours `return_to`.
- `.env.example`, `phpstan-baseline.neon` (one stale entry removed, none added).

Tests:

- Added `LegacyAttendeeHandoffTest.php` (5), `TransferredTicketRecordsTest.php` (3).
- Rewrote `AttendeePortalRouteSecurityTest.php` to exercise 25 routes against a hostile host.
- Updated `PlatformAttendeeHistoryTest.php`, `EventRegistrationTest.php`,
  `AttendeePortalHostTest.php`, `resources/js/test/setup.js`,
  `resources/js/test/portal/Portal.test.jsx`, `resources/js/test/portal/VerifyPrompt.test.jsx`.
- Frontend assets rebuilt (`npm run build`).

Nothing was committed or pushed; it is all in the working tree for review.

---

## F16 — Eight Plan A tests still asserted the old confirmation page

Found only by running the whole suite after the F6 fix, which is the point worth keeping.

**Problem.** Eight tests across five files asserted that
`/e/{event}/registrations/{registration}` returns 200 and renders portal props — the behaviour
F6 removed. They covered real guarantees: the portal payload shape, the masked address and
withheld phone number, private-event lineup gating by registration status, the conditional help
tab, and the "awaiting checkout" states.

**Why it matters.** These were characterisation tests written in Plan A precisely so the portal
could be restructured safely. They did their job — they failed the moment the contract changed.
The failure mode to avoid was deleting them as "obsolete": each one still describes a guarantee
that must hold, just at a different address.

**Resolution.** All eight were migrated to the canonical workspace with platform proof in the
session, keeping every assertion. Only the premises changed: the comment "Anyone holding the
link sees this page" became a note that the address is masked even for the person who proved it,
because the page is read on shared screens. Files: `AttendeePortalPageTest.php`,
`EventVisibilityTest.php`, `ServiceRequestTimingTest.php`, `EventRegistrationApprovalTest.php`,
`PlatformAttendeeWorkspaceTest.php`.

The checkout-grant test in `PlatformAttendeeWorkspaceTest` was the one case where an assertion
had to be **inverted** rather than moved: it asserted that an anonymous visit mints a grant,
which is F7. It now asserts the opposite, with a positive control that the address holder still
receives one.

**A second defect surfaced while fixing these.** `register()` returned a redirect to
`public.events.confirmation`, which now redirects to another origin. That response answers an
Inertia form submission, which follows redirects over XHR, so CORS would have blocked it and
every registrant would have seen a network error instead of their ticket. `register()` now
returns `Inertia::location()` straight to the workspace — the same fix, and the same reasoning,
that `EventCheckoutController` already carried a comment about. Worth remembering: **on an
Inertia POST, a cross-origin redirect is not a redirect.**

---

## Shipped

Pushed to `main` as `15226a2..673878c` on 2026-09-24 — 17 commits of the original work plus
seven of remediation. **CI passed for the first time on this body of work** (run 35981595015,
all five gates). Laravel Cloud deployment `depl-a2d25308` succeeded.

`ATTENDEE_PORTAL_IP_CHALLENGE_THRESHOLD=20` was set on the production environment before the
push, so the Turnstile challenge cannot fire ahead of the hard IP cap while the keys are absent
(F4). The remaining caps still apply: 20 sends per IP per 10 minutes, 100 per IP per day, 5 per
email per hour, 10 per email per day. **When `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are
set, delete this variable** so the challenge returns to the sixth request, as the spec intends.

Verified against production after deploy:

| Check | Result |
|---|---|
| `miconvener.com/my` | 200, renders `AttendeePortal/MyPortal` |
| `www.miconvener.com/my` | 301 → `miconvener.com/my` |
| `{tenant}.miconvener.com/my` | 302 → `miconvener.com/my?organiser={tenant}` |
| Cloud vanity domain `/my` | 404 |
| Nested host `a.b.miconvener.com` | refused |
| `/my/events/{uuid}` unauthenticated | 302 → `/my?return_to=/my/events/{uuid}` |
| Legacy confirmation, unknown id | 404 |
| Anonymous `/my` payload | `verifiedEmail`, `initialHistory` both null — the shell the service worker caches carries no history (F13) |
| `POST /my/verify/send`, known address | 202, code queued |
| `POST /my/verify/send`, unknown address | 202, byte-identical response — no enumeration |
| `POST /my/verify/confirm`, wrong code | generic 422 |
