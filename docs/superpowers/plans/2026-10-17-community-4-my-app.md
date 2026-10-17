# Community milestone 4 — `/my` app implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans for native implementation, task by task; use superpowers:subagent-driven-development only if the owner requests it. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** In progress. Owner approved native implementation, inbox deferral and ticket-only offline behavior on 2026-10-17.

**Goal:** Make `/my` a coherent, accessible personal app across Home, Tickets, Communities, Money and Me, retaining every existing attendee capability and milestone 3 identity safeguard.

**Architecture:** Keep React/Inertia and the platform-host `/my` entry. Add an app shell around existing panels and identity-gated read models; keep canonical event workspaces at `/my/events/{registration}`. Reuse payment, verification, member-access, preference and receipt services; this milestone does not rewrite money or event rules.

**Tech Stack:** Existing Laravel 13, React 19, Inertia, Tailwind 4 semantic tokens, Lucide, Pest and Vitest; existing PWA manifest, worker and offline ticket store. No new dependency without approval.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md` §§2, 5.3, 6.1–6.3, 8, 10, 13.1. Roadmap: `docs/superpowers/community-roadmap.md`, milestone 4 brief.

**Baseline:** `main` at `550ede1`; event discovery PR #31 head `ed920814212a8d9b75c9c32b6595661358291f23` is reviewed, CI green and ready for owner review, **not merged**. This planning branch includes that head. Start implementation from main after #31 merges, or explicitly retain it as a dependency; recheck the diff before a PR so #31 changes are not presented twice.

**Preview:** `community-my-app-preview.html` in this chat's visualization directory. Illustrative people, organisations, prices and ticket examples are labelled sample data; they are not live accounts or bookings. The preview guides layout, not authorization or a new financial model.

## Global Constraints

- Phone: bottom tabs **Home, Tickets, Communities, Money, Me**; desktop: the same sections in a left rail, with list/detail columns.
- “No person table.” Identity remains independently proven session identifiers; never derive a universal profile or merge records by phone, name or an inferred email/phone relationship.
- “Email first.” Existing email and phone proofs remain non-sliding **12-hour** proofs; no new remembered-device identity or automatic proof renewal.
- Phone proof alone grants no historic member, ticket, contribution, abstract, certificate, receipt or message access. Use `MemberAccess` for each member and existing platform event authorization for each ticket. Representative views must name the represented member and role.
- “Only ticket passes are cached for offline use.” No financial histories, membership lists, messages, private documents, credentials or authenticated JSON/HTML in service-worker caches.
- All personal caches clear on sign-out and when the session identifiers change; current contacts/grants are rechecked server-side on every protected request/download.
- Money stays integer minor units grouped by currency; no wallet, spendable balance, transfers, new commission logic or inline fee arithmetic. Ledger and payout invariants in AGENTS.md remain binding.
- Preserve `/my?organiser=…`, `?tab=certificates|abstracts|attendance|contributions`, `#communities`, safe `return_to`, `/my/events/{id}?tab=…`, `/pay/{token}`, receipts and all existing verification/check-out grants.
- Events remain in Events; library and organisation polls are milestone 7; campaigns/funds and contribution cutover are milestone 5; member ticket prices are milestone 6.
- Use MiConvener tokens, light/dark mode, square public-app controls, 44px minimum touch targets and safe-area space. Ticket-pass decoration may use subtle rounded/perforated presentation; action controls stay consistent. Do not introduce a second branding system.
- Native execution with one whole-change independent review; focused checks per task and one required milestone release gate. Do not overlap database-refreshing suites. Local full PHP suite uses two workers.
- Rebuild and commit `public/build` with each JS/CSS implementation commit, use `pint --dirty`, tick plan steps in the same commit and update roadmap status at state changes. Do not merge/deploy/change production without owner approval; after an approved deploy, Purpledot pilot activation is already authorized, other tenants unchanged.

## Owner review decisions

1. **Recommended scope adjustment: no in-app unread inbox in milestone 4.** Spec §6.2 mentions unread messages on Home. Current `CommunityMessageDelivery` is an outbound delivery log, not an authorized inbox/read-receipt model; phone delivery addresses must not become historic private-message credentials. Show “Messages arrive by email and SMS” and per-organisation preferences, with **no unread badge**, until a separately designed inbox is approved. Owner approved this exception on 2026-10-17; spec §6.2 is amended in the first implementation commit. Do not invent counts from delivery logs. Deferral avoids a separate inbox subsystem, migration and phone-reassignment review.
2. **Ticket-only offline restriction approved 2026-10-17.** The current store also saves a personal agenda and may use `virtual_link` as its venue summary. Task 5 narrows saved records to ticket passes, physical venue/online-event label and QR only; programme/meeting links remain available online. No personal agenda, virtual access URL or private document is persisted. This implements §6.2 but changes the old offline-agenda behavior visibly.

No new giving, membership-price or SMS rollout decisions are needed for this plan. Existing live OTP/private-ticket acceptance and phone-entry readiness remain tracked in milestone 3; app work must not silently enable phone entry.

## Review Focus

1. Shared device / changed verified identifier: previous tickets must disappear from screen, memory, local storage and worker caches, including another open tab (tasks 1, 5).
2. Phone-only or representative session: no inferred email history; grants are checked per member, revoked access disappears, representative labels persist through Money (tasks 3, 4).
3. Existing URLs and outstanding payments: old links reach the same protected destination, and pending/cancelled/waitlisted tickets never become usable passes (tasks 1, 2).
4. Large histories / mixed currencies / missing images: lists paginate without dropping an organisation beyond page 1, currencies are not added together, broken media has a real fallback (tasks 2, 4).
5. Offline / expired proof / 200% text: only saved pass content works offline, financial actions stay disabled, re-verification returns to the intended activity and navigation never clips (tasks 5, 6).

## File map and existing behavior to retain

- `resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx`: currently combines verification, history, Communities and account controls. Extract incrementally; do not replace it wholesale.
- `…/AttendeePortal/Portal.jsx` and `panels/*`: retain My ticket, My day, polls, Q&A, forms/evaluations, help, downloads, self-check-in, transfer, certificates and attendance. PR #31 activity entry must survive.
- `…/components/{NeedsAttentionSection,LiveNowSection,OrganiserEventsSection,CertificatesSection,AbstractsSection,AttendanceSection,ContributionsSection,EditTributeModal,HistorySkeleton}.jsx`: reuse, adapting composition rather than discarding features.
- `resources/js/Pages/Public/Community/{CommunitiesPanel,PhoneVerify,RecoveryCredential,MemberClaim}.jsx`: preserve follow/unfollow, join/claim/recovery, wrong-number reports and represented-member distinctions.
- `app/Services/Events/PlatformAttendeeHistory.php`: existing email-bound event/history readers. Add safe image/organiser metadata here; do not widen who can read them.
- `app/Services/Community/{CommunityHistory,MemberAccess,Following,MessagingPreferences}.php`: use existing identity and consent boundaries. Do not make private member-query helpers public for unguarded reuse.
- `app/Http/Controllers/Public/{PlatformAttendeeAccessController,PlatformAttendeeWorkspaceController,MyCommunitiesController}.php`, `routes/attendee.php`: preserve existing endpoints; new readers live under the same platform host and middleware conventions.
- `resources/js/lib/{offlineTicketStore,registerServiceWorker}.js`, `resources/js/workers/attendee-sw.js`, existing manifest and `resources/js/Pages/Public/Events/AttendeePortal/panels/MyTicketPanel.jsx`: privacy-limited offline upgrade.

## Task 1 — App shell, route compatibility and pilot switch

**Files:** Modify `MyPortal.jsx`, `PlatformAttendeeAccessController.php`, `CommunityHistory.php`, `routes/attendee.php`; create `…/AttendeePortal/components/AttendeeAppShell.jsx`, `resources/js/test/portal/AttendeeAppShell.test.jsx`, `tests/Feature/Community/CommunityAppRolloutTest.php`, `app/Console/Commands/Community/EnableCommunityAppCommand.php` (Artisan generators for PHP files).

**Interfaces:** `AttendeeAppShell({ section, onSectionChange, children, detail, offline })`; section is exactly `home|tickets|communities|money|me`. `CommunityHistory::usesAppShell(Session $session, ?string $organiserSlug): bool` checks an explicitly selected active pilot tenant or an actually authorized organisation with `community_app` enabled. It must not infer memberships from phone or inspect only the first paginated page. Controller supplies `app_shell_enabled`; legacy UI remains behind the off switch. Command `community:enable-app {tenant} {--off}` follows `EnableFollowingCommand`'s idempotent feature row/cache invalidation pattern; no plan price changes.

- [x] Add failing shell/rollout tests: exactly five sections and keyboard-labelled navigation; 320px bottom navigation and desktop rail; an off-switch tenant keeps old layout; a pilot organisation beyond page 1 can enable the shell without exposing its records; mixed-rollout users keep all already-authorized records.
- [x] Add URL tests: `section=…` restores selected section; legacy `tab=certificates|abstracts|attendance` maps to Tickets > Records, `tab=contributions` to Money > Contributions, `#communities` to Communities; organiser filter and safe `return_to` survive. `/my/events/{id}?tab=forms` still reaches the existing verification/deep-link flow. Invalid sections fall back to Home.
- [x] Run `npm test -- resources/js/test/portal/AttendeeAppShell.test.jsx` and `php artisan test --compact tests/Feature/Community/CommunityAppRolloutTest.php`; confirm intended failures.
- [x] Implement shell, history-aware switch and compatibility adapter. Use `section` rather than overloading workspace `tab`. Greeting must not infer a global name from an arbitrary registration; default “Good morning.”/appropriate local greeting. Do not change canonical workspace URLs.
- [x] Rerun the task tests and existing `AttendeePortalHostTest`, `PlatformAttendeePortalHostTest`; format/build, tick steps and commit.

## Task 2 — Home and Tickets, without losing attendee records

**Files:** Modify `PlatformAttendeeHistory.php`, `PlatformAttendeeAccessController.php`, `routes/attendee.php`, `MyPortal.jsx`, `components/LiveNowSection.jsx`; create `app/Http/Requests/Attendee/AppEventsRequest.php`, `components/AttendeeHome.jsx`, `components/AttendeeTickets.jsx`, `components/TicketPass.jsx`, `resources/js/test/portal/AttendeeTickets.test.jsx`; extend `tests/Feature/Events/PlatformAttendeeHistoryTest.php` and `resources/js/test/portal/Portal.test.jsx`.

**Interfaces:** Preserve the legacy history API and its response keys. Add `PlatformAttendeeHistory::getAppHistory(string $emailNormalized, array $filters): array` and verified-email-only `GET /my/app/events` with validated `{period:upcoming|past, organiser?:string, page?:int}`. It returns `{needs_attention, has_more_attention, live_now, passes:{data,meta,links}}`: passes are paginated 20 at the SQL query, Home attention is capped at 10 with a visible link to the full filtered list, and real current events are capped at 10 with a Tickets link. The app shell fetches this reader rather than loading every legacy history inline; legacy shell keeps its current reader. Each event entry gains `hero_image_url: ?string` and organiser `logo_url: ?string` using existing storage URL helpers. `AttendeeHome({ history, duesSummary, onSectionChange })`; `AttendeeTickets({ history, records, offlineTickets })`; `TicketPass({ registration, event, onOpen, savedOffline })`. A pass's QR/details still come from the authorized workspace/ticket endpoint, never from anonymous event metadata. A checked-in event that ended is Past, not permanently Live. Do not return all registrations and paginate the array in the browser; test SQL-backed pagination over more than 20 registrations.

- [x] Add failing tests for confirmed/pending/checked-in/waitlisted/cancelled states; no usable QR for unconfirmed tickets; private unconfirmed registrations reveal no new hero/private venue; image fallback; upcoming/past filters; stable order; stale “live” classification after event end.
- [x] Add navigation tests reaching existing Certificates, Abstracts, Attendance, contribution tributes/receipts, speaker tools when available, programme and PR #31 activities. Home action cards resolve to their existing endpoints, including unfinished checkout; no new payment call is made merely by opening a tab.
- [x] Run new tests plus `PlatformAttendeeHistoryTest` to observe failure; implement safe metadata and composition with existing panels. Ticket actions include view/check-in/transfer/save only when existing eligibility permits. Feature labels reflect unavailable/offline states.
- [x] Rerun history and attendee portal tests, format/build, tick and commit. Do not implement an unread message badge unless owner decision 1 has been resolved with an approved inbox design.

## Task 3 — Communities and Me: keep identity boundaries visible

**Files:** Modify `MyPortal.jsx`, `CommunitiesPanel.jsx`, `PlatformAttendeeAccessController.php`, `routes/attendee.php`; create `components/AttendeeMe.jsx`, `components/AttendeeCommunities.jsx`, `app/Http/Controllers/Public/AttendeePreferencesController.php`, `app/Http/Requests/Attendee/UpdateAttendeePreferenceRequest.php`, `tests/Feature/Community/AttendeePreferencesTest.php`, `resources/js/test/portal/AttendeeMe.test.jsx`; extend `MyCommunitiesPrivacyTest.php`, `MemberAccessRevocationTest.php`, `communityReviewBoundaries.test.jsx`.

**Interfaces:** Reuse `/my/identity`, `/my/communities`, existing member/charge/claim/recovery/follow routes. Add `GET /my/preferences`, `PATCH /my/preferences/{tenantSlug}` under `community.identity` + existing access throttle; payload `{channel: email|sms, marketing_enabled: boolean, resume_marketing: boolean}`. Resolve the address from current verified session, **never from submitted address/member ID**. `GET` returns only preference rows for authorised/followed organisations and independently proven channels. Add `DELETE /my/identity/email` to forget email proof and clear event checkout/session-registration grants, retaining independently valid phone proof; preserve existing phone-forget and full-sign-out endpoints. Every identity change invalidates all personal client snapshots before showing the replacement identity.

- [x] Write failing tests: unverified secondary contact is not displayed or subscribed; bare phone cannot see old memberships; represented-member label survives navigation; contact/grant revocation blocks refreshed detail and download; exact-email and explicit member-grant rules hold across two organisations.
- [x] Test preferences cannot change another address, organisation or unverified channel; marketing opt-out never disables receipts/sign-in/service notices; resuming announcements requires explicit consent and passes existing `Following`/`MessagingPreferences` logic, including held-number safeguards. No OS push permission prompt or imaginary push subscription.
- [x] Test full sign-out and separate identifier removal preserve only independently valid proof, immediately discard private UI/cache and do not retain event checkout grants after removing email. Existing recovery credentials remain one-time-display and are never stored by the app.
- [x] Observe failures; implement app composition, Me and bounded preference endpoints using current services. Communities show logo/name, Following/Pending/Member/Representative statuses and explicit join/recovery next steps, with paginated lists. Phone-only users see an email verification CTA for event records, not a misleading empty ticket history.
- [x] Rerun listed tests, format/build, tick and commit. No changes to phone entry rollout or SMS caps.

## Task 4 — Money: authorised dues, existing giving and statements

**Files:** Modify `PlatformAttendeeHistory.php` for an email-scoped paginated contributions reader; create `app/Services/Community/MyMoneySummary.php`, `app/Http/Controllers/Public/MyMoneyController.php`, `app/Http/Requests/Attendee/MyMoneyRequest.php`, `resources/js/Pages/Public/Events/AttendeePortal/components/AttendeeMoney.jsx`, `resources/views/pdf/community-member-statement.blade.php`, `tests/Feature/Community/MyMoneyTest.php`, `resources/js/test/portal/AttendeeMoney.test.jsx`; modify `routes/attendee.php`, `CommunityHistory.php`, `MyCommunitiesController.php` and `MyPortal.jsx` only as needed to reuse bounded readers.

**Interfaces:** `MyMoneySummary::for(Session $session, array $filters): array` supports validated `{tenant_slug?:string, member_id?:uuid, currency?:string, page?:int}`; `GET /my/money` under `community.identity` returns paginated `{dues:{data,meta,links}, totals_by_currency, contributions:{data,meta,links}}`, each member row with `{tenant_slug, member_id, member_name, access_role, currency, open_total, pay_url}`. Add `CommunityHistory::moneyMembers(Session $session, array $filters): LengthAwarePaginator` as an **internally authorized** paginated reader using its existing private `memberQuery()`, eager-loading tenant/type and summing open charges. Do not enumerate only Communities page 1 or expose a raw unguarded builder. Add `PlatformAttendeeHistory::getAppContributions(string $emailNormalized, array $filters): LengthAwarePaginator`, sharing the existing `getContributions()` ownership/query and resource mapping but paginating 20 in SQL; keep its legacy API unchanged. Contributions are fetched only for current verified email; phone-member grants do not widen event/giving history. Add `GET /my/communities/{tenantSlug}/members/{member}/statement?from=YYYY-MM-DD&to=YYYY-MM-DD`, with `MyMoneyRequest` validating required statement dates, date order and a maximum 366-day range, and per-request `CommunityHistory::member()` authorization; response `private, no-store`, read-only dues statement.

- [x] Add failing tests: two organisations, shared phone, self versus representative grant, changed contacts, mixed currencies, later pagination, paid/cash/open/waived/void charges; no cross-currency total and no cash-backed “available balance.” Blank and zero totals have distinct honest states.
- [x] Test dues payment links and existing paid-charge receipts remain exactly the existing public-pay/receipt contracts; a pay token never grants `/my` access. Statement contains only the authorized member's recorded charges/payment states in the validated period, labels date/currency and never recomputes commission or changes balances.
- [x] Test contributions/receipts/tribute edits still require their original verified-email proof; grant-only phone sessions cannot fetch those histories. Requests from expired/revoked grants are rejected before response/download.
- [x] Observe failures, implement the read models and UI using existing payment/receipt components. Tabs: Dues, Contributions & receipts, Statements. Label giving as existing event contributions until milestone 5 introduces funds/campaigns; no invented pledge, wallet or transaction capability.
- [x] Rerun new tests plus `MyCommunityReceiptsTest` and relevant existing contribution/history tests; format/build, tick and commit. Money writes/terms/ledgers remain untouched.

## Task 5 — Ticket-only offline access and identity-safe cache lifecycle

**Files:** Modify `offlineTicketStore.js`, `registerServiceWorker.js`, `workers/attendee-sw.js`, `MyPortal.jsx`, `Portal.jsx`, `panels/MyTicketPanel.jsx`, existing manifest method in `PlatformAttendeeAccessController.php`; create `resources/js/lib/attendeeIdentityCache.js`, `resources/js/test/portal/offlineTickets.test.js`, `resources/js/test/portal/attendeeWorker.test.js`, `tests/Feature/Events/AttendeePwaShellTest.php`.

**Interfaces:** Offline schema v3 whitelists `{schema_version, owner_key, proof_expires_at, registration_id, attendee_name, event_name, organiser_name, event_dates, physical_venue_summary, ticket:{code,type,status,seat}, qr_image, saved_at,last_confirmed_at}`. `owner_key` is an opaque session-proof-bound cache partition, not a raw email/phone or a bearer authentication credential. Retain `saveOfflineTicket/getOfflineTicket/removeOfflineTicket/clearAllOfflineTickets/purgeExpiredOfflineTickets` call sites, adapting signatures through an explicit optional identity context; legacy snapshots without v3 identity metadata are deleted, never assigned to the next user. Add `listOfflineTickets(context)` for Tickets > Saved. Client cache module supplies `reconcileAttendeeIdentity(context)` and `clearAttendeeIdentity()` with cross-tab logout/change notification. Context has explicit `verified`, `signed_out` and `unknown_offline` states: an anonymous cold offline shell is not evidence of sign-out. It may open only the last locally selected partition before its saved proof expiry; online server identity is authoritative on reconnect. Never adopt an old owner partition for a newly verified identity.

- [x] Add failing whitelist tests proving no agenda, notes, virtual access link, email, phone, money, membership lists, documents, recovery secret or session credential is persisted. Malformed JSON and invalid timestamps/statuses fail closed; unconfirmed/cancelled/expired snapshots cannot become passes.
- [x] Test A → B sign-in on a shared device, removal/change/expiry of either proof, logout in another tab, revoked/changed ticket on online refresh and expired retention. An offline pass may be shown only within its recorded proof-expiry and existing retention limits; label last verification and require connection after expiry. Offline mode cannot prove current revocation or perform check-in/transfer/vote/payment.
- [x] Test worker behavior for `/my`, `/my/events/{id}`, private GET JSON, receipts/PDF, Money, member lists, messages and material URLs. Only an anonymous app shell and versioned public static assets are cacheable by the worker; ticket pass records live in the explicit partitioned store. Include hashed bundle/CSS dependencies from Vite manifest, not only logos; never precache authenticated Inertia responses. A forbidden/network API result is never replaced with another user's cached response.
- [x] Observe failures; implement v3 migration/purge and explicit cache ownership, worker cache-version bump/deletion, usable cold offline shell and an update/reconnect affordance. Fix late registration of the worker when the window load event has already fired; no new packages. Use no-store on all new authenticated readers and the signed-in app HTML.
- [ ] Run offline/worker/PWA tests and existing ticket/transfer/sign-out suites; browser-test save → network disabled → cold reload → view pass, then sign-out/change identity/reconnect and verify clearing. Format/build, tick and commit.


## Task 6 — Acceptance, one whole-branch review and controlled rollout

**Files:** Tests above; `docs/superpowers/community-roadmap.md`, this plan, relevant existing acceptance notes. No unrelated refactor or new feature.

- [x] Add/run interaction tests for skeleton/empty/403/404/429/500/retry/offline states; visible focus, keyboard dialog dismissal/focus return, labelled QR/action buttons and status announcements; retry preserves form inputs. Verify slow responses from identity A cannot repopulate state after switching to B (abort/version requests).
- [ ] Verify 320px, 390px and desktop list/detail navigation, bottom safe-area spacing, 200% text/reflow, light and dark contrast (measure text/action pairs; if the existing accent pair fails AA, add a scoped accessible app-action token in `resources/css/app.css` rather than silently claiming compliance or recolouring the whole site); both layouts expose all existing ticket, abstract, certificate, attendance, tribute, speaker, material, community recovery and payment actions. Check deep link/browser Back and link sharing without leaking QR/session/recovery secrets.
- [ ] Run scoped checks per task, then once for milestone release: complete PHP suite with **two local workers** on disposable testing databases, `pint --dirty`, PHPStan zero with unchanged baseline, full JS suite with bounded workers, lint and build; `php artisan finance:verify-categories` must exit 0 and `PayoutOtpFlowTest` remains green. Do not share databases or overlap full refreshing suites. CI uses its normal four-worker disposable service. Preserve output/evidence; do not repeat successful full gates without changes/findings.
- [x] Perform the AGENTS.md and post-implementation Self-Review Report, then **one** fresh whole-branch review; fix concrete findings and rerun affected checks, not a loop of full reviews. Record unverified acceptance honestly.
- [ ] Let the owner click through phone and desktop. Update roadmap to `built` only after the agreed acceptance/decision criteria pass; commit rebuilt assets and checked plan steps together. Open review PR, do not merge/deploy automatically.
- [ ] After explicit merge/deploy approval: verify exact deployed SHA, existing event/payment routes and cache-version upgrade, enable `community_app` only for Purpledot using the approved standing instruction and confirm readback. Leave other tenants and phone/SMS readiness switches unchanged. Roll back presentation via `community:enable-app purpledot --off` with no data deletion; retain legacy verification/payment routes and never restore unsafe v2 personal caches.

## Plan self-review

- Covers five sections, desktop/mobile, accessibility/states, identity boundaries, existing records/actions, Money read-only surfaces, offline boot/cache clearing and independent rollout.
- Explicit proposed deviation: unread inbox; requires owner approval and spec update before implementation. No fake unread count or automatic phone-history access.
- Existing offline agenda/virtual link storage conflicts with the newer ticket-only boundary; the planned v3 purge and visible online-only messaging address that conflict, pending owner review of the behavior change.
- No future Giving/Library/member-price feature is silently pulled into milestone 4. Existing giving history and all legacy records stay accessible.
- Reviewed for source paths/interfaces, privacy, paginated/mixed-currency input and empty/revoked states. Tests and release gate are bounded to avoid duplicate long review/test cycles. Implementation is on `codex/community-4`. Tasks1–5 are implemented; the browser/owner acceptance and full final CI gates remain open.


## Self-Review Report — 2026-10-17

### Touchpoints updated

- [x] Navigation: the five-section app is selected behind `community_app`; current event-only email owners, members and followers of a pilot can reach it at canonical `/my`. Legacy layouts and workspaces remain available.
- [x] Tests: focused ownership, identifiers, preferences, mixed-currency dues, statements, PWA scope/cache, delayed responses and recovery, legacy sign-out broadcasts, loading/error and filter tests. All existing feature tests were included in the local release run.
- [x] Build: rebuilt `public/build` committed with frontend work. Worker caches use the manifest build hash; `/my` and nested routes share scope and old registration migration.
- AGENTS.md/memory: no new global convention or deployment rule; milestone decisions and continuation state are in this plan, spec and roadmap.
- Seeders/permissions: not applicable; no new staff permission or onboarding entitlement. The idempotent command creates only the requested tenant feature row. No organisation has been activated by this implementation.
- Config/.env/dependencies: not applicable; no new environment variable, package or infrastructure service.

### UI Gap Analysis

- Discoverability: Home, Tickets, Communities, Money and Me are visible on desktop rail/mobile bottom navigation. Full event workspace links preserve programme, speakers, polls, Q&A, surveys, materials, ticket transfer and payment flows. Certificate/abstract/attendance readers and contribution receipts/tribute editing remain reachable.
- States: Money/Me now distinguish loading/error from successful empty data; Phone-only Home retains authorised dues while event records request independent email proof. Existing verification/rate-limit/retry flows remain.
- Access: every new private reader is session-authorised and private/no-store. Current membership grants are rechecked for statements/receipts; no phone-to-email inference or pay-token-to-identity access was added.
- Offline: v3 stores only explicit issued ticket passes, bound to opaque proof identity and expiry. No agenda, virtual link, financial history, document or recovery secret is stored. Authoritative identity changes, legacy sign-out/removal and cross-tab events clear private UI and snapshots. Unknown offline boot probes the server before accepting its online status; no redirect loop based solely on navigator.onLine.
- Accessibility checked: anonymous actual app at320px,390px and1280px; no horizontal overflow. Mobile five-item nav targets about59x63px at320px; Me controls44px. Light accent corrected within this app to #007367 (5.76:1 on white), dark action #00c8b7/#042522 (7.69:1). Existing modal keyboard/focus tests retained.
- **Still unverified:** signed-in responsive flows, actual phone/desktop cold network-disabled boot and200% text reflow. The browser tool has no network-emulation API. Local QA flag activation was blocked by automatic approval review and is awaiting owner approval; no workaround or production change was made. Do not claim WCAG certification or owner acceptance.

### Independent review and fixes

One fresh reviewer reported no Critical or Minor findings and10 Important findings. All were accepted and covered by a single regression/fix pass:

1. Worker scope includes canonical `/my`; allowed header/manifest align and old `/my/` attendee registrations retire.
2. Cross-tab expiry/sign-out and owner changes clear Me identity/preferences as well as tickets and Money.
3. Existing legacy offline-clear call sites now clear/broadcast the shared identity lifecycle.
4. Credential callbacks capture the originating identity generation; late recovery secrets are discarded.
5. Preferences read actual marketing permission, not follower-row existence; explicit member resume works with Following off and keeps held-number/advisory-lock safeguards.
6. Event-only pilot owners are included by current verified email, excluding deleted events and cancelled/rejected registrations.
7. An offline shell stays usable when the browser reports online but server requests fail; authoritative server responses control reconnect.
8. Home always loads Upcoming independently of the Saved/Past Tickets filter.
9. Phone-only members/representatives retain Home’s authorised dues attention cards; event proof remains email-only.
10. Money and Me render loading/failure states before deciding a history is empty.

Evidence: all targeted regressions were observed failing, then passing. Full JS364/364; focused PHP36/36 (190 assertions), including payout OTP; PHPStan0 with baseline unchanged; lint, Pint, build and read-only finance category verification passed. Local full PHP ran2895 tests/14019 assertions:2894 passed, one old static worker-file assertion errored; its dynamic response contract was corrected and its PWA suite is green in the focused gate. Final full remote CI is required before merge; no second independent review was dispatched.

### Recommended for user decision

- Approve only local `community-qa` activation if signed-in browser acceptance should continue here; the existing Purpledot production standing instruction is retained for an approved deployment.
- Review on phone and desktop, including real offline save/reload and text enlargement, before marking the milestone built.
- In-app inbox is intentionally deferred. A reminder `in-app-inbox-follow-up` exists, quiet until milestone4 is deployed. Claude’s useful parallel scope is a separate inbox specification (recipient authorisation, email-first/shared-number safeguards, read/unread, retention), not this branch’s shared source files.

### Not gaps and rulings

- No new dependencies, fee/ledger/payment writes, permissions, SMS enablement or production changes are required.
- The original event workspaces remain the canonical full-feature destination; the new ticket detail reuses the existing panel. This avoids duplicate payment/activity implementations; their old URLs retain their contracts.
- Money exposes totals per recorded currency rather than one membership-currency total; otherwise historical mixed-currency charges would be misrepresented. Legacy payloads are unchanged.
- Anonymous shell and public hashed assets are the only worker cache content. Personal passes live in the explicit partitioned store. Real browser acceptance remains a gate because unit/source tests cannot establish a complete device experience.
- Deferred minors: none from the independent review. Unverified acceptance is reported explicitly rather than treated as a passed check.
