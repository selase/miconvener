# Community 1b: Charges, Periods and Commercial Terms Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Tick each step in this file in the same commit as the work.

**Goal:** Dues charge themselves: every period type (daily to yearly) is anchored in the organisation's timezone, a daily `dues:process` run raises missing charges (catching up after missed runs), and commission comes from one resolver whose terms are frozen when checkout starts.

**Architecture:** Builds on 1a (branch `community-1a`; names `MembershipType`, `membership_type_id`, `communityEnabled()`, `tenant.community.*`). New `community_settings` (per tenant). `DuesPeriodEnum` gains anchors. New `CommercialTerms` resolver with per-tenant, per-stream overrides set by the superadmin, used for dues now (giving later; tickets keep `PlatformFeeResolver`). New `dues_payment_attempts` table freezes the terms per checkout; the confirmer reads them. Generation lives in `DuesScheduler` and the `dues:process` command.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Pest 5, Inertia + React, Blade (superadmin console).

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md` sections 5.2, 6.1 (shared numbers), 6.3 (statuses), 7, 9, 10 item 1b.

## Global Constraints

- Money is integer minor units. Commission is never computed inline: everything goes through `CommercialTerms` (this replaces `DuesFee`). A stored `0.00` override is an explicit waiver; "no override" falls through.
- Defaults: dues stream 1% on paid plans, 2% on the Free plan (`package.is_free`, or no package), uncapped, bearer = organisation. Platform defaults in config: `services.dues.platform_fee_percentage` (1) and new `services.dues.free_plan_fee_percentage` (2).
- Only the application superadmin sets overrides: the existing `access-superadmin-dashboard` gate (see AGENTS.md "Only the application superadmin sets commission percentage or cap"). Ruling against the spec's new permission name: the codebase already has one superadmin gate for commission, and a second would split the rule.
- Every override change is audited (who, when, stream, old, new, reason).
- **Frozen terms:** a checkout creates a `dues_payment_attempts` row with the rate and per-charge commissions; confirmation uses that row. A webhook or callback without an attempt id (pay checkouts started before this deploy) falls back to the 1% config rate, which is what those checkouts were charged.
- **Tickets are untouched.** `PlatformFeeResolver` and the event cascade stay as they are; routing tickets through `CommercialTerms` is a later step.
- Existing charges keep their amount, currency and period. Reports use stored `commission_amount`.
- Timezone default `Africa/Accra`. Week starts Monday by default. Financial year starts January by default.
- PHP style, toolchain, PostgreSQL, Pint, phpstan and commit trailer as in the 1a plan.

## Review Focus

1. **A daily payer after a 3-day scheduler outage** gets exactly the missing three daily charges, no duplicates, on the next run (Task 3).
2. **A rate change between checkout and webhook** books the rate frozen at checkout (Task 2).
3. **Midnight boundaries:** a charge for "today" in Accra is the right date when the server runs in UTC and the run happens at 00:30 local (Task 1).
4. **A member who exits and rejoins** is not back-charged for the gap and is charged from the next period (Task 3).
5. **Two members sharing one phone number** can both be added, and each keeps their own charges and pay link (Task 4).

---

### Task 1: Community settings and anchored periods

**Files:**
- Create: migration `2026_10_16_100000_create_community_settings.php` (landlord), `app/Models/CommunitySettings.php`, factory; `tests/Feature/Community/DuesPeriodsTest.php`
- Modify: `app/Enum/DuesPeriodEnum.php` (add `Daily`, `Weekly`; anchor methods), `MembershipTypeRequest` (accept the new periods), membership type form copy in `resources/js/Pages/Tenant/Community/MoneyDues.jsx`

**Interfaces:**
- `community_settings`: `tenant_id` (unique), `timezone` (default `Africa/Accra`), `week_starts_on` (1–7, ISO, default 1), `financial_year_start_month` (1–12, default 1), `generate_days_ahead` (default 7), `charge_current_period_on_join` (bool, default false), timestamps. `CommunitySettings::for(Tenant): self` returns the row or an unsaved default.
- `DuesPeriodEnum::periodStartFor(CarbonImmutable $localDate, CommunitySettings $s): CarbonImmutable` (the anchor on or before the date), `nextStart(CarbonImmutable $start, CommunitySettings $s): CarbonImmutable`, `periodEnd(CarbonImmutable $start, CommunitySettings $s): CarbonImmutable` (= next start − 1 day). Anchors: daily = the date; weekly = the most recent `week_starts_on`; monthly = day 1; quarterly = 1 Jan/Apr/Jul/Oct; yearly = 1st of `financial_year_start_month`.

- [x] **Step 1: Failing tests** (`DuesPeriodsTest.php`), one per anchor plus edges:

```php
dataset('anchors', [
    'daily'     => ['daily', '2026-10-15', '2026-10-15', '2026-10-16'],
    'weekly'    => ['weekly', '2026-10-15', '2026-10-12', '2026-10-19'],   // Thursday → Monday
    'monthly'   => ['monthly', '2026-10-15', '2026-10-01', '2026-11-01'],
    'quarterly' => ['quarterly', '2026-11-20', '2026-10-01', '2027-01-01'],
    'yearly'    => ['yearly', '2026-10-15', '2026-01-01', '2027-01-01'],
]);

test('each period anchors to its start and knows the next', function (string $period, string $date, string $start, string $next) {
    $s = new App\Models\CommunitySettings(['timezone' => 'Africa/Accra', 'week_starts_on' => 1, 'financial_year_start_month' => 1]);
    $p = App\Enum\DuesPeriodEnum::from($period);
    $anchor = $p->periodStartFor(Carbon\CarbonImmutable::parse($date), $s);

    expect($anchor->toDateString())->toBe($start)
        ->and($p->nextStart($anchor, $s)->toDateString())->toBe($next)
        ->and($p->periodEnd($anchor, $s)->toDateString())->toBe(Carbon\CarbonImmutable::parse($next)->subDay()->toDateString());
})->with('anchors');

test('a financial year starting in April anchors yearly dues to 1 April', function () { /* 2026-10-15 → 2026-04-01, next 2027-04-01 */ });
test('a week starting on Sunday anchors weekly dues to Sunday', function () { /* week_starts_on 7: 2026-10-15 → 2026-10-11 */ });
test('monthly periods never drift on the 31st', function () { /* start 2026-01-01 → next 2026-02-01 → 2026-03-01; end of Jan is the 31st */ });
test('settings default when an organisation has none', function () { /* CommunitySettings::for($tenant) → Africa/Accra, 1, 1, 7, false */ });
```

- [x] **Step 2: Run, see them fail.** `"$PHP" artisan test --compact tests/Feature/Community/DuesPeriodsTest.php`. Expected: FAIL.
- [x] **Step 3: Implement** the migration, model and enum methods. Replace the old `nextStart`/`periodEnd` signatures; update their callers (`DuesCharges::createFor`).
- [x] **Step 4: Run** the new file and `tests/Feature/Dues`. Expected: PASS.
- [x] **Step 5: Commit** `feat(community): community settings and anchored dues periods`.

### Task 2: Commercial terms and frozen checkout terms

**Files:**
- Create: `app/Enum/CommercialStreamEnum.php` (`Tickets`, `Contributions`, `Giving`, `Dues`), `app/Services/Finance/CommercialTerms.php`, `app/Services/Finance/ResolvedTerms.php` (readonly value: `rate`, `capAmount`, `bearer`, `source` one of `override|plan|platform`, `isWaiver`), migrations `commercial_terms_overrides` (`tenant_id`, `stream`, `rate` decimal(5,2) not null, `cap_amount` nullable, `reason`, `set_by` user id, timestamps, unique `(tenant_id, stream)`) and `commercial_terms_changes` (audit: `tenant_id`, `stream`, `old_rate`, `new_rate`, `old_cap`, `new_cap`, `reason`, `changed_by`, `created_at`), and `dues_payment_attempts` (`id` uuid, `reference` unique — our own Paystack reference passed to `transaction/initialize`, `tenant_id`, `member_id`, `charge_ids` json (sorted), `currency`, `expected_amount`, `rate` decimal(5,2), `commissions` json map charge id → minor units, `terms_source`, `status` `open|confirmed|superseded`, `confirmed_at`, `created_at`); models for each; `tests/Feature/Community/CommercialTermsTest.php`, `tests/Feature/Community/FrozenTermsTest.php`
- Modify: `app/Services/Payment/PaystackGateway.php` (`createOneTimeCheckoutSession` accepts an optional reference, sent to Paystack as `reference`), `app/Services/Dues/DuesCheckout.php` (create the attempt with its reference; metadata `dues_attempt_id`; platform share = sum of the attempt's commissions), `app/Services/Dues/DuesPaymentConfirmer.php` (resolve the attempt and require **all** of: gateway reference = attempt reference, tenant, member, exact charge set, currency, and paid amount ≥ expected amount; on any mismatch log `dues.payment.attempt_mismatch`, note the charges and book nothing; a confirmed attempt makes redelivery a no-op; fallback for payments with no attempt id), delete `app/Services/Dues/DuesFee.php` and its usages, superadmin tenant page (Blade, `Admin\TenantController` + its view) to view and set per-stream overrides with a reason, `config/services.php` (`dues.free_plan_fee_percentage`).

**Interfaces:**
- `CommercialTerms::for(Tenant $tenant, CommercialStreamEnum $stream): ResolvedTerms`; `CommercialTerms::commission(ResolvedTerms $terms, int $amount): int` (rounding half up, cap applied, never negative). Precedence: override row → plan default (Free 2%, otherwise 1% for dues) → platform config.
- `CommercialTerms::setOverride(Tenant, CommercialStreamEnum, ?float $rate, ?int $cap, string $reason, User $by): void` (null rate removes the override; writes the audit row in the same transaction).

- [ ] **Step 1: Failing tests:**

```php
test('dues default to 1% on a paid plan and 2% on Free', function () {});
test('an override wins, and an override of 0 is an explicit waiver', function () {
    // setOverride(… 0.0 …) → rate 0.0, isWaiver true, source override; commission(…, 10000) === 0
});
test('removing an override falls back to the plan default', function () {});
test('every override change is audited', function () {});
test('only the application superadmin can change terms', function () {
    // Org Superadmin POST to the superadmin route → 403; superadmin → 302 and row written
});
test('commission rounds half up and respects a cap', function () { /* 1% of 150 → 2; cap 50 on 10_000_000 → 50 */ });
```

  `FrozenTermsTest.php`:

```php
test('checkout freezes the rate on an attempt and sends the matching split share', function () {
    // tenant on 1%; start checkout for charges 10000 + 5050 → attempt row rate 1.00, commissions {a:100, b:51}; Paystack initialize body transaction_charge reflects 151
});
test('a rate change after checkout does not change what is booked', function () {
    // start checkout at 1%; superadmin sets 3%; deliver webhook with dues_attempt_id → commission_amount 100 and 51; ledger platform revenue 151
});
test('a webhook without an attempt id books the legacy 1% rate', function () {});
test('an attempt from another tenant is ignored', function () { /* forged dues_attempt_id → flagged, nothing booked */ });
test('an attempt from the same organisation but another member or charge set is refused', function () {});
test('an attempt whose reference, currency or amount does not match the payment is refused', function () {});
test('a retried checkout supersedes the earlier open attempt for the same charges', function () {});
test('the callback and the webhook for one payment book it once, in either order', function () {});
```

- [ ] **Step 2: Run, see them fail.**
- [ ] **Step 3: Implement.** Keep `DuesPaymentConfirmer`'s apportioning and own-gateway allocation exactly as they are, replacing only where per-charge commissions come from. Delete `DuesFee` and update its tests to the resolver (no test may lose its assertion; rename, do not delete).
- [ ] **Step 4: Run** `tests/Feature/Community tests/Feature/Dues tests/Feature/Finance tests/Feature/Payments`. Expected: PASS, with the same per-file counts for Finance and Payments as before the task.
- [ ] **Step 5: Commit** `feat(finance): commercial terms resolver with frozen dues checkout terms`.

### Task 3: Charge generation (`dues:process`)

**Files:**
- Create: `app/Services/Dues/DuesScheduler.php`, `app/Console/Commands/Dues/ProcessDuesCommand.php` (`dues:process {--tenant=} {--date=}`), `tests/Feature/Community/DuesGenerationTest.php`
- Modify: `app/Services/Dues/DuesCharges.php` (`createFor` uses the anchored periods and settings), `app/Console/Kernel.php` (schedule `dues:process` hourly `withoutOverlapping`; each tenant is processed when its local time has passed 00:30 and it has not been processed for that local date), `MemberController` + routes (`members.rejoin`), `People.jsx` (Rejoin action for exited members).

**Interfaces:**
- New member columns: `active_since` (date; backfilled from `joined_on` or the creation date; set again on rejoin and when a lapsed member is made active) and `lapsed_on` (nullable).
- `DuesScheduler::generateFor(Tenant $tenant, CarbonImmutable $localToday): int` (number created). For each member that is `active`, whose type `hasDues()` and `is_active`:
  - **Eligible window:** from the first eligible period (the period containing `active_since` when `charge_current_period_on_join`, otherwise the next period after `active_since`) to `localToday + generate_days_ahead`.
  - **Bounded search for gaps, not "after the latest charge":** walk every period start in the window and create each one that has no charge (any status), oldest first, so an earlier gap is filled even when a later charge exists. The window is capped at the most recent 400 periods (logged when hit).
  - Override of 0 → the charge is created as `waived`.
  - **Effective-date rules:** a membership type or override change applies to periods with no charge yet; charges already generated (including future ones inside the horizon) keep their amount; an admin can void an open future charge to have it regenerated with the new terms. Exit ends eligibility from `exited_on`; rejoin opens a new window at the rejoin date; periods between exit and rejoin, or while lapsed, are never charged.
- Members `pending`, `lapsed`, `exited` get none. Types without dues or inactive get none; inactive types' members are listed on Money > Dues (a notice with a count).
- `members.rejoin`: `exited` → `active`, sets `rejoined_on` (new nullable date column on `members`) and clears `exited_on`.

- [ ] **Step 1: Failing tests** covering: weekly and daily generation; a gap before an existing later charge is filled; nothing charged while lapsed; catch-up after missed runs (three missing days created once; a second run creates none); join mid-period both settings; exit stops charges from the exit date; rejoin resumes from the next period with no back-charges; type change affects only new charges; override 0 → waived; pending/lapsed/exited and types without dues or inactive → none; per-tenant local midnight (server clock UTC, `--date`); the 400 cap; two tenants processed independently.
- [ ] **Step 2: Run, see them fail.**
- [ ] **Step 3: Implement.** Idempotency rests on the existing unique `(member_id, period_start)` plus `firstOrCreate`.
- [ ] **Step 4: Run** `tests/Feature/Community tests/Feature/Dues`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(community): dues:process raises charges for every period type`.

### Task 4: Shared phone numbers

**Files:**
- Create: migration `2026_10_16_100300_allow_shared_member_phones.php` (drop unique `(tenant_id, phone)`, add a non-unique index; add unique `(tenant_id, phone, name)` as the duplicate guard), `tests/Feature/Community/SharedNumbersTest.php`
- Modify: `MemberRequest` (replace the phone-unique rule with name + phone uniqueness; keep the email rule for members with no phone), `MembersTest.php` cases that asserted phone uniqueness (update to the new rule, keep them).

- [x] **Step 1: Failing tests:** two members with the same phone and different names → both created; same name and phone → 422 with "This person is already a member."; each keeps their own pay link and charges; the pay page for one never shows the other's charges.
- [x] **Step 2: Run, see them fail.** **Step 3: Implement.** **Step 4: Run** `tests/Feature/Community tests/Feature/Dues`.
- [x] **Step 5: Commit** `feat(community): members may share a phone number`.


Ruling: Add a canonical PostgreSQL unique index as well as the raw composite key. Request validation alone cannot prevent concurrent equivalent name/phone identities. Stored contact values remain unchanged; migration refuses ambiguous legacy duplicates without deleting data. Cost if wrong: resolve duplicates before migrating.

### Task 5: Verification

- [ ] Full suite, `pint --dirty`, phpstan, lint, JS tests, build (commit rebuilt `public/build` if JSX or CSS changed).
- [ ] Local: migrate, run `"$PHP" artisan dues:process --tenant=purpledot`, check charges appear for the local member.
- [ ] One whole-branch review by a fresh reviewer; fix Critical and Important findings with tests.
- [ ] Update `docs/superpowers/community-roadmap.md` (status `built`, evidence) and push.
