# S1c collection and handover implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans for the owner's native approach. Implement task-by-task, test first; steps use checkboxes. One fresh whole-branch reviewer after implementation, not per-task agents.

**Goal:** Complete verified, whole-order merchandise handover with auditable preparation, short-lived collection passes and refund-safe collection.

**Architecture:** Extend the deployed StoreOrder fulfilment lifecycle; do not create event registrations or a second stock/finance ledger. Canonical tenant/order locks serialize pass, preparation, handover and existing refund claims. Keep buyer email ownership and staff collection permission independent of dues, Community and payment permissions.

**Tech stack:** Laravel 13 / PHP 8.4-compatible code, PostgreSQL landlord database, React/Inertia and existing Tailwind tokens, native email proof, Pest/Vitest, existing BaconQrCode renderer and html5-qrcode camera. No dependency additions.

**Spec:** `docs/superpowers/specs/2026-10-24-commerce-s1c-collection-design.md` (read first).

**Status:** plan/preview proposal only, based on deployed `7a90c66c` / PR #50. No application implementation, new production configuration, payments or sends authorised by this document. Review the bearer/sharing policy and role defaults before building. Existing native execution preference is preserved; do not ask the owner to choose it again.

## Global constraints

- Complete-order, collection-only, online operation. No partial handover, offline pass, delegated membership grant, staff bypass, shipping, return/exchange or uncollect mutation.
- Pass possession authorises handover; explicit sharing warning. Native original verified buyer email issues it; no phone or event QR fallback. Live email-proof expiry clears UI/prevents issue; an already issued bearer credential remains bounded by its own 15-minute expiry/current order eligibility.
- Exact `commerce_collection` default-off switch gates new preparation/proof/handover actions independently of catalog/checkout. Retained history/refunds/reconciliation remain reachable. Checkout stays disabled until separate acceptance and owner activation.
- Existing paid/free eligibility, snapshots, receipt/payment/ledger/stock contracts remain authoritative. Handover writes no stock movement, ledger entry or financial status. Read AGENTS.md finance invariants before refund integration.
- Use native React console/personal-workspace patterns and square light/dark surfaces. No stack conversion or dependency install. Never change the shared node_modules installation or Claude's primary checkout.
- Tenant → order → sorted inventory locks, no provider/mail HTTP inside locks. All idempotency and proof checks are server-side; no client can set paid/collected fields directly.
- All private DTOs/no-store/identity revalidation/telemetry exclusions and ticket-only offline rules apply to collection. Never log/pass secrets in URLs, email, localStorage or fixture screenshots.
- Isolated test DB `miconvener_commerce_s1c_testing`; serialize local PHP runners. Generate framework files with inspected `make:*` commands and `--no-interaction`; use Boost docs before changing code. Stage new files, run `pint --dirty`, include migrations in static analysis; rebuild public/build with JS/CSS.
- Affected checks per task; one final whole-suite CI gate on final source, with justified fixes for actual failures. No extra unchanged full-suite loops, repeated independent review or production activation.

## Review Focus

1. Another seller/operator, phone-only proof, revoked permission or expired email proof must not reveal/use credentials; an eligible no-dues buyer keeps a paid order after membership lapses. Tasks 1, 2, 4.
2. A copied/rotated/expired/consumed pass, event QR, changed payload or duplicate/lost response must not cause duplicate handover. Tasks 2, 3, 4.
3. Primary refund/free cancellation racing collection must allow one winner; additional-capture refunds must never cancel/restock the primary order. Task 3 with real committed-fixture two-process tests.
4. Failed/late ready mail, returning to preparation, flag pause, queue outage and network loss must leave truthful states and retained evidence. Tasks 1, 4, 5.
5. Collection must not double-deduct purchase stock, leak seller finance, alter immutable terms, weaken receipt identity, venue/event scans or offline boundaries. Tasks 1, 3–6.

## Files and interfaces

New landlord models/factories/migration: `StoreFulfilmentEvent`, `StoreCollectionPass`, `StoreCollection`, `StoreFulfilmentNotice`. Composite order/shop/tenant ownership, immutable event/collection snapshots, unique order collection, order-scoped idempotency, monotonic pass lifecycle. Audit actor ID/name survives deletion without cascading the record. Reuse `CommerceInventoryLocks`, `StoreOrderAccess`, `QrCodeGenerator` and current safe mail identity; retain every S1b model.

Services under `app/Services/Marketplace/`: `StoreFulfilment` owns shared financial/fulfilment eligibility and readiness; `StoreCollectionPasses` owns proof issue/parse/revoke/validate; `StoreCollections` owns final handover; `StoreFulfilmentNotices` owns readiness delivery/recovery. `StoreCollectionPayload` whitelists masked collection-only staff data. Neither a normal order DTO nor finance DTO contains proof material.

Routes below are proposed names/paths, using existing host/CSRF/native proof conventions, explicit UUID constraints and generic denied IDs. Secrets are POST bodies only.

- Buyer `POST /my/store-orders/{order}/collection-pass`, name `store.orders.collection-pass`; original live email proof, operation key. Return `{pass_id, qr_svg, manual_code, expires_at}` plus no-store. QR renderer receives purpose-tagged JSON, never an event/admission token.
- Seller `GET /store/collections`, name `tenant.store.collections.index`; collection permission and seller-owned history.
- Seller `POST /store/collections/{order}/ready`, `/prepare`, `/validate`, `/confirm`, named `tenant.store.collections.ready|prepare|validate|confirm`. `prepare` requires reason; all writes require UUID operation key except read-only validation. No ID-only GET can mutate.
- Request classes `StoreReadyRequest`, `StorePreparationRequest`, `ValidateStoreCollectionRequest`, `ConfirmStoreCollectionRequest` in `app/Http/Requests/Tenant/Store/`; buyer `IssueStoreCollectionPassRequest` in `app/Http/Requests/Marketplace/`. Controllers derive tenant/buyer/actor, never trust body ownership.

## Task 1: immutable lifecycle, collection permission and ready transitions

**Files:** StoreFulfilmentEvent model/factory/landlord migration only; `app/Services/Marketplace/StoreFulfilment.php`; PermissionsSeeder, RolePermissions, Permission::TENANT_SAFE, HandleInertiaRequests, consoleNav; tests `tests/Feature/Marketplace/StoreFulfilmentTest.php`, `StoreCollectionPermissionsTest.php` and existing permission registries.

**Interfaces:** `StoreFulfilment::eligible(StoreOrder $order): bool`; `ready(StoreOrder $order, User $actor, string $operationKey): StoreFulfilmentEvent`. Role/ownership/feature checks are repeated after acquiring the tenant/order locks. Reversal belongs to Task 2, where pass revocation exists; do not add a placeholder reversal in Task 1.

- [ ] Write RED tests: foreign ownership/collection-only role/no finance grant; Org Superadmin/Admin defaults and custom-role ceiling; exact collection flag; no-dues/zero/paid eligibility; quoted/pending/captured-review/unposted ledger/refund pending/review reason/abnormal allocation reject. Ready retry writes one event; changed payload/key conflict rejects; snapshots immutable across product/user changes; pause retains readable audit. No order/stock/ledger mutation beyond allowed fulfilment transition.
- [ ] Run `php artisan test --compact tests/Feature/Marketplace/StoreFulfilmentTest.php tests/Feature/Marketplace/StoreCollectionPermissionsTest.php` in the isolated DB; require meaningful missing behavior failures.
- [ ] Implement owned schema, services and separate permission/capability with defaults described in the spec. Add `commerce_collection` in the existing exact-switch pattern and history-aware navigation; do not enable any organisation. Keep original order snapshot trigger intact; store readiness audit in new records.
- [ ] Run affected tests plus existing permission/role/console-usage checks and scoped static analysis including the migration; expect green. Tick and commit schema/source/tests together.

## Task 2: separate short-lived bearer proof

**Files:** StoreCollectionPass model/factory/landlord migration in this owning task; `StoreCollectionPasses.php`; buyer request/controller `StoreCollectionPassController.php`; route in routes/web.php; telemetry/private-path/offline tests; `tests/Feature/Marketplace/StoreCollectionPassTest.php`.

**Interfaces:** `issue(StoreOrder $order, Session $session, string $operationKey): array` returns the explicit response contract above; `validate(StoreOrder $order, User $actor, array $credential): StoreCollectionPass` authorises seller/permission and checks purpose/hash/lifecycle without consuming; `revoke(StoreOrder $order, string $reason): void` requires the canonical lock transaction. Add `matches(StoreCollectionPass $pass, array $credential): bool` for hash-only idempotent completed-operation replay; it grants no access and is used only after actor/tenant/order authorisation in Task 3. Add `StoreFulfilment::prepare(StoreOrder $order, User $actor, string $operationKey, string $reason): StoreFulfilmentEvent` here, atomically recording reversal and revoking active passes. `validate` accepts QR `{pass_id,secret,purpose}` or manual `{manual_code}` for this already-authorised order, rejects mixed/extra fields. Renderer: existing `App\Services\Events\QrCodeGenerator::svgDataUri(string $payload)`; reuse rendering only, never event verification.

- [ ] Write RED cases: email-only owner, expired/email-swapped/phone/foreign denial; paid order survives member lapse; issue requires ready/eligible/flag; exact 15-minute expiry; same key returns original proof/expiry, fresh key rotates and invalidates predecessor, stale-key replay cannot renew; malformed >2KiB/wrong-purpose/event QR/foreign pass/code brute-force throttles; encrypted/hash storage and no DTO/log/URL/cache secret. Test 12-character manual normalisation and constant-time verifier path behavior without timing assertions.
- [ ] Run `php artisan test --compact tests/Feature/Marketplace/StoreCollectionPassTest.php`; observe RED, then implement issue/verify/revoke and locked one-current-pass guarantee. Return private/no-store, use HMAC limiter selectors, clear old encrypted material when safe while preserving hashes/history. Idempotent success replay may reconstruct only an active credential, never resurrect a revoked/expired one.
- [ ] Test and implement reversal (retry once, changed payload denied, required reason, immutable event) with pass revocation; test refund/cancel invalidation hooks via Task 3 before claiming those paths complete. Add daily expired/revoked/consumed secret purge using existing scheduler conventions; hashing/audit survives purge. Task 3 tests prove retries of completed handover can use stored hash/key after purge.
- [ ] Re-run proof/fulfilment/identity/offline/telemetry checks, Pint/scoped static; tick and commit. No real pass/email or payment issued in production.

## Task 3: atomic full handover versus refund

**Files:** StoreCollection model/factory/landlord migration in this owning task; `StoreCollections.php`, existing StoreRefunds.php and fulfilment integration; `tests/Feature/Marketplace/StoreCollectionsTest.php`, existing StoreRefundsTest.php; new `tests/Infrastructure/Marketplace/StoreCollectionConcurrencyTest.php` using the repository's committed-fixture subprocess conventions.

**Interface:** `StoreCollections::confirm(StoreOrder $order, User $actor, array $credential, string $operationKey): StoreCollection`. Require explicit validated `all_items_handed_over=true` at the HTTP boundary. For a prior collection, authorise actor/order first, then match the retained original pass hash and operation key; do not require its now-consumed/expired active validity merely to replay the original success. Do not store or log proof in immutable collection evidence: use pass ID, method, actor ID/name, original line quantities, timestamp and operation key. Reuse shared eligibility under lock; primary refund/free cancellation invalidates proof at claim time even if funding/provider result is pending.

- [ ] RED tests: whole order once; invalid/not-ready/unpaid/refunded/financial-review/paused/foreign/revoked-role denial; immutable collection and no uncollect; same consumed credential/key retries return original collection even after expiry/purge, altered proof/order denies, second operator sees truthful duplicate without new record; no additional stock movement or ledger post. Extra-capture refund does not restock/cancel primary order or invalidate proof merely by existing; unresolved review still blocks.
- [ ] RED real two-process tests (not mocks): commit isolated fixtures with DatabaseTruncation per existing infrastructure tests, coordinate lock/claim barriers, then prove collection-first refuses primary refund; refund-first (including needs_funding) refuses handover; free cancellation versus handover; two confirmations produce one collection. Time-bound children and clean only owned fixtures. Do not run infrastructure writers on a shared test DB.
- [ ] Implement claim/invalidation/confirmation under canonical locks, release before provider/mail calls. Preserve S1b's original capture/fee/refund posting and confirmed-only restock. Do not turn a provider success into a failure to conceal an inconsistent historical collected state.
- [ ] Run feature and infrastructure files plus StoreRefundsTest/StorePayoutsTest/StorePaymentConfirmationTest; expect green and unchanged finance invariants. Tick and commit. This is the collection/refund acceptance dependency; do not replace it with two sequential unit calls.

## Task 4: native buyer pass and staff collection workspace

**Files:** buyer page `resources/js/Pages/Public/Marketplace/Orders/Index.jsx`, StoreOrderPayload; new seller `resources/js/Pages/Tenant/Store/Collections/Index.jsx`, `resources/js/Components/Marketplace/StoreCollectionScanner.jsx`; seller controller `Tenant/Store/StoreCollectionController.php`, requests, collection-only payload; routes/subdomain.php; console nav/capabilities, existing order/finance links; JS tests `resources/js/test/marketplace/StoreCollections.test.jsx`, HTTP `StoreCollectionScreensTest.php`.

- [ ] RED HTTP/UI cases: actual permission/flag-derived entry points; collection-only staff cannot access finance/orders/captures/full buyer email; native original-email verification preserved; Ready/Preparing/Collected/Review/Refund/Paused/No orders states; returned/expired/rotated pass unavailable; no proof in initial Inertia props or persistent caches; 320/390/768/1440 long content and light/dark; keyboard dialog focus/return, busy/error focus and alert roles.
- [ ] Implement three-step buyer progress and explicit pass issue, real accessible countdown and sharing warning. No automatic token creation on GET/refocus. Visibility/proof expiry/navigation clear memory; pass response arriving after proof loss is discarded. Preserve receipt, payment/finance and support links.
- [ ] Implement staff preparation and scan/manual review → required all-items check → confirm, server revalidation at both stages, current state refresh after ambiguous network response, stable operation key and no optimistic handover. Use existing html5-qrcode only after camera start, stop/clear on unmount/visibility change; event scanner endpoints/handlers unchanged. Manual input stays usable when camera permission/device fails. No credential in GET/query/hash/telemetry.
- [ ] Run `php artisan test --compact tests/Feature/Marketplace/StoreCollectionScreensTest.php` plus affected Vitest, event scanner and offline tests; lint/Pint/static and build. Browser-check native synthetic data and manual path without a provider; no claim of actual phone camera/200% zoom without evidence. Tick and commit source/tests/rebuilt public/build together.

## Task 5: durable ready notice and support recovery

**Files:** StoreFulfilmentNotice model/factory/landlord migration in this owning task; `StoreFulfilmentNotices.php`, `app/Jobs/DeliverStoreReadyNotice.php`, `app/Mail/StoreOrderReady.php`, `resources/views/emails/store-order-ready.blade.php`; existing scheduling/telemetry exclusions; exhaustive MailableRenderTest.php; tests `StoreReadyNoticesTest.php`, existing StoreReceiptsTest.php.

**Interfaces:** `queue(StoreFulfilmentEvent $readyEvent): StoreFulfilmentNotice` inside the ready transition transaction; `deliver(string $noticeId): void`, `retryQueued(): void`, same bounded behavior as receipt delivery. One unique ready event/notice, encrypted original destination/snapshot, stable Message-ID, after-commit dispatch with a recovery sweep. Reversal invalidates undispatched old-ready notices; a notice already handed to SMTP cannot be recalled. Recheck current ready generation before claiming send, show no pass in email, and always direct to current native order state; a subsequent ready event has a distinct notice. Suppressed stale notices become `superseded`, not falsely `sent`.

- [ ] RED tests: actual template render and exhaustive registry; ready retries one notice, new ready generation after reversal one new notice; stale queued notice superseded; queued job/worker/send failure retains readiness and protected pass; exhausted/swept abandoned claim; original receipt still sends once/retains its snapshot. Max five actual attempts, abandoned sending after ten minutes, sweep every five minutes; no marketing credits/opt-out or SMS path.
- [ ] Implement the new notice without repurposing receipt snapshots/FKs. Retain immutable ready event identity; mutable delivery fields guarded separately. Readiness payload shows distinct mail status and support guidance. Catch dispatch fault after commit, never roll back handover/preparation for mail.
- [ ] Run notice/receipt/MailableRender tests plus queue-tenancy/mail-identity checks; Pint/static (include new migration) and affected UI tests. Tick and commit.

## Task 6: final review, sandbox acceptance and release record

- [ ] Self-review against all five focus classes and public interfaces, registry/nav/capability/config/retention/telemetry touchpoints and prototype decisions. Update AGENTS.md finance/collection invariants, design/plan/roadmap in the same implementation commits. Confirm no placeholder, unused dependency, product/receipt repricing or event QR crossover.
- [ ] One fresh whole-branch review after native implementation; resolve material findings with focused RED→GREEN. One final CI gate on final source; migrations, exhaustive mail/permission/category registries, existing venue/calendar/rooms/RFQ/legacy payments, Community/Giving/dues/event admission and offline tests included. Record failure fixes honestly without suppressing guards or repeating unchanged whole-suite runs.
- [ ] Separately obtain/record isolated sandbox operator/account/recipient/actions/budget authority, then run the existing S1b sandbox runbook plus S1c whole-order handover, race and mobile camera/manual checks. Validate actual 200% text zoom and readiness email arrival. No production credential substitution, real merchant/customer involvement or inferred send approval.
- [ ] Only after owner merge/deploy approval, verify release/current commit/schema/permissions/queue/scheduler and flags. Checkout remains off until provider and collection acceptance, numerical/support/tax/funding terms and a named pilot are approved. Historical recovery stays accessible. No money/audit table rollback or data deletion.

## Plan self-review and handoff

Coverage: Task 1 lifecycle/roles; Task 2 buyer proof; Task 3 atomic handover/refund; Task 4 actual protected UX; Task 5 ready delivery; Task 6 preservation/provider/release evidence. Every Review Focus maps to owning regressions. Renderer and camera already exist; only purpose-isolated usage is new. No application source/schema was changed by preparing this plan. Models/migrations stay in their owning tasks; reversal waits for proof revocation in Task 2, and completed-operation replay uses retained proof hashes instead of active-pass validation.

Implementation risks made explicit: bearer sharing is a policy choice, not identity assurance; SMTP can deliver an already-submitted stale email; credential issue needs encrypted replay material but history needs only hashes; callback/ledger review blocks fulfilment; collection never deducts stock twice. Whole-order-only/no override keeps partial fulfilment and returns out of this first slice.

Owner review requested for the spec/preview and the five recommended decisions listed in the spec. Keep native execution once approved; do not merge/deploy/enable checkout from a planning approval alone.

## Preparation self-review report

**Touchpoints updated:** design, implementation plan and roadmap; synthetic task-owned preview. AGENTS.md, memory, seeders/roles, application navigation/permissions, environment/config and production are N/A for this planning-only change; their required implementation touchpoints are assigned above. No package, source, schema or build artifact changed. Browser assertions cover the prototype; new application tests/full CI would not verify these prose changes and were not rerun.

**UI gap analysis:** discoverability present in buyer/staff preview controls; production collection navigation is proposed in Tasks 1/4. Empty/loading/auth states are specified for implementation, not simulated as real access checks in the static preview. Preview error/expiry/refund/duplicate/confirmation states are functional. Light/dark checks at outer viewports 320/390/768/1440 (embedded content 288/358/736/1024) found no horizontal overflow; the buyer action precedes the summary on small screens. Visible labels, native buttons/inputs/checkbox, error focus and status announcements are present. Actual device camera, native 200% text zoom, provider flows and ready-email delivery are unverified.

**Solved:** moved model/migration creation into owning tasks; moved reversal to the proof-revocation task rather than an intermediate placeholder; distinguished hash-based completed replay from active-pass verification; clarified extra-capture review can remain support-blocked. The preview's manual submit initially hit the conversation sandbox's form restriction; replaced it with a local button action and reran assertions. Enlarged the illustrative QR icon and put the buyer pass first on mobile. Final preview checks passed: pass/expiry/refresh, invalid/manual match, explicit whole-order check, handover/duplicate, refund blocking, preparation→ready, both themes/widths; zero browser errors/warnings. No assertion of a real QR/security/backend test.

**Recommended for owner decision:** approve or amend bearer sharing, whole-order-only collection, 15-minute pass, no staff override and proposed collection-role defaults. Recommend the stated bounded first release; changing to identity-checked or partial collection would require a revised proof/fulfilment design before building, rather than an ad hoc override. Native implementation effort is six bounded tasks plus separately authorised external acceptance; provider/device/mail timing is not estimated from prototype tests.

**Not gaps:** the sample pass is explicitly non-functional; no real customer appears; no camera is opened; local preview actions do not mutate the application. Checkout remains disabled under the existing release gate. One read-only builder check found no planning blockers; no second plan-review agent or product implementation ran.
