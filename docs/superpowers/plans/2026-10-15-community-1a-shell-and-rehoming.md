# Community 1a: Shell and Re-homing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Tick each step in this file in the same commit as the work.

**Goal:** Give the console a grouped menu with a new Community section, and move the milestone 2 dues screens into it (members under People, membership types under Money, payouts under Finance) without changing any money logic or any public URL.

**Architecture:** Work on branch `community-1a` (cut from `member-dues-m2` at `61224d2`, with the Community spec merged in). A new migration renames `dues_classes` to `membership_types` and makes amount and period optional. The `member_dues` rollout switch becomes `community`. Console routes move from `/dues/*` to `/community/*` and `/finance/payouts`, with redirects from the old console URLs. The console menu becomes data-driven groups in a small JS module that is unit tested.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Pest 5, Inertia + React, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md` (sections 4, 9, 10 item 1a, 13.1, 14, 15).

## Global Constraints

- **No money logic changes.** Fee calculation, ledger postings, checkout, webhooks, payouts and reconciliation behave exactly as on `member-dues-m2`. Their tests must pass unchanged except for renamed identifiers.
- **Public URLs do not change:** `/pay/{token}`, `/pay/{token}/checkout`, `/pay/{token}/callback`, `/pay/{token}/receipt`. Paystack metadata `type = member_dues` stays accepted. Route names `public.dues.*` stay.
- **Old console URLs redirect** (`/dues/members` → `/community/people`, `/dues/classes` → `/community/money/dues`, `/dues/payouts` → `/finance/payouts`) with 301 for GET; old POST/PATCH console endpoints are removed (only the console calls them).
- **Rollout switch:** feature key `community`, admin-override row, command `php artisan community:enable {tenant} {--off}`. Permissions unchanged: `manage members`, `read finance`, `manage payouts`.
- **Renames use a new migration**, never an edit of `2026_10_14_100000_create_dues_tables.php` (the owner's local database has already run it).
- Naming: "Membership type" in UI and code (`MembershipType`, `membership_types`, `membership_type_id`). Never "class" in new UI copy.
- Hidden, not "coming soon": Community items not built yet (Messages, Library, Polls; Followers and Groups tabs) do not appear.
- PHP: `declare(strict_types=1)`, final classes, explicit return types, PHPDoc over inline comments, Form Requests. Run `vendor/bin/pint --dirty` and phpstan (0 errors, baseline must not grow) before each commit.
- Toolchain: `export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; PHP="$HOME/Library/Application Support/Herd/bin/php"`. PostgreSQL only; never drop local databases by pattern.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A pay link sent before this change** still opens, checks out, confirms by webhook and shows its receipt (Task 3 test).
2. **Renamed foreign keys** on existing local data: the rename migration keeps rows, constraints and the unique `(tenant_id, name)` rule (Task 1 test).
3. **A tenant without the community switch** sees no Community or Payouts menu and gets 404 on every `/community/*` and `/finance/payouts` URL, while `/pay/{token}` behaves as before (Task 2 and 3 tests).
4. **Menu groups with no visible items disappear**, including in the compact icon rail inside an event (Task 4 tests).
5. **A membership type without an amount** can be created and members added to it, and creating a charge for such a member is refused with a clear message (Task 1 test).

---

### Task 1: Membership types (rename and optional amount)

**Files:**
- Create: `database/migrations/landlord/2026_10_15_100000_rename_dues_classes_to_membership_types.php`
- Rename: `app/Models/DuesClass.php` → `app/Models/MembershipType.php`; `database/factories/DuesClassFactory.php` → `MembershipTypeFactory.php`; `app/Http/Controllers/Tenant/DuesClassController.php` → `MembershipTypeController.php`; `app/Http/Requests/Tenant/Dues/{Store,Update}DuesClassRequest.php` and `DuesClassRequest.php` → `{Store,Update}MembershipTypeRequest.php`, `MembershipTypeRequest.php`; `tests/Feature/Dues/DuesClassesTest.php` → `MembershipTypesTest.php`
- Modify: every file that references `DuesClass`, `dues_class_id`, `duesClass`, `dues_classes` (`grep -rln "dues_class\|DuesClass\|duesClass" app resources tests database routes` lists them; about 29 files), including `Member`, `DuesCharge`, `DuesCharges`, `MemberController`, `MemberRequest`, `DuesChargeController`, factories, JSX pages and tests.

**Interfaces:**
- Produces: `App\Models\MembershipType` (table `membership_types`, fillable `tenant_id, name, amount, currency, period, is_active`; `amount` and `period` nullable; `hasDues(): bool` = `amount !== null`); `Member::membershipType(): BelongsTo` (column `membership_type_id`); `DuesCharge::membershipType()` (column `membership_type_id`); `DuesCharges::createFor()` throws `DomainException('This membership type has no dues.')` when `! hasDues()`.

- [ ] **Step 1: Write the failing tests** in `tests/Feature/Dues/MembershipTypesTest.php` (renamed from `DuesClassesTest.php`, keeping its existing cases with renamed identifiers) and add:

```php
test('the rename keeps existing types, members and charges', function () {
    $type = App\Models\MembershipType::factory()->create(['name' => 'Full member', 'amount' => 5000, 'period' => 'monthly']);
    $member = App\Models\Member::factory()->create(['tenant_id' => $type->tenant_id, 'membership_type_id' => $type->id]);

    expect($member->membershipType->is($type))->toBeTrue()
        ->and(Illuminate\Support\Facades\Schema::connection('landlord')->hasTable('dues_classes'))->toBeFalse();
});

test('type names stay unique per organisation after the rename', function () {
    $type = App\Models\MembershipType::factory()->create(['name' => 'Full member']);

    expect(fn () => App\Models\MembershipType::factory()->create(['tenant_id' => $type->tenant_id, 'name' => 'Full member']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('a membership type can have no dues', function () {
    // POST tenant.community.money.dues.store (route from Task 3; until then the existing store route)
    // with name only → 201; row has amount null and period null.
});

test('amount and period come together', function () {
    // amount without period → 422 on period; period without amount → 422 on amount.
});

test('a member of a type without dues cannot be charged', function () {
    // createFor() on such a member → DomainException 'This membership type has no dues.'; the HTTP charge endpoint → 422 with that message.
});
```

Write the three commented tests in full using the request pattern already used in `DuesClassesTest.php` (eventHost, enable the switch, actingAs, subdomain host). Until Task 3 lands, call the existing route names; Task 3 renames them.

- [ ] **Step 2: Run them and see them fail**

Run: `"$PHP" artisan test --compact tests/Feature/Dues/MembershipTypesTest.php`
Expected: FAIL (class `MembershipType` not found).

- [ ] **Step 3: Migration**

```php
public function up(): void
{
    $schema = Schema::connection('landlord');
    $schema->rename('dues_classes', 'membership_types');
    $schema->table('membership_types', function (Blueprint $t): void {
        $t->unsignedBigInteger('amount')->nullable()->change();
        $t->string('period', 16)->nullable()->change();
    });
    $schema->table('members', fn (Blueprint $t) => $t->renameColumn('dues_class_id', 'membership_type_id'));
    $schema->table('dues_charges', fn (Blueprint $t) => $t->renameColumn('dues_class_id', 'membership_type_id'));
    DB::connection('landlord')->statement(
        "ALTER TABLE membership_types ADD CONSTRAINT membership_types_dues_pair_check CHECK ((amount IS NULL) = (period IS NULL))"
    );
}
```

`down()` reverses it in the opposite order (drop the check, rename columns back, set amount and period NOT NULL only if no nulls exist, otherwise throw a clear exception, rename the table back). Postgres keeps foreign keys and indexes through renames; the existing index and constraint names stay as they are (cosmetic).

- [ ] **Step 4: Rename the code** (models, factory, controller, requests, relations, columns, JSX props `classes` → `membershipTypes`, copy "Class" → "Membership type"), make the request rules `amount` `nullable|integer|min:100|required_with:period` and `period` `nullable|required_with:amount` (keep the existing currency and period lock rules, applied only when the type has dues), and make `DuesCharges::createFor()` refuse types without dues.

- [ ] **Step 5: Run the dues suite**

Run: `"$PHP" artisan test --compact tests/Feature/Dues`
Expected: all pass (existing cases renamed, new cases green).

- [ ] **Step 6: Commit**

```bash
"$PHP" vendor/bin/pint --dirty
git add -A app database resources tests
git commit -m "refactor(community): membership types replace dues classes, dues optional"
```

---

### Task 2: The community rollout switch

**Files:**
- Rename: `app/Console/Commands/Dues/EnableDuesCommand.php` → `app/Console/Commands/Community/EnableCommunityCommand.php` (`community:enable {tenant} {--off}`); `app/Http/Middleware/EnsureDuesEnabled.php` → `EnsureCommunityEnabled.php` (alias `community.enabled` in `app/Http/Kernel.php`); `tests/Feature/Dues/DuesGateTest.php` → `tests/Feature/Community/CommunityGateTest.php`
- Create: `database/migrations/landlord/2026_10_15_100100_rename_member_dues_feature_rows.php` (updates `tenant_features.feature_key` from `member_dues` to `community`; down reverses)
- Modify: `app/Models/Tenant.php` (`duesEnabled()` → `communityEnabled()`), `app/Http/Middleware/HandleInertiaRequests.php` (`tenant.features.community`), every caller (`grep -rn "duesEnabled\|member_dues\|dues.enabled\|dues:enable" app routes tests resources`).

**Interfaces:**
- Produces: `Tenant::communityEnabled(): bool`; middleware alias `community.enabled`; command `community:enable`; shared prop `tenant.features.community`.
- Keeps: Paystack metadata `type = member_dues` (that string is a payment type, not the switch; do not rename it).

- [ ] **Step 1: Write the failing tests** (move and rename the existing gate tests, then add):

```php
test('community:enable writes the community switch as an admin override that survives a feature sync', function () { /* as the old dues:enable test, with 'community' */ });
test('an existing member_dues row becomes a community row', function () {
    // insert tenant_features row feature_key member_dues enabled, run the migration's up() via artisan migrate on the test DB state
    // (or call the migration class directly) → row feature_key is community, enabled true, meta source admin_override.
});
test('the shared props expose tenant.features.community', function () { /* Inertia assertion */ });
```

- [ ] **Step 2: Run, see them fail.** Run: `"$PHP" artisan test --compact tests/Feature/Community/CommunityGateTest.php`. Expected: FAIL (command not found).
- [ ] **Step 3: Implement** the renames and the data migration.
- [ ] **Step 4: Run** `"$PHP" artisan test --compact tests/Feature/Community tests/Feature/Dues`. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(community): one community rollout switch replaces member_dues`.

---

### Task 3: Routes and pages under Community and Finance

**Files:**
- Modify: `routes/subdomain.php` (replace the `dues` console group with the groups below; keep the `pay/{token}` group as is apart from the middleware alias)
- Rename pages: `resources/js/Pages/Tenant/Dues/Members.jsx` → `resources/js/Pages/Tenant/Community/People.jsx`; `MemberFormModal.jsx` → `Tenant/Community/MemberFormModal.jsx`; `Classes.jsx` → `Tenant/Community/MoneyDues.jsx`; `Payouts.jsx` → `Tenant/Finance/Payouts.jsx`
- Create: `app/Http/Controllers/Tenant/CommunityOverviewController.php`, `resources/js/Pages/Tenant/Community/Overview.jsx`, `resources/js/Components/Community/CommunityTabs.jsx`
- Modify: controllers' `Inertia::render` names and route names; `tests/Feature/Dues/*` route names; `tests/Feature/RoutesResolveTest.php` expectations if it lists pages; JS tests under `resources/js/test` that import the moved pages.

**Interfaces:**
- Route names and URLs (all inside the authenticated tenant group):

```php
Route::middleware('community.enabled')->group(function (): void {
    Route::prefix('community')->name('tenant.community.')->group(function (): void {
        Route::get('/', [CommunityOverviewController::class, 'index'])->name('overview');
        Route::get('people', [MemberController::class, 'index'])->name('people.index');
        Route::post('people/members', [MemberController::class, 'store'])->name('members.store');
        Route::patch('people/members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::post('people/members/{member}/exit', [MemberController::class, 'exit'])->name('members.exit');
        Route::post('people/members/{member}/rotate-link', [MemberController::class, 'rotateLink'])->name('members.rotate-link');
        Route::get('money/dues', [MembershipTypeController::class, 'index'])->name('money.dues.index');
        Route::post('money/dues/types', [MembershipTypeController::class, 'store'])->name('money.dues.store');
        Route::patch('money/dues/types/{type}', [MembershipTypeController::class, 'update'])->name('money.dues.update');
        Route::post('money/dues/charges', [DuesChargeController::class, 'store'])->name('charges.store');
        Route::post('money/dues/charges/{charge}/waive', [DuesChargeController::class, 'waive'])->name('charges.waive');
        Route::post('money/dues/charges/{charge}/void', [DuesChargeController::class, 'void'])->name('charges.void');
        Route::post('money/dues/charges/{charge}/record-offline', [DuesChargeController::class, 'recordOffline'])->name('charges.record-offline');
    });
    Route::prefix('finance/payouts')->name('tenant.finance.payouts.')->group(function (): void {
        Route::get('/', [DuesPayoutController::class, 'index'])->name('index');
        Route::post('/', [DuesPayoutController::class, 'store'])->name('store');
        Route::post('{payout}/send', [DuesPayoutController::class, 'send'])->name('send');
        Route::post('{payout}/finalize', [DuesPayoutController::class, 'finalize'])->name('finalize');
    });
    Route::permanentRedirect('dues/members', 'community/people');
    Route::permanentRedirect('dues/classes', 'community/money/dues');
    Route::permanentRedirect('dues/payouts', 'finance/payouts');
});
```

  Check that `finance/payouts` does not collide with an existing route (`php artisan route:list --path=finance`). If it does, stop and report.
- Pages: `Tenant/Community/Overview` (props: `counts: {members, activeMembers, openCharges, overdueCharges}`, `collectedThisMonth: [{currency, amount}]` from paid online dues charges, `links`), `Tenant/Community/People` (the old Members page with a `CommunityTabs` header showing only **Members**), `Tenant/Community/MoneyDues` (the old Classes page, titled "Dues", tab header showing only **Dues**), `Tenant/Finance/Payouts` (the old Payouts page, titled "Payouts", copy says these are dues payouts for now; event payouts stay inside each event's Finance tab).
- The member drawer's charge list and the dues pages keep every existing action.

- [ ] **Step 1: Write the failing tests** in `tests/Feature/Community/CommunityRoutesTest.php`:

```php
test('every community and payouts page renders for an enabled organisation', function () {
    // GET tenant.community.overview → component Tenant/Community/Overview with counts;
    // tenant.community.people.index → Tenant/Community/People with members.data;
    // tenant.community.money.dues.index → Tenant/Community/MoneyDues with membershipTypes;
    // tenant.finance.payouts.index → Tenant/Finance/Payouts.
});
test('every community and payouts URL is a 404 while the switch is off', function () {});
test('old console URLs redirect permanently to their new homes', function () {
    // GET /dues/members → 301 to /community/people; /dues/classes → /community/money/dues; /dues/payouts → /finance/payouts.
});
test('a pay link issued before the move still works end to end', function () {
    // create member + charge; GET /pay/{token} OK; POST checkout (Http::fake Paystack) redirects to Paystack;
    // signed charge.success webhook with metadata type member_dues confirms the charge; GET receipt shows it paid.
});
test('the overview counts only this organisation', function () { /* two tenants; counts differ */ });
```

Write each in full following `tests/Feature/Dues/DuesEndToEndTest.php` and `DuesPagesRenderTest.php`.

- [ ] **Step 2: Run, see them fail.** Expected: FAIL (route names not defined).
- [ ] **Step 3: Implement** routes, controller renders, page moves, `CommunityTabs` (a row of tab links; receives `tabs: [{label, href, active}]`; a single tab still renders, as the section label, so the layout does not jump when more tabs arrive), Overview page (console `PageHeader`, three stat cards, links to People, Dues and Payouts). Update every renamed route name in controllers, JSX `route(...)` calls and tests.
- [ ] **Step 4: Run** `"$PHP" artisan test --compact tests/Feature/Community tests/Feature/Dues tests/Feature/RoutesResolveTest.php` and `npm run test`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(community): community section routes and pages; payouts under Finance`.

---

### Task 4: Grouped console navigation

**Files:**
- Create: `resources/js/Layouts/consoleNav.js`, `resources/js/test/console/consoleNav.test.js`
- Modify: `resources/js/Layouts/ConsoleLayout.jsx` (render groups from the module)

**Interfaces:**
- `consoleNav.js` exports `NAV_GROUPS` and `visibleGroups(groups, { features, can })`:

```js
export const NAV_GROUPS = [
    { key: 'top', items: [{ label: 'Dashboard', href: 'tenant.dashboard', icon: 'LayoutDashboard' }] },
    { key: 'events', label: 'Events', items: [{ label: 'Events', href: 'tenant.events.index', icon: 'Calendar' }] },
    {
        key: 'community', label: 'Community', items: [
            { label: 'Overview', href: 'tenant.community.overview', icon: 'LayoutGrid', feature: 'community' },
            { label: 'People', href: 'tenant.community.people.index', icon: 'UsersRound', feature: 'community', permission: 'manage_members' },
            { label: 'Money', href: 'tenant.community.money.dues.index', icon: 'Coins', feature: 'community', permission: 'manage_members' },
        ],
    },
    {
        key: 'venue', label: 'Venue', items: [
            { label: 'Facility operations', href: 'tenant.venue.operations.index', icon: 'ClipboardCheck', permission: 'manage_venue' },
            { label: 'Spaces', href: 'tenant.venue.spaces.index', icon: 'Building2', permission: 'manage_venue' },
            { label: 'Calendar', href: 'tenant.venue.calendar.index', icon: 'CalendarDays', permission: 'manage_venue' },
            { label: 'Inquiries & leads', href: 'tenant.venue.inquiries.index', icon: 'Inbox', permission: 'manage_venue' },
            { label: 'RFQs & proposals', href: 'tenant.venue.quotes.index', icon: 'FileText', permission: 'manage_venue' },
        ],
    },
    {
        key: 'finance', label: 'Finance', items: [
            { label: 'Overview', href: 'tenant.finance.index', icon: 'Wallet', feature: 'finance', permission: 'read_finance' },
            { label: 'Payouts', href: 'tenant.finance.payouts.index', icon: 'HandCoins', feature: 'community', permission: 'read_finance' },
        ],
    },
    {
        key: 'organisation', label: 'Organisation', items: [
            { label: 'Team', href: 'tenant.users.index', icon: 'Users' },
            { label: 'Roles', href: 'tenant.roles.index', icon: 'ShieldCheck' },
            { label: 'Billing', href: 'billing.index', icon: 'CreditCard', permission: 'manage_billing' },
            { label: 'Settings', href: 'tenant.settings.index', icon: 'Settings' },
            { label: 'API keys', href: 'tenant.api-keys.index', icon: 'KeyRound' },
            { label: 'AI usage', href: 'tenant.llm-usage.index', icon: 'Sparkles' },
            { label: 'AI configuration', href: 'tenant.llm-config.index', icon: 'SlidersHorizontal', feature: 'llm_byok' },
        ],
    },
    { key: 'bottom', items: [{ label: 'My account', href: 'tenant.account', icon: 'UserRound' }] },
];

export function visibleGroups(groups, { features = {}, can = {} } = {}) {
    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => (!item.feature || features[item.feature]) && (!item.permission || can[item.permission])
            ),
        }))
        .filter((group) => group.items.length > 0);
}
```

  Icons are names mapped to lucide components inside the layout (the module stays free of React so it is trivially testable). Item labels and order above are the spec's section 14; "Refunds" is not a separate page today (refunds live on the Finance page), so it is not listed.
- Layout behaviour: a group label renders above its items (not in the `top` and `bottom` groups); groups collapse by clicking the label; the collapsed state is stored per group in `localStorage` under `console.nav.collapsed.{key}` wrapped in try/catch (render expanded when storage throws); the group holding the current page is always expanded; in `compact` mode (icon rail inside an event) labels are hidden and a thin divider separates groups; the phone drawer shows the same groups.

- [ ] **Step 1: Write the failing Vitest tests** (`resources/js/test/console/consoleNav.test.js`):

```js
import { describe, expect, it } from 'vitest';
import { NAV_GROUPS, visibleGroups } from '@/Layouts/consoleNav';

describe('visibleGroups', () => {
    it('hides the Community group without the community feature', () => {
        const keys = visibleGroups(NAV_GROUPS, { features: {}, can: { manage_members: true } }).map((g) => g.key);
        expect(keys).not.toContain('community');
    });

    it('hides the Venue group for organisations without manage_venue', () => {
        const keys = visibleGroups(NAV_GROUPS, { features: { community: true }, can: {} }).map((g) => g.key);
        expect(keys).not.toContain('venue');
    });

    it('shows Payouts only with the community feature and read_finance', () => {
        const finance = visibleGroups(NAV_GROUPS, { features: { community: true, finance: true }, can: { read_finance: true } })
            .find((g) => g.key === 'finance');
        expect(finance.items.map((i) => i.label)).toEqual(['Overview', 'Payouts']);
    });

    it('keeps the order of the spec', () => {
        expect(NAV_GROUPS.map((g) => g.key)).toEqual(['top', 'events', 'community', 'venue', 'finance', 'organisation', 'bottom']);
    });

    it('never returns an empty group', () => {
        visibleGroups(NAV_GROUPS, {}).forEach((g) => expect(g.items.length).toBeGreaterThan(0));
    });
});
```

- [ ] **Step 2: Run, see them fail.** Run: `npx vitest run resources/js/test/console/consoleNav.test.js`. Expected: FAIL (module not found).
- [ ] **Step 3: Implement** the module and the layout change. Keep the active-item logic as it is (Events stays lit inside an event).
- [ ] **Step 4: Run** `npm run test`, `npm run lint`, `npm run build`. Expected: all pass. Revert `public/build` changes if the build artifacts are tracked and you are not meant to commit them (check `git status`; follow what recent commits on this branch did).
- [ ] **Step 5: Commit** `feat(console): grouped navigation with Community, Venue, Finance and Organisation`.

---

### Task 5: Verification and handoff

- [ ] **Step 1:** Full suite: `"$PHP" artisan test --parallel --processes=4 --compact`. Expected: all pass; name every failure.
- [ ] **Step 2:** `"$PHP" vendor/bin/pint --test` (clean), phpstan (0 errors, baseline not grown), `npm run lint`, `npm run test`, `npm run build`.
- [ ] **Step 3:** On the owner's local database: `"$PHP" artisan migrate --database=landlord` (applies the two new migrations), `"$PHP" artisan community:enable purpledot`, and confirm the existing local member, charges and payouts still show under the new pages (database query check is enough; the owner will click through).
- [ ] **Step 4:** Update `docs/superpowers/community-roadmap.md` (1a status `built`, evidence commit) and the project memory file `member_dues_design_decisions.md` (1a done, new command name).
- [ ] **Step 5:** Commit any remaining changes and push `community-1a`.

## Self-review against the spec

- Section 10 item 1a: grouped navigation (Task 4), Community section (Task 3), membership types with optional amount and no generation changes (Task 1), routes re-homed with public URLs preserved (Task 3), payouts in Finance (Task 3), gates split (Task 2 covers the rollout switch; plan entitlements arrive with the features they gate in 1c and later, per section 13.1; permissions already exist).
- Section 9: rename, optional amount, re-homed routes, payouts moved, switch renamed. Dropping the unique phone constraint, fee resolver and periods are 1b by the spec's milestone list.
- Section 15 compatibility: pay links (Task 3 test), metadata type kept (Task 2 note), no money logic touched (Global Constraints).
- Placeholders: none; commented test bodies in Tasks 1 and 3 name exactly what to assert and which existing test file shows the pattern.
- Type consistency: `MembershipType`, `membership_type_id`, `communityEnabled()`, `community.enabled`, `tenant.community.*`, `tenant.finance.payouts.*` are used identically across tasks.
