# Public attendee profile implementation plan

> For agentic workers: execute natively with `superpowers:executing-plans`, task by task. Do not delegate implementation. Tick each step in the commit containing its work. Use one fresh review after the completed feature, focused local checks and one final CI gate.

**Goal:** let a verified adult attendee explicitly publish and remove their own event-specific display name and optional photo, without publishing anyone by default.
**Architecture:** existing Laravel13, React/Inertia, native verified-email/session guards, private storage and installed GD image support. One publication service owns consent mutations; one shared eligibility policy gates every public list/image read. Use existing event/ticket pages and staff console. No new dependency, payment path or Community prerequisite.
**Spec:** `docs/superpowers/specs/2026-11-02-public-attendee-profiles-design.md`; approved visual/privacy choices2November2026. Visual companion is the adjacent `-preview.html` file. Owner instruction3November2026 “Finish it” authorises native implementation under the approved design. Production pilot activation remains a separate gate.

## Global constraints

- Explicit per-event consent and adult self-confirmation; optional photo, chosen display name; no account/legal-name/photo prefill. No guardian or representative publication in this release.
- Original independently verified registration email, not a group payer or phone/checkout grant alone. One self-profile per normalised email/event. Do not weaken existing workspace access for other features.
- Published visibility ends at the earlier of event end+30days or consent+90days; open-ended/recurring events still have the90day ceiling. Exact deadline is expired. Withdrawal hides immediately; name/photo purged within24hours. Minimal pseudonymous consent evidence retained90days after withdrawal/expiry, then purged. No legal-compliance guarantee is implied.
- Private/unpublished/cancelled events, cancelled/refunded/transferred tickets, event/tenant feature withdrawal and moderation holds suppress public output immediately, independently of queue/scheduler health. Reactivation never republishes old consent. Existing anonymous Going count is unchanged.
- JPEG/PNG/WebP,2MiB maximum; no remote URLs, SVG or animation. Decode and re-encode, strip metadata, bounded dimensions/pixels and512px derivative. Use installed GD and verify production extension support before activation; fail clearly if unavailable.
- Public output contains only opaque profile/revision identifiers, chosen display name, initials and controlled derivative URL. No ticket code, raw registration ID, email, phone, membership, financial fields, storage paths or consent logs. Public data and images are no-store; no offline/service-worker/CDN profile cache.
- Rollout is explicit tenant flag `public_attendee_profiles`, absent=off, no plan inheritance; event switch default=false. Never auto-publish existing attendees. Purpledot activation needs its separate acceptance/go-ahead; implementing or deploying code is not activation.
- Preserve session blocking, CSRF, exact ownership, feature/permission boundaries, all existing event tabs/live features, financial idempotency and tenant isolation. Use the owned checkout and explicitly named disposable testDB only; no Claude databases or production test data.

## Review Focus

1. A shared or group-payer email must not become permission to publish other named attendees; at most one self-designated profile per verified email/event, with explicit self-confirmation.
2. A stale list/image URL after transfer, full refund, privacy change, moderation or flag removal must never reveal a previously public name/photo, even before cleanup runs.
3. Saving a draft or a failed image must not silently publish edits or lose the currently published choice; revised terms/content require fresh consent.
4. Recurring events, missing end times and edits that extend an event must never extend stored visibility beyond the original90day consent ceiling. Re-consent is explicit.
5. Refund/transfer writers and profile/photo cleanup must not acquire locks in opposite orders or add failures to existing ticket/payment operations. Design hooks and purge records without restricting historical financial deletion policies.

## File map

**Existing touchpoints:** `app/Models/{Event,EventRegistration}.php`; `app/Services/Events/{PlatformAttendeeVerification,PlatformAttendeeWorkspaceAuthorizer}.php`; `app/Http/Controllers/Public/{PlatformAttendeeWorkspaceController,PublicEventController}.php`; `routes/{attendee,subdomain,web}.php`; `app/Console/Kernel.php`; `app/Libraries/RolePermissions.php`; `database/seeders/PermissionsSeeder.php`; existing platform gate provider; `app/Services/Tenancy/StorageMeter.php`; private filesystem config; `resources/js/Pages/Public/Events/{Show,AttendeePortal/panels/MyTicketPanel}.jsx`; `resources/js/Pages/Tenant/Events/Show.jsx`; existing console navigation.

**New bounded units:** `app/Models/{EventAttendeeProfile,EventAttendeeProfileImage,EventAttendeeProfileConsent}.php` with factories and landlord migration; `app/Services/Events/Profiles/{ProfileEligibility,ProfilePublication,ProfileImages,ProfileRetention,ProfileRollout}.php`; attendee Form Requests/controller; public list/image controller; tenant moderation controller/request; platform moderation controller/request; tenant console panel; public list component; personal ticket editor; enable/disable and purge commands under existing command directories. Native-generate classes/models/requests/commands/tests with discovered Artisan options, no hand-created parallel framework.

## Task 1 — Persistence and rollout without publication

- [x] Add failing schema/rollout tests: absent/off flag, eventfalse, cross-tenant/context mismatch, duplicate registration and duplicate email/event profile, expired equality, protected image/consent fields and safe serialization.
- [x] Run targeted RED using `DB_DATABASE=miconvener_s2b_codex_testing` and PHP8.5. Never rely on default testDB, migration seed rows or another test's fixtures.
- [x] Native-generate landlord migration/models/factories. Profiles hold immutable owner-email HMAC, current consent/revision and published payload. Images retain durable pending/retired cleanup metadata even after profile deletion; never lose the only key needed to purge an orphan. Consent history is append-only minimal evidence, with an explicit expiry. No plaintext identifier/display-name/photo in audit snapshots.
- [x] Enforce tenant/event/registration consistency with native constraints; add needed composite unique indexes after checking existing schema. Avoid a restrictive foreign key/observer that blocks existing refund/transfer/event deletion. Keep cleanup metadata recoverable if those records are removed.
- [x] Implement explicit tenant/event rollout policy and native enable command; disabled code exposes no editor/public list. No plan entitlement or production activation.
- [x] Run schema/rollout tests, Pint, commit with these steps ticked.

## Task 2 — Verified self-consent and withdrawal

- [x] Add failing ownership/service/HTTP tests for unverified/expired proof, phone proof, checkout grant only, representative, wrong tenant, group payer/second profile, missing adult/consent, unsafe name markup, stale revision, duplicate operation and consent replay after withdrawal.
- [x] Implement `ProfilePublication::publish(...)` and `withdraw(...)` with exact current `PlatformAttendeeVerification::verifiedEmail` ownership plus current registration eligibility. Do not reuse the broader `canAccess` workspace grant as publication authority. No changes to how existing workspace features authenticate.
- [x] Audit registration transfer/refund lock paths before choosing mutation locks. Quota mutation uses tenant→registration/profile; no new nested opposite lock in a money writer. Re-read current eligibility while locked. Provider, mail and image HTTP calls never enter this path.
- [x] Publish saves chosen plain-text display name, adult self-confirmation, consentversion/time, immutable maximum expiry and random revision with append-only minimal evidence. Changing displayed content needs a new current consent; request-key replay returns the same result only while that exact publication still exists.
- [x] Withdrawal is idempotent, immediately removes public visibility/name, retires image metadata for cleanup and records its time. A concurrent transfer/cancellation cannot make it public again. Existing tickets/payments remain untouched.
- [x] Add native Form Requests/CSRF POST+DELETE routes under `/my/events/{registration}/public-profile`, explicit safe DTOs and private/no-store responses. Keep profile data out of initial QR/offline ticket snapshots.
- [x] Run focused tests and existing ticket/portal identity regressions, Pint, commit.

## Task 3 — Safe optional photo and bounded storage

- [ ] Write RED tests using synthetic image bytes for actual decoding, JPEG/PNG/WebP success, malformed/mislabelled input, SVG/animation/remoteURL rejection,2MiB boundary, excessive pixel allocation, metadata removal, failed writes/rollback, replacement and orphan cleanup.
- [ ] Reuse the installed GD approach demonstrated by `ArtifactArtworkService`; do not broaden that service's badge namespaces or bypass image limits. Set input bounds before decoding (maximum6000px each axis and12million pixels), re-encode a square512px derivative, verify output and store privately under a profile-specific namespace. No new dependency.
- [ ] Bind each pending image to exact tenant/registration/proof context with a short-lived opaque upload identifier. Only the current owner may attach it; abandoned/replaced/withdrawn originals and derivatives retain durable cleanup rows. Do not trust a filename, browserMIME or a client-provided storage path.
- [ ] Include physically retained profile-image bytes in `StorageMeter`, alongside existing tenant assets/merchandise/Library editions. Apply the existing preserved allowance under tenant lock before storing; only charge stored derivative bytes and account for pending/retired objects until deletion is confirmed. A full cap never blocks withdrawal/reading or text-only publication.
- [ ] Verify installed image support read-only on production before pilot readiness. Missing support leaves the feature off and yields a clear error; never silently store unprocessed originals.
- [ ] Run photo/storage/Library/merch-media affected tests, Pint/static checks, commit.

## Task 4 — Public privacy boundary and retention

- [ ] Add RED public-read tests for no consent, private/unpublished event, cancelled/refunded/transferred ticket, wrong ownerHMAC, exact expiry, revoked flags, hidden profile, stale image revision and missing stored derivative. Check the existing Going count remains identical.
- [ ] Implement one `ProfileEligibility` policy used by public list AND image endpoints. Query bounded public DTOs separately from the cacheable event payload; initial Show props contain no attendee names. First list limit6, expanded cursor pages25, no email search or cross-event directory.
- [ ] Every list/image request rechecks event/registration/consent/revision. Registration changes must invalidate old consent permanently (generation/revocation evidence) so restoring eligibility cannot resurrect it. Use after-commit cleanup for files, never let cleanup failure fail a paid refund/transfer. Reads fail closed immediately even while jobs wait.
- [ ] Add no-store/private cache headers as appropriate, `nosniff`, controlled image content type, and explicit service-worker/CDN bypass. No URL includes a ticket code, buyer email or storage key. Public images must return404 after removal.
- [ ] Add scheduled hourly bounded `ProfileRetention` sweep with durable claim/retry semantics. Queue deletion immediately and retry hourly; alert if any retired object remains beyond24hours. Clear bytes only after storage deletion is confirmed. Expire public profiles at exact stored deadlines; cleanup never decides visibility. Purge minimal consent records at90days after end of publication.
- [ ] Test retries, missing objects, interrupted storage deletion, transfer/privacy flip during reads and proof expiry. Reuse real-PostgreSQL worker helpers for publish versus withdraw/transfer and concurrent quota writes. Only the owned isolated DB is allowed.
- [ ] Run targeted public/portal/storage and worker tests, Pint/static checks, commit.

## Task 5 — Native editor public list and moderation

- [ ] Add failing Vitest cases for initially-private state, disabled feature, no prefill, optional photo/initials, missing consent/adult confirmation, draft versus published content, expiry/reverification, publish retry, immediate removal, keyboard focus and long names.
- [ ] Build editor within My ticket after existing actions, with preview and independent unchecked consent/adult controls. Existing registration, payment, transfer, PDF, check-in, certificate and explicit offline-save handlers remain intact. A failed optional upload leaves the current published choice unchanged; clear saved draft/private data on signout.
- [ ] Add the small consenting-profile strip next to existing Going information in `Show.jsx`, with labelled “See public attendees” expansion. Render names as text; graceful empty/error state does not block programme, live polls/Q&A/surveys or tickets. No fake headshots or automatic publication.
- [ ] Add tenant `manage public attendee profiles` and separate platform `moderate public attendee profiles` permission to seeders/RolePermissions/gates together. Initially Org Superadmin and platform Superadmin respectively; platform permission is never TENANT_SAFE. Test direct controller calls as well as middleware routes. Existing removed grants must stay removed on reseed.
- [ ] Expose event-level profile switch and Hide action from the event console only with the tenant permission/flag. Hidden profiles require a separately authorised platform moderation hold release, followed by fresh attendee consent; no staff publish/edit action. Platform moderation navigation must be discoverable under the existing superadmin console.
- [ ] Check desktop/actual390px, both themes,200% text and keyboard with synthetic profiles. Measure actual viewport; do not claim resizing/zoom succeeded if the tool ignored it. Record any device-only limitations separately.
- [ ] Run focused JS plus portal/event component suites, scoped lint, Pint and rebuild committed `public/build`; commit.

## Task 6 — Final review and separate pilot gate

- [ ] Re-read complete diff and literally apply post-implementation self-review: all entry points, policies, seeders/navigation, DTO/cache/image privacy, cleanup, migration reversal, empty/error/loading/mobile/keyboard and money/identity boundaries. Fix obvious gaps with regressions.
- [ ] One fresh independent whole-change review. Fix critical/important findings, record any minor coverage limitations without repeating whole reviews. No unapproved dependency or financial flow changes.
- [ ] Integrate current main, rebuild generated conflicts, verify source comparison, open focused PR with concrete validation, require final-head checks and owner merge/deploy instruction within standing release scope. Never bypass CI/protection or mislabel synthetic/test-mode checks as real attendee consent.
- [ ] Verify schema, permission propagation, queue/scheduler, private storage and all flags read-only after deployment. Keep production profile flags off until the Purpledot pilot acceptance/go-ahead. No actual attendee is opted in by staff or a migration.
- [ ] Present a synthetic end-to-end demo and exact owner pilot decision. Update parent plan, Community follow-ups and deployment evidence in the same release record. Keep real-phone/offline accessibility gates independent; this feature never caches profiles offline.

Task1 foundation:19schema/rollout tests35assertions passed; scoped PHPStan0errors and Pint passed. RED cases included inactive organisation, incomplete/null deadlines, cross-organisation image context, early consent deletion and forged caller switch. No production flag/schema changed. Database privacy generations also cover tenant suspension and raw event/registration/feature updates.
