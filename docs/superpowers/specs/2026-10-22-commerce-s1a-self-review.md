# S1a catalog self-review

**Branch:** `codex/commerce-s1a`. **Scope:** merchandise catalog, not orders/payments or deployment. Review question: “Is there anything I have overlooked? Is the solution optimal? Let me take a step back.”

## Touchpoints updated

- [x] AGENTS.md: canonical catalog, inventory lock/audit, private media, identity and rollback rules.
- [x] Seeders/roles: `manage store catalog` follows existing permission propagation; no grants through venue permissions.
- [x] Navigation: seller Store/Catalog; native Communities and organisation Store links; typed storefront/detail links.
- [x] Permissions: current seller/team checks on every action and current verified-email active self membership for private reads.
- [x] Configuration: private local media disk; production reuses existing private S3 configuration. No new dependencies, environment variables or plan/commission settings. `.env.example` therefore unchanged.
- [x] Tests: schema/stock/idempotency/locking, privacy/media/feature state, tenant boundaries, seller actions, public/member storefront and UI, legacy venue/staff-room preservation.
- [x] Durable continuation: design, plan, roadmap and this report in the owned branch; no separate global memory dependency.

## UI gap analysis

Present: seller navigation and empty-state CTA, typed create/edit/publication, archived draft restoration, variants/prices, audited corrections, approved photo upload/removal, member/public item cards, no-purchase explanation, loading/errors and error focus. Native square surfaces and existing light/dark tokens retained. Keyboard option selection changes the visible availability state. New-item save navigates to canonical edit URL.

Local app browser evidence: synthetic seller create/publish and opening stock; photo upload; native logged-email verification; active membership whose type has both dues amount/period null; member-only item success; feature-off item and copied-photo URL denied; 320/390/768/1440 storefront without horizontal overflow; narrow seller form and long product title; light/dark and missing-photo/unavailable-size states. Synthetic flag restored; no live messages or payments.

**Limitations:** native 200% text zoom and actual-device acceptance remain unverified with the exposed browser controls. Production S3 direct-object denial/bucket policy must be checked before rollout: fake/local disks are not proof of live bucket privacy. Existing shared marketplace chrome remains venue-oriented (including its existing verification copy); mixed-category discovery is a later roadmap slice. These are explicit review/rollout items, not claimed passes.

## Solved during implementation/review

- Protected private merchandise from generic listing/count/media/search/MCP and legacy booking/RFQ/venue CRUD paths, preserving existing non-merchandise behavior.
- Closed Inertia history restoration leak: private content waits for current access; renewed email proof can complete its recheck; gallery updates reset removed photo selection.
- First-save canonical route and stable variant order prevent stale empty creation pages and false unsaved changes.
- Inventory actor ID/name snapshots survive user deletion; seller corrections are immutable/idempotent and cannot go negative.
- Organisation hard deletion now returns a clear conflict before logo changes when catalog/audit retention applies, under the catalog's tenant lock. Existing deactivation remains available.
- Fresh whole-branch review found one additional venue-calendar blackout path; merchandise is now rejected without writing a VenueBooking. Regression was observed failing before the guard.

## Recommended for user decision

Before S1a production rollout: review this PR; approve merge/deployment and a named catalog pilot; verify private production media policy; complete enlarged-text/device check. Checkout remains disabled.

Before S1b: approve store fee/bearer/cap, supported settlement mode and accountable payout/refund/support ownership, collection/cancellation/return terms, and isolated provider sandbox. Detailed continuation brief: `docs/superpowers/plans/2026-10-22-commerce-continuation-gates.md`. No automatic adoption of the old marketplace 10%, no gateway swap and no implicit real-money pilot.

## Not gaps

- Checkout, customer orders, receipts, reservations and handover are explicitly S1b/S1c; catalog buttons do not fake those operations.
- Catalog works without Community or dues. Private item eligibility is membership identity, not payment of dues.
- Venue IDs, URLs, calendar and operational records remain authoritative; no destructive type conversion or historical repricing.
- No new package is installed or unused. No placeholder money provider or parallel identity system is introduced.
- Previously downloaded content cannot be recalled; current protected requests revoke access rather than promising remote deletion.

## Verification record

Focused implementation runs: stock/schema 21 tests; privacy/legacy integrated 118; seller/permissions 54; final seller retention 25; storefront/admin 33; native Communities/access 14; UI/navigation/quote-payment/inbox 50. These overlap and are not summed as unique coverage. Pint, ESLint, scoped static analysis and rebuilt assets passed at their recorded commits.

Final preservation, bounded full JS and full static-analysis results are recorded below when finished. The first simultaneous local complete gates caused resource contention: full PHP was stopped; first full JS had 464 passes and nine timeouts. No timeout limit was weakened. The complete PHP gate belongs to the existing PR CI, rather than another redundant full local run. Fresh review: one read-only reviewer, no second review loop; one material calendar finding addressed with RED→GREEN evidence.

Final affected preservation: **288 tests / 1,297 assertions passed** in 365.73s. Bounded complete JS: **472/473 passed**, one pre-existing certificate editor timeout; isolated file rerun **14/14 passed in 6.30s** with the existing timeout, remote complete gate still required. No source/timeout change to hide it.
