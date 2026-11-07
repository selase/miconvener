# Future-organisation file storage allowances

**Approved:** owner2November2026: Free100MiB, Starter1GiB, Growth5GiB, Enterprise20GiB;20MiB/file remains. Existing organisations retain their current allowances. Never delete files or block reading because of a cap.
**Parent:** project completion Phase4. Native implementation; existing StorageMeter and CommunityContentStorage remain the enforcement path.

## Constraints and Review Focus

- Preserve every existing organisation's effective limit before installing defaults, including unlimited, disabled and explicit finite allowances. Preserve it across package sync, upgrades and downgrades. No creation-date guess or resync can retroactively cap an existing organisation.
- One nullable protected tenant snapshot: null means future plan allowance; -1 means retained unlimited; nonnegative means retained finite limit. Not tenant mass assignable. Migration preserves old limits using original planLimitValue, before adding any defaults.
- Only the storage feature/pivots change on existing packages. Never run a whole package seeder on production or change price, commission, gateway, plan assignment or other entitlements. Fresh installs keep defaults through the existing package seeder.
- New organisations without a storage feature row still get the approved standard-package/no-plan Free default. Explicit tenant feature overrides and unknown custom-plan terms retain their native semantics.
- Existing aggregate byte meter counts tenant assets, merchandise media and retained Library versions. Preserve tenant lock, exact-boundary allowance, private file serving and missing/foreign/expired-proof denial. Cap enforcement affects new uploads only.
- Staff see readable capacity information and existing allowance retention. Public pricing copy is deferred: this release covers managed Library/merchandise uploads, not a blanket audit of all legacy upload routes. No raw storage keys or private document content in DTOs or public copy.

## Task1 — Reproduce defaults and preservation requirements

- [x] Add failing standard-tier/no-plan limit tests, old unlimited/finite preservation across plan changes, new explicit override, invalid snapshot range and fresh seeder cases.
- [x] Add exact quota boundary/retained-edition coverage using small synthetic file sizes, not giant allocations.
- [x] Run focused tests and observe missing defaults/preservation failures.

## Task2 — Implement through existing policy and meter

- [x] Native-generate storage policy, migration and Pest tests. Store legacy snapshot before attaching storage feature defaults. Default resolution remains read-only and tenant bounded.
- [x] Integrate storage-only resolution with existing tenant featureLimitValue; no replacement quota meter or read restrictions.
- [x] Update fresh package definitions and staff capacity explanation; defer public pricing claims under the scope ruling above. No package-wide reseed in production.
- [x] Verify native synthetic PDF publication/member download and unsigned denial; preserve physical storage and immutable versions.
- [x] Run affected allowance/Library/package tests and frontend tests if changed; scoped static analysis/Pint/build.

## Task3 — Review and release

- [x] Self-review quota preservation, concurrent upload locking, auth, over-limit reads and failed-upload cleanup; one fresh independent review.
- [x] Require final-head CI, merge/deploy under approved future-only policy.
- [x] Read-only verify old effective allowances unchanged, future standard defaults installed and original assets retained. Update parent roadmap/evidence.

## Existing acceptance evidence

Read-only production inventory: five package storage values unset; ten organisations' Library versions0bytes; Purpledot aggregate188396bytes and no fixed limit. Local synthetic PDF uploaded/published through real staff form, independently verified member download byte-identical878541bytes. Sign-out cleared saved pass across tabs and denied private Library URL through normal login redirect. No real tenant document published or deleted.


## Execution and self-review — 2 November

- First default/preservation run: 8 tests, 6 RED before policy. Follow-up UI regressions: HTTP capacity prop RED; two JS capacity cases RED. Final affected PHP:41passed136assertions; Library JS6passed. Follow-up preservation/rollback tests recorded separately below. Pint, scoped ESLint, PHPStan and asset build passed.
- Local-only migration applied to `miconvener_acceptance_codex_20261102`, not another checkout/database. Existing synthetic published PDF retained; staff screen shows858KiB and retained unlimited allowance. Desktop1280 light/dark has no overflow. Browser390px override did not take effect (measured1280), so this is NOT a mobile visual pass. Existing responsive classes and component tests remain; physical/zoom checks stay in parentPhase3.
- Fresh independent reviewer found no blocking defect. Added direct disabled-legacy/custom-package/non-storage-pivot/rollback assertions from minor notes. Full migration executed on owned acceptance database; added a real migration-up regression against a pre-existing synthetic cohort, retaining finite/unlimited values and original updated_at.
- Forward-only schema preservation: rollback application code without dropping snapshots. An automatic down-migration must not erase retained customer terms. Package defaults are attached missing-only; no full production package reseed.
- Exact quota boundary accepted; retained-edition bytes counted and old edition remains readable after the allowance is reached. Existing native tenant locks are unchanged; this test is boundary/read coverage, not new worker-race evidence.

### Self-Review Report

Touchpoints: AGENTS updated with retained-limit and migration rules; package seeder/default policy updated; proportional PHP/JS tests and rebuilt assets. No new environment variables, permissions, dependencies, roles or navigation destinations. Existing Library navigation and permission gate expose the capacity summary. Persistent project knowledge is in this tracked plan and AGENTS; no separate memory file needed.

UI: discoverability present in Library; existing empty-state add-document/service-note actions retained; permission gating unchanged; existing loading/error handling retained; capacity refreshed after successful saves; theme-aware colors and responsive wrapping used. Desktop both themes checked; actual narrow viewport remains explicitly unverified in this run. Cap blocks attachments only, not text notes/reads/deletion.

Solved: legacy unlimited could otherwise inherit new package limits; protected snapshot prevents that across plan changes. Existing negotiated package pivot retained on default installation and fresh seeding. Destructive rollback refused. Review notes now have direct regression assertions.

Recommended: future wider upload-route/usage-meter performance audit before advertising a global storage product. No additional owner decision is needed for this bounded release.

Not gaps: Free storage allowance does not enable paid Library features; existing rollout/plan permissions still apply. Existing organisations retain original terms; no historic asset is deleted, read-restricted or reclassified. Public storage pricing is intentionally deferred under the scope ruling.

Review follow-up:78tests230assertions passed across allowance/migration plus merchandise catalog/visibility suites;3package fee/assignment tests8assertions passed. Migration regression executes the actual up-method after recreating the pre-column schema; destructive down is tested separately.

Final CI found one new test fixture assumed the migration-created storage feature existed. Reproduced the exact missing-row exception locally by removing that fixture inside the test transaction; corrected the test to create its own feature explicitly, without changing production policy or weakening assertions. Final-head CI rerun required.


## Release result

PR87 merged `fd3cdebcfb143a7be3a7dc2e8c24371edbe2b302` after final-head `2dc16cd5` CI green: code quality3m45s, Laravel15m24s (run41481032640). Automatic Cloud deployment `depl-a3216153-58ba-4d07-8851-28a966613b2a` succeeded16:42:11UTC.

Read-only before/after proves all10existingtenants retain unlimited allowance with snapshot-1. Package financial settings, tenant financial settings and tenant-feature hashes unchanged. Tracked file totals unchanged (Purpledot188396bytes). New limit feature/pivots: Free104857600, Starter1073741824, Growth5368709120, Enterprise21474836480bytes. Probe/custom package unchanged. No full package seeder, other tenant activation, extra deploy or real file mutation.
