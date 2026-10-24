# S1c author self-review and release evidence

2026-10-24. Native implementation on `codex/commerce-s1c`, based on deployed PR #50 / `7a90c66c`. Pending one fresh whole-branch reviewer and final CI. No merge, deployment, production flag or real send/payment performed. Checkout stays disabled.

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
