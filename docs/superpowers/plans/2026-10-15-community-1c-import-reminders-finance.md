# Community 1c: Import, Reminders and Finance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Tick each step in this file in the same commit as the work.

**Goal:** An association can bring its whole register in from a spreadsheet, members are reminded automatically (and on demand) with their pay link, member caps follow the plan, and the Finance section shows dues collections, cash, arrears and what can be paid out, with CSV exports.

**Architecture:** Builds on 1a and 1b (`MembershipType`, `CommunitySettings`, `DuesScheduler`, `dues:process`, `CommercialTerms`). Import uses `maatwebsite/excel` (already a dependency) with a two-step preview then confirm. Reminders are a second phase of `dues:process`, aggregated per member, sent with `TenantMailIdentity` (email) and the `SmsGateway` contract with `SmsAllowance` (SMS), recorded in `dues_reminders`. Plan entitlements use the existing package features and `tenants:sync-features`. Finance reads domain records for collections and `DuesBalance` for what is payable.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Pest 5, Inertia + React, maatwebsite/excel.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md` sections 5.3, 7 (reminders aggregate per member), 8 (service messages, SMS spending limit), 13 and 13.1 (member caps, over-cap and downgrade rules), 10 item 1c.

## Global Constraints

- Dues reminders and receipts are **service messages**: no marketing opt-out, but they respect `community_settings.reminders_enabled` and the organisation's SMS spending limit.
- Email reminders use `TenantMailIdentity` (organisation display name and Reply-To). SMS uses the `SmsGateway` contract and reserves the organisation's credits through `SmsAllowance` before provider submission, with receipt-backed refunds only on confirmed failure; a reminder that cannot be sent for lack of credit or the spending limit is recorded as `skipped` with the reason and never retried in a loop.
- Every reminder carries the member's pay link (`route('public.dues.show', …)`), the organisation's name, the total open and the oldest due date.
- Member caps (spec 13): Free 50, Starter 500, Growth 5,000, Enterprise unlimited. Counted: `pending`, `active`, `lapsed`. Over the cap nothing is deleted and existing members keep being charged; adding, importing and approving are refused with a message naming the plan and the number.
- Finance figures: **collected** (paid charges by `paid_at`, gross, with recorded cash as its own line), **collected online** (excludes recorded cash), **available to pay out** (`DuesBalance::available`, never includes cash). Never mix them.
- The tenant Finance page and menu follow the 1a rule `handlesTicketMoney() || communityEnabled() || hasDuesMoney()`; existing ticket figures are unchanged.
- PHP style, toolchain, PostgreSQL, Pint and phpstan as in the 1a plan. Commit authorship must identify the actual contributor; Codex changes do not inherit 1a’s Claude co-author trailer.

## Review Focus

1. **A 3,000-row import with 40 bad rows** saves nothing until confirmed, reports each bad row with its line number and reason, and saves the good rows once (Task 1).
2. **A daily payer** receives at most one reminder a day (or one a week if the organisation chose the weekly digest), with email listing every open charge and SMS carrying the bounded summary/full pay link (Task 2).
3. **An organisation out of SMS credit** still gets email reminders sent and SMS reminders recorded as skipped (Task 2).
4. **Recorded cash** appears in collections but never in available to pay out (Task 4).
5. **A downgrade below the member count** keeps every member and charge, and blocks only new additions (Task 3).

---

## Pre-flight rulings (2026-10-15)

- Execute Task 3's cap and advisory-lock foundation before Task 1 because confirming an import requires that interface. Task 1 then integrates it; Task 2 and Task 4 follow. Review each task before proceeding.
- All additions, including rejoining an exited member, enforce the cap under the same per-tenant advisory transaction lock. Pending and lapsed members already count.
- Preserve the existing stricter email-only duplicate guard while name + phone permits shared family numbers. A single normalizer applies that rule consistently to import and manual writes; existing records are not rewritten.
- Import refuses ambiguous active membership-type names after case/whitespace normalization with a per-row error in both preview and confirmation. Existing manual uniqueness/schema remain unchanged; administrators must rename conflicting types before importing.
- Arrears audiences require both `manage members` and `read finance`, following spec section 8.
- Reserve SMS credits and a reminder row before sending, then refund the exact reservation on confirmed provider failure. Persist a distinct reserved state rather than reporting an unsent message as sent. Unknown/interrupted outcomes require reconciliation and never a blind retry.
- Browser acceptance uses a separate QA database, email log and null SMS gateway; the owner's existing Purpledot data and settlement settings remain unchanged.

### Task 1: Spreadsheet import

**Files:**
- Create: `app/Services/Community/MemberImport.php`, `app/Http/Controllers/Tenant/MemberImportController.php`, `app/Http/Requests/Tenant/Community/{UploadMemberImportRequest,ConfirmMemberImportRequest}.php`, `resources/js/Pages/Tenant/Community/ImportMembers.jsx`, `tests/Feature/Community/MemberImportTest.php`, fixtures under `tests/Fixtures/community/` (a good CSV, a mixed CSV, an XLSX)
- Modify: routes (`tenant.community.people.import.{show,preview,confirm}`), `People.jsx` ("Import" button), a downloadable template (`tenant.community.people.import.template`, CSV with headers `name,phone,email,membership_type,joined_on`).

**Interfaces:**
- `MemberImport::preview(Tenant, UploadedFile): ImportPreview` with `rows` (`line`, `name`, `phone`, `email`, `membership_type`, `joined_on`, `status` one of `new|duplicate|existing|error`, `errors`), summary counts, and a `token` (preview stored in cache 30 minutes, tenant-scoped). Max 10,000 rows, 5 MB, CSV or XLSX.
- `MemberImport::confirm(Tenant, string $token, string $existingPolicy /* skip|update */, int $userId): ImportResult` inside one transaction that consumes the preview atomically (`Cache::pull` under a lock keyed by the token, so a second confirm gets 410), takes the same per-tenant advisory lock as manual member creation, **revalidates every row against the register as it is now** (rows that became duplicates since the preview are skipped and reported), then enforces the member cap under that lock (refuses the whole import with a clear message if the new rows would exceed it).
- **One normaliser for import and manual entry:** `App\Services\Community\MemberIdentity` (`normalisePhone()`, `normaliseEmail()`, `isDuplicate()`) is used by `MemberRequest`, `MemberController` and `MemberImport`.
- Row rules: name required; phone or email required; phone normalised (Ghana local `0XXXXXXXXX` and `+233…` to E.164); membership type matched by name case-insensitively within the tenant and must be active; duplicate inside the file = same name + phone (or name + email when no phone); existing = same rule against the register.

- [x] **Step 1: Failing tests:** two simultaneous confirms of one preview create the rows once; an import racing a manual add of the same person creates one member; imports and manual adds together cannot exceed the cap; manual entry and import reject the same duplicate; preview saves nothing; per-row errors with line numbers (missing contact, unknown type, inactive type, bad date, bad phone); in-file duplicates; existing members skipped or updated by policy; XLSX works; confirm creates exactly the good rows once (second confirm with the same token → 410 "This import has already been used"); a token from another tenant → 404; over-cap import refused whole; permission `manage members` required; switch off → 404.
- [x] **Step 2: Run, see them fail.** **Step 3: Implement** (page: upload, preview table with filters for errors, policy choice, confirm). **Step 4: Run** `tests/Feature/Community`.
- [x] **Step 5: Commit** `feat(community): import members from a spreadsheet with preview`.
- [x] **Task review fix 1:** reject ambiguous active normalized type names in preview and confirmation; cover existing case/whitespace collisions and collisions introduced after preview.
- [x] **Final review I1 / M1 / M2:** reject rows whose reserved email and name/phone identify different members, both in preview and under the current tenant lock at confirmation; preserve per-row errors, good-row consume-once import and planned identity uniqueness. Cover collisions before/after preview, manual-update agreement, untouched member/history/pay-token fields and multiple planned updates. Preview shows both contacts; import error counts use singular/plural grammar. Evidence: `5c39e8b`; 49 affected PHP tests (262 assertions), 26 JS tests, scoped PHPStan 0, Pint/lint/build green; scoped independent re-review approved all four findings.

### Task 2: Reminders and the arrears button

**Files:**
- Create: migration (`dues_reminders`: `id`, `tenant_id`, `member_id`, `step` one of `before|on_day|after_7|after_30|digest|manual`, `channel` `email|sms`, `charge_ids` json, `status` `sent|skipped|failed`, `reason` nullable, `sent_for_date` date (the organisation-local day), `created_at`; unique `(member_id, channel, sent_for_date)`, so automatic and manual reminders together send at most one per member, per channel, per local day), add to `community_settings`: `reminders_enabled` (true), `reminder_offsets` json (default `{"before":7,"on_day":0,"after_7":7,"after_30":30}`), `frequent_digest` (`daily|weekly`, default `daily`, applies to daily and weekly periods), `sms_monthly_limit` (nullable int); `app/Services/Dues/DuesReminders.php`, `app/Mail/DuesReminder.php` + view, `tests/Feature/Community/DuesRemindersTest.php`
- Modify: `ProcessDuesCommand` (phase 2 after generation), routes `tenant.community.money.dues.remind-arrears`, Overview settings tab (reminders on/off, offsets, digest, SMS monthly limit) with a Form Request, `MoneyDues.jsx` ("Remind everyone in arrears" with a confirm showing the count and SMS credits needed).

**Interfaces:**
- `DuesReminders::sendDue(Tenant, CarbonImmutable $localToday): array{sent: int, skipped: int}`: for each member with open charges, decide today's step: monthly, quarterly and yearly charges use the offsets per charge (but one message per member per day lists all open charges); daily and weekly charges use the digest (daily: one per day while anything is open; weekly: on `week_starts_on`). Email when the member has an email; SMS when they have a phone, SMS is enabled for the tenant, credits remain and the monthly limit is not reached.
- `DuesReminders::remindArrears(Tenant, int $userId): array{sent: int, skipped: int}`: step `manual`; shares the per-member, per-channel, per-day limit with automatic reminders.
- **Reserve before sending:** inside a transaction holding a per-tenant advisory lock (`pg_advisory_xact_lock`), check the monthly SMS limit (this month's reserved plus sent SMS reminders) and allowance, reserve the credit with its exact source receipt and insert the `dues_reminders` row; commit; then send. On a confirmed provider refusal mark the row `failed` and refund that exact reservation once; uncertain/interrupted outcomes remain reserved for investigation. Concurrent workers can never overspend credits or the monthly limit. Email uses the same row-first pattern for its daily limit.

- [x] **Step 1: Failing tests:** automatic plus manual on one day sends one per channel; concurrent reminder runs never exceed the remaining credits or the monthly limit; a provider failure returns the credit; the four offsets for a monthly charge; one message listing several open charges; daily digest once per day; weekly digest only on the week start; reminders off → none; no credit → email sent, SMS skipped with reason; monthly SMS limit reached → skipped; arrears button sends once per day per member and needs both `manage members` and `read finance`; message content has pay link, organisation name, total and oldest due date; white-label from/reply-to; tenant isolation.
- [x] **Step 2–4:** fail, implement, pass (`tests/Feature/Community tests/Feature/Dues tests/Feature/Mail`).
- [x] **Step 5: Commit** `feat(community): automatic dues reminders and the arrears button`.
- [x] **Task review fix 2 (N1):** freeze the organisation-local day in every queued run payload before serialization; stale manual/automatic dispatcher, continuation and delivery retries stop. Cover unknown/sent SMS across midnight, stable serialized continuation dates, and fresh next-day HTTP requests.
- [x] **Task review fix 1 (R1/R2):** bound queued audience pages and independent channel delivery units below queue retry-after; preserve untouched-recipient continuation and unknown-outcome reservations; preserve saved settings and validation drafts through tab remounts. Cover queue reachability/channel ordering and Settings second-save behavior with observed RED/GREEN tests.

Rulings recorded during Task 2:
- Email itemises every open charge. SMS contains the open-charge count, full organisation/member names, totals separated by currency, oldest due date and full pay link to the complete list. When this mandatory summary exceeds the existing 459-character limit, skip SMS as `message_too_long` and still send email. Cost: SMS recipients open the link for period details; exceptionally long summaries require email.
- Every queued logical run freezes its organisation-local date when initially enqueued, before serialization; dispatcher retries, continuations, delivery units and retries preserve that date. Stale units stop, including manual runs, rather than obtaining a new daily claim. A new human manual request uses its current local day. Public synchronous `remindArrears` retains current-day-per-call behavior. Historical `--date` generation still works without stale reminders. Cost: runs delayed overnight require a new manual request or the next eligible automatic run; this prevents replay of unknown submissions. This N1 ruling supersedes the earlier manual execution-day policy.
- SMS reservations and existing consume callers reuse one allowance debit path and a persistent receipt identifying original usage-row and pack sources. Usage resets take the same allowance lock; refunds never reduce a new month's row. Provider connection failures and HTTP 5xx responses use an explicit uncertain outcome and keep their reservation; definite refusals refund once. `sent` records provider/mail acceptance, not handset delivery. Provider throttling records a skipped reminder and refunds rather than looping.
- Manual HTTP requests queue delivery and return 202/`queued`; the confirmation is an estimate and re-evaluates audience, permissions, settings and funds at job execution. Shared contact numbers retain the spec's per-member transactional reminder limit.
- Review fix: dispatch pages of 100 recipients with an independently queued cursor continuation; each delivery unit handles one email or at most 50 personalised SMS recipients. Dispatch SMS units before email units and reserve only inside the executing channel unit. Dispatcher/delivery timeouts are 30/60 seconds, below the existing 90-second retry-after; up to three attempts can reach unclaimed recipients while persisted daily claims prevent replay. Each unit rechecks current actor permissions, settings, local date and charges. Cost: more small queue jobs, while ambiguous transport outcomes still require reconciliation.
- Review fix: Overview owns the settings draft and field errors, so Settings can unmount on tab changes while retaining saved choices and unsaved validation state. A full page reload reinitialises from server values; hidden duplicate forms are unnecessary.
- Final-review operational follow-up: the organisation owner/admin must establish unknown SMS provider outcomes manually before high-volume SMS rollout. Keep uncertain reservations held; never blindly refund or retry them. An auditable operator reconciliation workflow belongs to Messages milestone 2 and must reuse the allowance lock and account for existing reserved/sent reminder usage. No operator screen or command is included in milestone 1c.
- [x] **Final review M2:** arrears confirmation uses `member currently has` for one and `members currently have` for zero/many, with component coverage.

### Task 3: Member caps by plan

**Files:**
- Modify: `database/seeders/EventPackageSeeder.php` (feature `community_members`: free 50, starter 500, growth 5000, enterprise unlimited, following the seeder's existing limit-feature convention), `app/Services/Community/MemberCap.php` (create), `MemberController` (store, and approve when it exists; both take the per-tenant advisory lock before the cap check), `MemberImport::confirm`, `People.jsx` (usage "312 of 500 members" and the over-cap banner); `tests/Feature/Community/MemberCapTest.php`

**Interfaces:**
- `MemberCap::limit(Tenant): ?int`, `MemberCap::counted(Tenant): int` (`pending` + `active` + `lapsed`), `MemberCap::assertRoomFor(Tenant, int $adding): void` (throws `DomainException` "Your {Plan} plan includes {n} members. …").

- [x] **Step 1: Failing tests:** add refused at the cap with the plan name and number; exited members do not count; a downgrade below the count keeps everyone, charges keep generating, adding is blocked; enterprise unlimited; the package feature reaches existing tenants through `tenants:sync-features`.
- [x] **Step 2–4:** fail, implement, pass.
- [x] **Step 5: Commit** `feat(community): member caps by plan`.

Ruling: Task 3 supplies the cap and shared advisory lock first. Task 1 will wire the real importer to `MemberCap::withLock`; no import or approval stubs were added. Rejoin is covered now because it increases the counted register. Focused cap tests: 16 green, including a real Starter-to-Free downgrade preserving 51 members, paid history and charge generation.

### Task 4: Finance for dues

**Files:**
- Modify: `app/Http/Controllers/Tenant/FinanceController.php` (already opened in 1a by `handlesTicketMoney() || communityEnabled() || hasDuesMoney()`; add a dues section), its page under `resources/js/Pages/Tenant/Finance/Index.jsx`; create `app/Services/Dues/DuesReport.php`, export routes `tenant.finance.dues.collections.export` and `tenant.finance.dues.arrears.export`; `tests/Feature/Community/DuesFinanceTest.php`

**Interfaces:**
- `DuesReport::summary(Tenant, CarbonImmutable $from, CarbonImmutable $to): array` per currency: `collected_online_gross`, `gateway_fees`, `commission`, `net_online`, `recorded_cash`, `collected_total` (online gross + cash), `available_to_pay_out` (from `DuesBalance`), `in_flight`, `arrears_total`, `members_in_arrears`.
- Collections CSV: one row per paid charge in the range (member, phone, email, type, period, amount, method, paid at, reference, gateway fee, commission, net). Arrears CSV: one row per member in arrears (member, contacts, type, open charges, total, oldest due date).

- [x] **Step 1: Failing tests:** a dues-only tenant (no ticket money) can open Finance; a ticket tenant without Community sees exactly what it saw before (snapshot of the existing props); recorded cash in collections and never in available; own-gateway tenant: collections from charges, available 0; date range and currency grouping; CSV headers and rows; `read finance` required; tenant isolation.
- [x] **Step 2–4:** fail, implement, pass (`tests/Feature/Community tests/Feature/Finance`). Focused Task 4 PHP 22/22 (189 assertions), JS 35/35, scoped PHPStan 0, Pint/lint/build green. Broader Community/Finance/Dues/Payments regression: 547/547 passed (2,960 assertions, 1,159.571s under system CPU contention). Independent task review approved the implementation with no Critical/Important findings; final milestone-wide verification remains Task 5.
- [x] **Step 5: Commit** `feat(finance): dues collections, cash, arrears and payable in Finance`.

**Task 4 integration rulings (approved by parent):**
- [x] Dues-only PaymentSettings uses unchanged `manage payment settings` plus `showsFinance()` eligibility for reading/configuring Paystack. Stripe Connect keeps its existing ticket entitlement; dues remain Paystack-only; commercial overrides remain superadmin-only.
- [x] Overview uses DuesReport stored online receipts and organisation-local month boundaries, labels collections separately from payable, links Finance reporting and uses a currency-neutral empty state. Finance links/totals require `read finance`.
- [x] Historical cash-only organisations retain Finance after Community is switched off: `hasDuesMoney()` includes paid charges alongside ledger/payout history. Cash remains outside ledgers and payable; Community writing routes stay switched off.
- [x] Dues-only Finance emphasises dues instead of empty ticket totals; ticket-only props and both settlement readers remain unchanged. Current arrears/payable/in-flight are labelled independently of filtered collections. CSV amounts remain integer minor units and currencies never mix.
- [x] **Final review M3:** applying dues dates preserves applied ticket search/status on mixed Finance; covered by a component test. Evidence: `5c39e8b` and the verification evidence below.

### Task 5: Verification

- [ ] Full suite, `pint --dirty`, phpstan, lint, JS tests, build (commit rebuilt `public/build` if JSX or CSS changed).
- [x] Local: used fictional CSV/XLSX fixtures and isolated QA; import preview/confirmation/update, scheduler/reminders and Finance cash/date checks passed. Outstanding browser visual/download checks are listed below.
- [x] One whole-branch review by a fresh reviewer; I1 and three minors fixed with tests in `5c39e8b`; one scoped re-review approved all findings, no new Critical/Important issue.
- [ ] Update `docs/superpowers/community-roadmap.md` (status `built`, evidence) and push.


## Final review and acceptance record (2026-10-15)

Fresh whole-branch review covered `0ed29f6..7485df9`. One Important finding was fixed in `5c39e8b`: conflicting email and name/phone matches could pass preview and cause a database uniqueness exception at confirmation, rolling back good rows and consuming the preview. Both preview and confirmation now reject the conflicting row without guessing a target. Tests preserve good-row import, consume-once behavior, manual-update agreement, contacts, membership/history and pay tokens. Three minor UI findings were also fixed: both contacts are visible, singular grammar is correct, and dues date submission retains ticket filters. One scoped re-review approved all findings with no new Critical/Important issue. `592982f` only removes an accidentally tracked scratch report; source is unchanged.

### Self-Review Report

“Is there anything I have overlooked? Is the solution optimal? Let me take a step back.”

#### Touchpoints updated

- AGENTS.md records reservation/refund/locking and report/access contracts, including reuse by future Messages work.
- Existing plan and roadmap carry handover and decisions. No independent memory file is needed.
- EventPackageSeeder includes member caps; no new permission is introduced. Existing manage-members/read-finance/manage-payment-settings gates and superadmin commercial controls remain. Package feature sync must be included in the later approved deployment preparation.
- People links Import; Overview settings expose reminders; Money and Overview link Finance where authorized. Public pay links retain their original scope.
- No new dependency, environment variable or configuration key: composer/npm manifests, lockfiles and PHPStan baseline are unchanged. No unused dependency was installed.
- Behavioral tests cover endpoints, row validation, tenant isolation, permissions, currencies, date boundaries, historical cash/payable, identity/cap races, credit reset/reservation races, uncertain provider outcomes and queued-date retries. Final full-suite evidence is recorded below when complete.
- Tracked public/build was rebuilt in every UI commit. No ledger writer was added outside LedgerService.

#### UI Gap Analysis

- Discoverability: existing People/Money/Overview/Finance entry points link the new actions.
- Empty states: imports show file guidance; cap and row errors have explicit outcomes; empty collections use currency-neutral/date guidance; arrears confirmation describes the audience and SMS credit estimate.
- Permissions: backend gates apply independently of hidden controls; arrears audiences require both permissions, and the queued actor is checked again. Finance totals are omitted for unauthorized readers.
- Loading/errors: upload/confirmation/date actions disable during work; malformed rows, expired/reused previews and quota failures have visible errors. SMS persists accepted/reserved/skipped/failed outcomes honestly.
- Mobile/accessibility: import and reminder interactions were exercised with a 390px override, but the preserved small mobile screenshot is insufficient to substantiate visual readability; narrow-screen visual acceptance remains open. Desktop dark-mode typed fields were readable. Existing semantic labels, buttons, modal structure and keyboard controls remain. Finance desktop totals and normal keyboard date editing passed.
- Outstanding browser checks: CSV download, narrow-screen visual acceptance (including import/reminders/Finance), PaymentSettings visual acceptance and post-fix visual refresh remain unverified because the isolated browser connection disappeared after a download timeout. Backend exports/access and component tests cover these behaviors, but are not described as completed browser checks.

#### Solved (auto-fixed)

Task reviews corrected ambiguous membership type names, queued delivery timing/continuation, settings drafts after tab remount and frozen queued dates across midnight. Final review corrected the importer conflict and three UI findings described above. Real cash recording was checked through the existing UI and reports cash without changing payable.

#### Recommended for user decision

Before high-volume SMS rollout, assign an application operator with the organisation owner/admin to investigate provider outcomes for uncertain reservations. Keep credits held until the outcome is established; do not blindly retry, refund or edit balances. Add an auditable reconciliation workflow in Messages milestone 2 (estimated 1–2 development days including tests and operator UI, subject to provider status-API availability). This is a rollout prerequisite for substantial SMS volume, not functionality claimed by 1c. Finish the outstanding browser checks when the isolated browser is available. Bundle-size optimization remains a separate performance follow-up; the existing >500 kB build warning is retained.

#### Not gaps

- New groups, following/join approval, verified identifiers, /my PWA, giving and member benefits belong to later roadmap milestones.
- This report reads existing dues states; it does not introduce refunds or correction writers.
- Provider acceptance is not a claim of handset delivery. QA used log email, disabled SMS and empty payment keys.
- The earlier D2/1a/1b code was not broadly re-audited; targeted changed touchpoints and the whole 1c delta were reviewed. Generated assets were rebuilt and their manifest checked, rather than reverse-engineered.
- Original checkout, owner Purpledot data/settlement mode and production remain untouched.


### Rulings carried forward and their costs

1. Build caps before import. Cost: task execution order differs from numbering.
2. Require manage-members plus read-finance for arrears audiences. Cost: some staff need another permission.
3. Preserve the existing reserved-email identity rule alongside name/phone matching. Cost: distinct people sharing only that reserved email need separate contact details or a later deliberate identity-policy change.
4. Reserve SMS credit before submission; refund exact sources only on confirmed failure. Cost: uncertain/interrupted sends retain credits pending reconciliation.
5. Count rejoining members against the cap under the shared lock. Cost: rejoining can be blocked at capacity.
6. Reject ambiguous normalized membership-type names per row. Cost: administrators must rename colliding types before importing.
7. Itemise charges in email; use a bounded SMS summary and full pay link, skipping summaries over 459 characters. Cost: SMS recipients open the link for details and unusually long content needs email.
8. Skip stale automatic runs instead of sending historical reminder bursts. Cost: delayed automatic reminders wait for an eligible current-day run. The initial manual execution-day approach was refined by ruling 10.
9. Allow Finance-eligible organisations to configure Paystack under the existing payment-settings permission, retaining Stripe ticket/superadmin fee gates; correct Overview collection labels/links. Cost: Paystack setup eligibility extends beyond paid-ticket plans.
10. Freeze every queued logical run's local day, including manual runs and retries. Cost: an overnight-delayed manual run needs a fresh request; synchronous new requests still resolve the current day.
11. Retain Finance for paid cash history after Community is switched off. Cost: historical cash-only tenants retain Finance and potentially empty Payouts visibility under existing permissions.
12. Do not copy the earlier Claude co-author trailer onto Codex commits. Cost: commit metadata differs from the earlier Claude-specific convention; authorship remains accurate.

13. Use a draft PR and the existing clean CI runner for the final full suite after the observed local PostgreSQL I/O/setup failures, keeping status in progress until green. Cost: a draft PR/remote branch exists before final verification; it can be closed without merging or deploying. This changes the push order, not the integration prohibition.
14. Sync approved main into the isolated feature branch to resolve its generated asset conflict and run draft CI; never integrate Community into main or deploy. Cost: released use-case changes and rebuilt assets add integration review/test surface; both histories remain in a reversible feature-branch merge.

### Full-suite verification investigation

The first completed four-worker run on the reviewed code returned 2,589/2,593 passing, 12,563 assertions, with four errors in 1,858.576s. One badge PDF pagination dataset encountered text-fit validation; three SMS/own-gateway tests encountered PostgreSQL connection timeout or a worker database still in use during setup. No Community assertion failed. Read-only database activity during the slow run showed no blocking queries and prolonged tenant-database creation waiting on DataFileRead. These results are not recorded as a green full suite. Focused reproduction/root-cause investigation precedes any correction or final rerun.



The unchanged three affected files passed 67/67 tests (249 assertions) in 346.871s on a clean focused rerun. Before/during/after database activity had no blockers or I/O waits. The framework's schema-probe exception path can attempt database recreation, explaining the secondary database-in-use error following the initial timeout. A controlled valid Faker-vocabulary event title, “Necessitatibus exercitationem reprehenderit voluptatibus.”, then reproduced the exact original Letter/8 pagination failure at `layout.event_name`; attendee names, ticket codes and ticket types were normal. The permanent correction pins only that pagination test's event title to “Medical Congress”, without changing production validators, bounds or assertions. All 14 pagination datasets passed after the one-line fixture correction in `1890366`; production validation and assertions are unchanged. Scoped test-only review and clean CI results follow when complete.


### Integration and clean CI

Approved `origin/main` (`077958e`, including PR #22 use-case pages) was merged **into `community-1c` only** in `11c4adb`; no Community-to-main merge or production change occurred. Only generated manifest/bundle conflicts required resolution, using a fresh production build. Independent scoped integration review found no Critical/Important issue. Focused combined-source checks passed: PHP 29/29 (272 assertions), frontend 22/22, Pint, lint and build. Existing chunk-size/timing advisories remain.

Draft [PR #26](https://github.com/selase/miconvener/pull/26) is conflict-free. Clean [CI run 38786241163](https://github.com/selase/miconvener/actions/runs/38786241163) on `11c4adb` passed the code-quality job: Pint (1,450 files), PHPStan 0, build, lint and JavaScript 282/282 (38 files). Two PHP-job attempts stopped before tests on a GitHub package archive HTTP 404. The exact locked archive later returned HTTP 200; the third targeted attempt installed dependencies and started the full PHP suite. No dependency, lockfile, workflow or security-setting change was made. The full run then passed 2,600 tests with seven setup failures (12,653 assertions, 407.57s), all in the new Community concurrency tests: the fresh base database lacked the root permissions schema before seeding. These tests had passed locally against an already-migrated base database. The clean-database reproduction failed all seven before assertions. `8a4cfbb` adds Laravel DatabaseTruncation to those two test files: this triggers parallel worker database setup, resets committed fixtures and preserves real subprocess contention. Fresh parallel verification passed 7/7 with 48 assertions; no assertions, production behavior, global harness, dependency or workflow changed. Full clean CI acceptance remains pending.

A DatabaseMigrations isolation trial exercised the race assertions but exposed a separate pre-existing rollback defect: `2026_09_20_110000_drop_data_export_requests_table::down()` recreates `requested_by` without the foreign key that `2026_09_13_120001_correct_user_reference_column_types::down()` subsequently drops. The final truncation lifecycle does not rely on reversing historical migrations. Those migrations remain unchanged; validate/fix that rollback path separately before relying on full schema rollback.

The next full CI run (`38789537870`, `746ab39`) passed quality checks and all seven race tests, but failed 18 existing admin tests (2,589 passed, 12,644 assertions, 286.50s). The isolated race-only check had missed committed role fixtures surviving into subsequent transaction-based tests. A deterministic one-worker mixed-suite reproduction matched all 18 failures. The teardown correction in `67f9e36` calls framework truncation after each race test; production code, global harness and all assertions remain unchanged. The deterministic one-worker run reported all 40 selected tests/153 assertions passed, but its agent formatter returned an unexplained exit 1, so that command is not claimed green. Fresh raw four-worker verification (same 40 mixed tests, including every original failure) passed 153 assertions in 94.69s, exit 0, with all issues displayed and no warnings. Independent scoped re-review and final full CI are the remaining gates.
