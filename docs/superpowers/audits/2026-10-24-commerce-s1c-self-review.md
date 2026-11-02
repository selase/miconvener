# S1c author self-review and release evidence

**Initial checkpoint (2026-10-24, before CI/release):** Native implementation on `codex/commerce-s1c`, based on deployed PR #50 / `7a90c66c`. One fresh whole-branch review completed; findings addressed in one focused fix pass. Final CI pending. No merge, deployment, production flag or real send/payment performed. Checkout stays disabled.

Author pause: **Is there anything I have overlooked? Is the solution optimal? Let me take a step back.**

## Self-Review Report

### Touchpoints updated

- [x] AGENTS.md: immutable arrangements, purpose-isolated proof, custody/refund locks, transactional notice recovery.
- [x] Seeders/role mappings/tenant-safe permission and shared capabilities: `manage store collection`; no finance grants. Missing `commerce_collection` is off.
- [x] Navigation: existing Store section links to the role-gated native workspace; retained history works while paused.
- [x] Tests: lifecycle, ownership, malformed/expired/replaced credentials, true two-worker races, HTTP consent/privacy, camera cleanup, native buyer/staff flow, mail render/retry and prior feature regressions.
- [x] Assets rebuilt and committed with UI source.
- [x] Roadmap/spec/plan status: implementation evidence separated from commercial/provider/release gates.
- Memory files: N/A; durable project rules and evidence live in this repository.
- `.env.example`/package/config: N/A; no dependency or environment-variable addition. Existing QR renderer, camera and native verification reused. Local fixture uses the worktree’s existing log-mail QA database only.

### UI Gap Analysis

- Discoverability: Store fulfilment for authorised staff; buyer `/my/store-orders` has method progress, explicit pass action, receipt and support.
- Empty/paused states: present; retained history, no fabricated order/payment action.
- Permission/ownership: collection-only HTTP workspace excludes finance/captures/full buyer email; address only on authorised selected detail and original-email buyer detail. Native verification remains required.
- Loading/error: busy controls, aborts, error focus, uncertain-response refresh, stable confirmation key, no optimistic completion; offline handover disabled. Camera starts only explicitly; manual fallback retained.
- Responsive: native synthetic collection and delivery screens checked at 320/390/768/1440; no horizontal overflow. Dark buyer/staff and light mobile reviewed. Phone camera/native 200% text zoom still require operator acceptance.
- Accessibility: labelled controls, required whole-order checkbox, existing modal focus trap, post-action focus on the retained order heading. Scanning matches only; it never confirms automatically.
- Ready email status remains distinct from physical fulfilment and receipt email. No private pass/address appears in readiness email.

### Solved during author review

- Controller dispatch name conflicted with Laravel base method; renamed to `dispatchOrder`.
- Native enum status compared incorrectly to text; corrected to `TenantStatusEnum::ACTIVE`.
- Formatter finalised inheritance extension points; simplified request hierarchy with final concrete rules and a shared authorisation/messages base.
- Short buyer addresses could bypass an ad hoc masking regex; reused `ContactMask`.
- Native handover left the list row stale and lost keyboard focus after its button disappeared. Added RED→GREEN regression; apply authoritative result to row and focus the retained heading.
- Added missing method-specific progress, original buyer delivery destination and handover timestamp, without exposing them in ordinary/list/receipt DTOs.
- Added a delivery/collection filter and offline warning, cleared pass memory after proof loss/page hide/revision, discarded delayed responses, and stopped late camera starts.
- Corrected cancelled/review/refund labels so retained history does not misrepresent those orders as in preparation. Added actual HTTP coverage for ready/preparation and unsuccessful-delivery actions; split fixture contexts between tenants.
- Kept readiness notices separate from receipt records. Source ready generation is rechecked under canonical locks; sender exceptions do not contain private transport details.

### Evidence

- Task4 native HTTP: 6 passed / 38 assertions; final label and preparation/failed-delivery boundary cases: 3 passed / 8 assertions.
- Ready notices plus existing receipt cases: 11 passed / 53 assertions.
- Exhaustive mailable registry, mail branding, tenant-aware queue URL and offline scan hardening: 30 passed / 64 assertions.
- Marketplace/nav/offline component checks: 56 passed; subsequent focused progress/focus cases: 10 passed. Final CI will cover final source together.
- Scoped PHPStan: 0. ESLint/Pint/build passed; build emits existing chunk/plugin-time advisories.
- True PostgreSQL two-worker races: initial collection/refund/free-cancel/idempotency five cases; added delivery dispatch/refund/return/delivered four cases, all passed. No in-memory serialization substitute.
- Synthetic native browser: original email-code flow → private buyer order → explicit code → staff match → required all-items confirmation → Collected, and analogous dispatched order → Delivered. Completion retains receipt, removes buyer code action, updates staff row and restores heading focus. No provider network call or real mail.
- Early local test infrastructure issues are recorded in the execution ledger: accidental overlap recovered only the dedicated test DB; host load ~500 and ~8GiB free caused worker startup/timeouts. No application pass inferred from a timeout; serial reruns passed. One initial ready-mail RED failed at generated model connection, then full readiness tests passed on the actual landlord model.

### Recommended for user decision

Before trading: approve a named sandbox operator/account/recipient/action budget and complete the existing S1b runbook plus both handover methods, actual ready-mail arrival, phone camera/manual fallback and native 200% text zoom. Confirm numerical settlement/reserve/fee/support/tax terms and named pilot. Keep checkout off until those pass. Merge/deploy requires the owner’s later go-ahead.

### Not gaps

- Delivery is included in the displayed price; no separate fee, courier or COD is part of this approved increment.
- Ready has no expiry. Fifteen minutes limits only the explicitly issued credential; expired proof leaves the order available.
- Whole-order only. Failed delivery and custody return do not create a merchandise exchange/return or automatically restock.
- SMTP cannot guarantee exactly-once inbox arrival or recall an already claimed message. Stable identity, bounded retries, supersession of unsent stale notices and the current native order link make that explicit.
- External provider/camera/zoom acceptance is not replaced by mocks, viewport measurements or this local fixture.

## Independent review and single fix pass

Fresh reviewer inspected `7a90c66c..278e16e9` with no conversation history, read-only. No Critical finance/security defect found. Two Important findings: direct HTML detail URLs lost selection, and purpose isolation/successful QR handover needed stronger backend tests. Two suggestions labelled Minor were regraded by the author as Important for user impact: typed country separators were discarded, and payment could be clicked while replacing an accepted fulfilment choice.

All four addressed in one pass. Native direct links now bootstrap a selected-order ID and masked summary, then fetch protected detail; addresses remain out of initial Inertia props. Checkout is disabled during editing, saving and the replacement-order refetch in storefront and My Orders. Country input retains raw typing until submission normalisation. Genuine issued collection/delivery QR secrets now succeed; changing only purpose is rejected without consumption, and delivery expiry/rotation/physical-return/redispatch generations are covered.

Evidence: native-props/direct-loader/form/payment tests observed failing before fixes. Backend3passed29 assertions and three affected component files18passed after fixes. The test-only QR coverage passed against existing correct guards; no guard was temporarily weakened to manufacture a failure. One reviewer and one fix pass, no repeat whole-branch review. Final CI will run on the resulting source; operator/provider/phone/zoom gates remain unchanged.

CI’s first formatting gate found eight formatting/import-alias lines in five earlier-task test files. Formatted every changed PHP file against the branch base, inspected the diff (no runtime behavior changed), and retained all assertions. Final checks rerun on the formatted commit; no dependency or CI configuration workaround.

## Current checkpoint — 2026-10-27

The initial pending-CI/no-release statement above is historical. S1c is deployed via PR #51 (`7afdb2e5`) after green final CI, with the production optional-dependency correction deployed via PR #52 (`d4c16801`). The marketplace roadmap records release IDs and test counts. Two explicitly approved GH1 live purchases completed once each: collection and delivery using manual proof, balanced posting, stock zero and checkout off. Owner confirmed receipt/readiness emails found. No mail-provider settings, resend, refund or payout was needed. PR #53 (`df385c12`) is deployed and its separately approved free physical retest passed: camera stopped, Code matched appeared, and exactly one QR-based collection was recorded. The owner report was independently verified against production; zero charge, no payment attempt and checkout disabled. This is bounded owner acceptance, not general marketplace readiness approval.


## Finance acceptance self-review — 2026-10-27

Author pause: **Is there anything I have overlooked? Is the solution optimal? Let me take a step back.**

### Touchpoints updated

- Gateway correction is confined to commerce refund verification; purchase capture eligibility, Giving/legacy refunds, commissions, ledger writers, stock consumption and payout release are unchanged. The existing refund/reconcile UI and scheduler already call this verifier.
- Proportional tests cover three reversal lifecycle states, six rejected identities/payment states and two rejected new-capture reversals. Actual provider pending evidence reproduced the defect; RED preceded correction. The roadmap/runbook distinguish mocked, real-worker, actual test-provider and bounded live evidence.
- AGENTS.md/memory: no new architecture or standing rule. Seeders/roles/permissions/navigation: existing recovery route and permission remain. Environment/config/packages/schema: no additions or changes. Frontend/build: no JS/CSS change, so no asset rebuild required. Original Claude checkout remains read-only.

### UI gap analysis

Existing finance recovery actions expose pending/review/confirmed outcomes and remain permission-gated; no new screen, link, modal, input, responsive layout or keyboard interaction is introduced. No UI-specific change requires a new browser layout run. Pending provider outcomes must not be presented as completed refunds, and the existing completion path remains responsible for exactly-once ledger/restock updates. The earlier real phone camera failure is closed by actual device acceptance, not by a mocked assertion.

### Solved and recommendations

Solved the actual Paystack reversal-status mismatch without weakening refund identity or allowing a reversed payment to fulfil a new sale. One focused independent review found no blockers. Do not claim the financial release complete: actual sandbox refunds remain pending, the test transfer requires owner OTP, the correction is not merged/deployed, and launch terms/readiness are still unapproved. Keep checkout disabled; complete/reconcile the existing operations and obtain release/activation approval before any production trading change.


### Terminal acceptance follow-through — 2026-10-28

The pending provider outcomes above are historical: both refunds are processed and the owner-approved replacement sandbox transfer succeeded after private OTP entry. The native patched refund verifier returned processed with the original payment reversed, and the settlement gateway independently returned success/GHS10/zero fee. Source CI on `4331675c` passed (3,938 PHP, 64 production-style no-Telescope checks, all JS/quality checks); the correction is still unmerged/undeployed. No additional code change, dependency, repeated source review or new paid live test was introduced for this outcome recording. Remaining hosted sandbox/launch gates and explicit checkout activation are not silently accepted.
