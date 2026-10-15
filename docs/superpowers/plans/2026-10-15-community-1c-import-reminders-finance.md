# Community 1c: Import, Reminders and Finance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Tick each step in this file in the same commit as the work.

**Goal:** An association can bring its whole register in from a spreadsheet, members are reminded automatically (and on demand) with their pay link, member caps follow the plan, and the Finance section shows dues collections, cash, arrears and what can be paid out, with CSV exports.

**Architecture:** Builds on 1a and 1b (`MembershipType`, `CommunitySettings`, `DuesScheduler`, `dues:process`, `CommercialTerms`). Import uses `maatwebsite/excel` (already a dependency) with a two-step preview then confirm. Reminders are a second phase of `dues:process`, aggregated per member, sent with `TenantMailIdentity` (email) and the `SmsGateway` contract with `SmsAllowance` (SMS), recorded in `dues_reminders`. Plan entitlements use the existing package features and `tenants:sync-features`. Finance reads domain records for collections and `DuesBalance` for what is payable.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Pest 5, Inertia + React, maatwebsite/excel.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md` sections 5.3, 7 (reminders aggregate per member), 8 (service messages, SMS spending limit), 13 and 13.1 (member caps, over-cap and downgrade rules), 10 item 1c.

## Global Constraints

- Dues reminders and receipts are **service messages**: no marketing opt-out, but they respect `community_settings.reminders_enabled` and the organisation's SMS spending limit.
- Email reminders use `TenantMailIdentity` (organisation display name and Reply-To). SMS uses the `SmsGateway` contract and consumes the organisation's credits through `SmsAllowance` (`canSend` before sending, `consume` after); a reminder that cannot be sent for lack of credit or the spending limit is recorded as `skipped` with the reason and never retried in a loop.
- Every reminder carries the member's pay link (`route('public.dues.show', …)`), the organisation's name, the total open and the oldest due date.
- Member caps (spec 13): Free 50, Starter 500, Growth 5,000, Enterprise unlimited. Counted: `pending`, `active`, `lapsed`. Over the cap nothing is deleted and existing members keep being charged; adding, importing and approving are refused with a message naming the plan and the number.
- Finance figures: **collected** (paid charges by `paid_at`, gross, with recorded cash as its own line), **collected online** (excludes recorded cash), **available to pay out** (`DuesBalance::available`, never includes cash). Never mix them.
- The tenant Finance page and menu follow the 1a rule `handlesTicketMoney() || communityEnabled() || hasDuesMoney()`; existing ticket figures are unchanged.
- PHP style, toolchain, PostgreSQL, Pint, phpstan and commit trailer as in the 1a plan.

## Review Focus

1. **A 3,000-row import with 40 bad rows** saves nothing until confirmed, reports each bad row with its line number and reason, and saves the good rows once (Task 1).
2. **A daily payer** receives at most one reminder a day (or one a week if the organisation chose the weekly digest), listing every open charge (Task 2).
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

### Task 2: Reminders and the arrears button

**Files:**
- Create: migration (`dues_reminders`: `id`, `tenant_id`, `member_id`, `step` one of `before|on_day|after_7|after_30|digest|manual`, `channel` `email|sms`, `charge_ids` json, `status` `sent|skipped|failed`, `reason` nullable, `sent_for_date` date (the organisation-local day), `created_at`; unique `(member_id, channel, sent_for_date)`, so automatic and manual reminders together send at most one per member, per channel, per local day), add to `community_settings`: `reminders_enabled` (true), `reminder_offsets` json (default `{"before":7,"on_day":0,"after_7":7,"after_30":30}`), `frequent_digest` (`daily|weekly`, default `daily`, applies to daily and weekly periods), `sms_monthly_limit` (nullable int); `app/Services/Dues/DuesReminders.php`, `app/Mail/DuesReminder.php` + view, `tests/Feature/Community/DuesRemindersTest.php`
- Modify: `ProcessDuesCommand` (phase 2 after generation), routes `tenant.community.money.dues.remind-arrears`, Overview settings tab (reminders on/off, offsets, digest, SMS monthly limit) with a Form Request, `MoneyDues.jsx` ("Remind everyone in arrears" with a confirm showing the count and SMS credits needed).

**Interfaces:**
- `DuesReminders::sendDue(Tenant, CarbonImmutable $localToday): array{sent: int, skipped: int}`: for each member with open charges, decide today's step: monthly, quarterly and yearly charges use the offsets per charge (but one message per member per day lists all open charges); daily and weekly charges use the digest (daily: one per day while anything is open; weekly: on `week_starts_on`). Email when the member has an email; SMS when they have a phone, SMS is enabled for the tenant, credits remain and the monthly limit is not reached.
- `DuesReminders::remindArrears(Tenant, int $userId): array{sent: int, skipped: int}`: step `manual`; shares the per-member, per-channel, per-day limit with automatic reminders.
- **Reserve before sending:** inside a transaction holding a per-tenant advisory lock (`pg_advisory_xact_lock`), check the monthly SMS limit (this month's sent SMS reminders) and `SmsAllowance::canSend`, `consume` the credit and insert the `dues_reminders` row; commit; then send. On a provider failure mark the row `failed` and return the credit. Concurrent workers can never overspend credits or the monthly limit. Email uses the same row-first pattern for its daily limit.

- [x] **Step 1: Failing tests:** automatic plus manual on one day sends one per channel; concurrent reminder runs never exceed the remaining credits or the monthly limit; a provider failure returns the credit; the four offsets for a monthly charge; one message listing several open charges; daily digest once per day; weekly digest only on the week start; reminders off → none; no credit → email sent, SMS skipped with reason; monthly SMS limit reached → skipped; arrears button sends once per day per member and needs `manage members`; message content has pay link, organisation name, total and oldest due date; white-label from/reply-to; tenant isolation.
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
- [ ] **Step 2–4:** fail, implement, pass (`tests/Feature/Community tests/Feature/Finance`). Focused Task 4 PHP 22/22 (189 assertions), JS 35/35, scoped PHPStan 0, Pint/lint/build green; broader Community/Finance/Dues/Payments regression pass is running and remains pending. Implementation committed for independent review; Task 4 is not yet marked complete.
- [x] **Step 5: Commit** `feat(finance): dues collections, cash, arrears and payable in Finance`.

**Task 4 integration rulings (approved by parent):**
- [x] Dues-only PaymentSettings uses unchanged `manage payment settings` plus `showsFinance()` eligibility for reading/configuring Paystack. Stripe Connect keeps its existing ticket entitlement; dues remain Paystack-only; commercial overrides remain superadmin-only.
- [x] Overview uses DuesReport stored online receipts and organisation-local month boundaries, labels collections separately from payable, links Finance reporting and uses a currency-neutral empty state. Finance links/totals require `read finance`.
- [x] Historical cash-only organisations retain Finance after Community is switched off: `hasDuesMoney()` includes paid charges alongside ledger/payout history. Cash remains outside ledgers and payable; Community writing routes stay switched off.
- [x] Dues-only Finance emphasises dues instead of empty ticket totals; ticket-only props and both settlement readers remain unchanged. Current arrears/payable/in-flight are labelled independently of filtered collections. CSV amounts remain integer minor units and currencies never mix.

### Task 5: Verification

- [ ] Full suite, `pint --dirty`, phpstan, lint, JS tests, build (commit rebuilt `public/build` if JSX or CSS changed).
- [ ] Local: import the owner's sample sheet (ask for one, or use the fixture), run `dues:process` with reminders, check the Finance page.
- [ ] One whole-branch review by a fresh reviewer; fix Critical and Important findings with tests.
- [ ] Update `docs/superpowers/community-roadmap.md` (status `built`, evidence) and push.
