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
- The tenant Finance page opens when `handlesTicketMoney()` **or** `communityEnabled()`; existing ticket figures are unchanged.
- PHP style, toolchain, PostgreSQL, Pint, phpstan and commit trailer as in the 1a plan.

## Review Focus

1. **A 3,000-row import with 40 bad rows** saves nothing until confirmed, reports each bad row with its line number and reason, and saves the good rows once (Task 1).
2. **A daily payer** receives at most one reminder a day (or one a week if the organisation chose the weekly digest), listing every open charge (Task 2).
3. **An organisation out of SMS credit** still gets email reminders sent and SMS reminders recorded as skipped (Task 2).
4. **Recorded cash** appears in collections but never in available to pay out (Task 4).
5. **A downgrade below the member count** keeps every member and charge, and blocks only new additions (Task 3).

---

### Task 1: Spreadsheet import

**Files:**
- Create: `app/Services/Community/MemberImport.php`, `app/Http/Controllers/Tenant/MemberImportController.php`, `app/Http/Requests/Tenant/Community/{UploadMemberImportRequest,ConfirmMemberImportRequest}.php`, `resources/js/Pages/Tenant/Community/ImportMembers.jsx`, `tests/Feature/Community/MemberImportTest.php`, fixtures under `tests/Fixtures/community/` (a good CSV, a mixed CSV, an XLSX)
- Modify: routes (`tenant.community.people.import.{show,preview,confirm}`), `People.jsx` ("Import" button), a downloadable template (`tenant.community.people.import.template`, CSV with headers `name,phone,email,membership_type,joined_on`).

**Interfaces:**
- `MemberImport::preview(Tenant, UploadedFile): ImportPreview` with `rows` (`line`, `name`, `phone`, `email`, `membership_type`, `joined_on`, `status` one of `new|duplicate|existing|error`, `errors`), summary counts, and a `token` (preview stored in cache 30 minutes, tenant-scoped). Max 10,000 rows, 5 MB, CSV or XLSX.
- `MemberImport::confirm(Tenant, string $token, string $existingPolicy /* skip|update */, int $userId): ImportResult` inside one transaction; respects the member cap (refuses the whole import with a clear message if the new rows would exceed it).
- Row rules: name required; phone or email required; phone normalised (Ghana local `0XXXXXXXXX` and `+233…` to E.164); membership type matched by name case-insensitively within the tenant and must be active; duplicate inside the file = same name + phone (or name + email when no phone); existing = same rule against the register.

- [ ] **Step 1: Failing tests:** preview saves nothing; per-row errors with line numbers (missing contact, unknown type, inactive type, bad date, bad phone); in-file duplicates; existing members skipped or updated by policy; XLSX works; confirm creates exactly the good rows once (second confirm with the same token → 410 "This import has already been used"); a token from another tenant → 404; over-cap import refused whole; permission `manage members` required; switch off → 404.
- [ ] **Step 2: Run, see them fail.** **Step 3: Implement** (page: upload, preview table with filters for errors, policy choice, confirm). **Step 4: Run** `tests/Feature/Community`.
- [ ] **Step 5: Commit** `feat(community): import members from a spreadsheet with preview`.

### Task 2: Reminders and the arrears button

**Files:**
- Create: migration (`dues_reminders`: `id`, `tenant_id`, `member_id`, `step` one of `before|on_day|after_7|after_30|digest|manual`, `channel` `email|sms`, `charge_ids` json, `status` `sent|skipped|failed`, `reason` nullable, `sent_for_date` date, `created_at`; unique `(member_id, step, channel, sent_for_date)`), add to `community_settings`: `reminders_enabled` (true), `reminder_offsets` json (default `{"before":7,"on_day":0,"after_7":7,"after_30":30}`), `frequent_digest` (`daily|weekly`, default `daily`, applies to daily and weekly periods), `sms_monthly_limit` (nullable int); `app/Services/Dues/DuesReminders.php`, `app/Mail/DuesReminder.php` + view, `tests/Feature/Community/DuesRemindersTest.php`
- Modify: `ProcessDuesCommand` (phase 2 after generation), routes `tenant.community.money.dues.remind-arrears`, Overview settings tab (reminders on/off, offsets, digest, SMS monthly limit) with a Form Request, `MoneyDues.jsx` ("Remind everyone in arrears" with a confirm showing the count and SMS credits needed).

**Interfaces:**
- `DuesReminders::sendDue(Tenant, CarbonImmutable $localToday): array{sent: int, skipped: int}`: for each member with open charges, decide today's step: monthly, quarterly and yearly charges use the offsets per charge (but one message per member per day lists all open charges); daily and weekly charges use the digest (daily: one per day while anything is open; weekly: on `week_starts_on`). Email when the member has an email; SMS when they have a phone, SMS is enabled for the tenant, credits remain and the monthly limit is not reached.
- `DuesReminders::remindArrears(Tenant, int $userId): array{sent: int, skipped: int}`: step `manual`, at most once per member per local day.

- [ ] **Step 1: Failing tests:** the four offsets for a monthly charge; one message listing several open charges; daily digest once per day; weekly digest only on the week start; reminders off → none; no credit → email sent, SMS skipped with reason; monthly SMS limit reached → skipped; arrears button sends once per day per member and needs `manage members`; message content has pay link, organisation name, total and oldest due date; white-label from/reply-to; tenant isolation.
- [ ] **Step 2–4:** fail, implement, pass (`tests/Feature/Community tests/Feature/Dues tests/Feature/Mail`).
- [ ] **Step 5: Commit** `feat(community): automatic dues reminders and the arrears button`.

### Task 3: Member caps by plan

**Files:**
- Modify: `database/seeders/EventPackageSeeder.php` (feature `community_members`: free 50, starter 500, growth 5000, enterprise unlimited, following the seeder's existing limit-feature convention), `app/Services/Community/MemberCap.php` (create), `MemberController` (store, and approve when it exists), `MemberImport::confirm`, `People.jsx` (usage "312 of 500 members" and the over-cap banner); `tests/Feature/Community/MemberCapTest.php`

**Interfaces:**
- `MemberCap::limit(Tenant): ?int`, `MemberCap::counted(Tenant): int` (`pending` + `active` + `lapsed`), `MemberCap::assertRoomFor(Tenant, int $adding): void` (throws `DomainException` "Your {Plan} plan includes {n} members. …").

- [ ] **Step 1: Failing tests:** add refused at the cap with the plan name and number; exited members do not count; a downgrade below the count keeps everyone, charges keep generating, adding is blocked; enterprise unlimited; the package feature reaches existing tenants through `tenants:sync-features`.
- [ ] **Step 2–4:** fail, implement, pass.
- [ ] **Step 5: Commit** `feat(community): member caps by plan`.

### Task 4: Finance for dues

**Files:**
- Modify: `app/Http/Controllers/Tenant/FinanceController.php` (open for `communityEnabled()`; add a dues section), its page under `resources/js/Pages/Tenant/Finance/Index.jsx`; create `app/Services/Dues/DuesReport.php`, export routes `tenant.finance.dues.collections.export` and `tenant.finance.dues.arrears.export`; `tests/Feature/Community/DuesFinanceTest.php`

**Interfaces:**
- `DuesReport::summary(Tenant, CarbonImmutable $from, CarbonImmutable $to): array` per currency: `collected_online_gross`, `gateway_fees`, `commission`, `net_online`, `recorded_cash`, `collected_total` (online gross + cash), `available_to_pay_out` (from `DuesBalance`), `in_flight`, `arrears_total`, `members_in_arrears`.
- Collections CSV: one row per paid charge in the range (member, phone, email, type, period, amount, method, paid at, reference, gateway fee, commission, net). Arrears CSV: one row per member in arrears (member, contacts, type, open charges, total, oldest due date).

- [ ] **Step 1: Failing tests:** a dues-only tenant (no ticket money) can open Finance; a ticket tenant without Community sees exactly what it saw before (snapshot of the existing props); recorded cash in collections and never in available; own-gateway tenant: collections from charges, available 0; date range and currency grouping; CSV headers and rows; `read finance` required; tenant isolation.
- [ ] **Step 2–4:** fail, implement, pass (`tests/Feature/Community tests/Feature/Finance`).
- [ ] **Step 5: Commit** `feat(finance): dues collections, cash, arrears and payable in Finance`.

### Task 5: Verification

- [ ] Full suite, Pint, phpstan, lint, JS tests, build.
- [ ] Local: import the owner's sample sheet (ask for one, or use the fixture), run `dues:process` with reminders, check the Finance page.
- [ ] One whole-branch review by a fresh reviewer; fix Critical and Important findings with tests.
- [ ] Update `docs/superpowers/community-roadmap.md` (status `built`, evidence) and push.
