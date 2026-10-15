# Community 1c — final review fix wave

Base: `7485df9`, branch `community-1c`, isolated checkout `.worktrees/artifact-completion`. Scope: final-review findings I1, M1, M2 and M3, addressed together without subagents. Milestone status remains **in progress**; Task 5, full-suite verification, fresh final review and push remain parent-owned.

## Changes

- **I1:** `MemberIdentity::matchingIds()` returns distinct matches for the reserved email and normalized name/phone. Existing manual uniqueness callers retain their duplicate semantics. Import validates that these identities do not identify different members before preview classification and again against the current/planned register while holding the existing tenant advisory transaction lock. Ambiguous rows receive an actionable error and never select an update target. Confirmation still compares the resolved ID to the preview's original ID; accepted rows reserve their planned identity in the same in-memory register before later rows are checked. File duplicates remain skipped, and one existing member still updates at most once. No database exception catch, weakened index or changed identity policy was introduced.
- **M1:** Import preview renders labelled phone and email lines, displaying both values when present.
- **M2:** Import result uses `error` for one and `errors` for zero/many. Arrears confirmation uses `member currently has` for one and `members currently have` for zero/many.
- **M3:** Applying dues dates sends the applied ticket search and status alongside the new dates on mixed Finance.
- Existing plan records the fix checks and the SMS operational follow-up. No roadmap status or shared `progress.md` edits.

Files: `app/Services/Community/{MemberIdentity,MemberImport}.php`; `tests/Feature/Community/MemberImportTest.php`; `resources/js/Pages/Tenant/Community/{ImportMembers,MoneyDues}.jsx`; `resources/js/Pages/Tenant/Finance/Index.jsx`; `resources/js/test/{MemberImport,DuesFinance,DuesReminders}.test.jsx`; `docs/superpowers/plans/2026-10-15-community-1c-import-reminders-finance.md`; this report; `public/build/manifest.json` and the replacement app bundle named below.

## Tests and RED evidence

All PHP runs use the explicit disposable database prefix below, with PostgreSQL socket access approved through execution escalation. No test runs overlap against this database.

```sh
DATABASE_URL='' DB_URL='' DB_DATABASE=miconvener_testing_community_1c_final DB_HOST=/tmp '/Users/selase/Library/Application Support/Herd/bin/php85' artisan test --compact
```

1. Before implementation, append `tests/Feature/Community/MemberImportTest.php --filter='conflicting email and phone|planned updates'`: **3 failed, 15 assertions, 89.486s**. Before-preview collision reported error0 instead of1; the after-preview collision returned 500 with PostgreSQL `members_tenant_id_phone_name_unique`, reproducing I1. The third failure was a test snapshot mistake (factory instance versus freshly loaded DB defaults/date representations); corrected it to compare fresh attributes before the next run.
2. First focused implementation run, same command: **2 passed / 1 failed, 39 assertions, 40.479s**. Both exact identity regressions passed, including manual update rejection and unrelated valid-row consume-once behavior. The planned-update regression caught reclassification of a file duplicate into an error after the first planned update. Restrict confirmation identity revalidation to eligible `new`/`existing` rows, preserving skipped duplicates.
3. JS initial run of the three affected files hit test timeouts and cascading un-restored mocks (9 failures / 11 passes, one unhandled error). Added local `afterEach(vi.restoreAllMocks)` to the fetch-mocking importer/reminder test files and reran with one worker and a 30s test timeout. The clean pre-UI-fix run was **3 failed / 17 passed**, failing specifically on hidden email, singular arrears text and omitted ticket filters. Command:

```sh
PATH=/Users/selase/.nvm/versions/node/v24.16.0/bin:$PATH npm run test -- resources/js/test/MemberImport.test.jsx resources/js/test/DuesFinance.test.jsx resources/js/test/DuesReminders.test.jsx --maxWorkers=1 --testTimeout=30000 --reporter=dot
```

4. Added independent zero/one/many import-result grammar cases. Before fixing the text, the importer-only command with the same worker/timeout flags produced **2 failed / 3 passed** (hidden email plus `1 errors`). This isolates the singular error failure from the earlier preview assertion.

Regression coverage includes Alice's reserved email conflicting with Sam's phone identity before preview and Sam introduced afterward; a good unrelated row imports once, repeated confirm returns410, manual update rejects the same phone collision, and full existing member attributes and paid charge attributes remain unchanged (contacts, type, status, notes, amount override, lifecycle, pay token and money history). Multiple planned rows cannot assign the same new phone identity to different email-only members; the second file duplicate remains untouched/skipped. Existing 3,000-row, cap and real concurrent-worker cases are included in the affected verification.

## GREEN verification

- Affected PHP importer/cap/concurrency: **49/49 passed, 262 assertions, 247.313s**, pristine output. Append `tests/Feature/Community/MemberImportTest.php tests/Feature/Community/MemberCapTest.php tests/Infrastructure/Community/MemberImportConcurrencyTest.php` to the PHP prefix above. This includes the 30 importer cases, 16 cap cases and 3 real concurrent-worker cases.
- Affected JS plus Community controls: **26/26 passed in 4 files, 47.01s**, pristine output. Command above plus `resources/js/test/CommunityDuesControls.test.jsx`.
- `'/Users/selase/Library/Application Support/Herd/bin/php85' vendor/bin/pint --dirty`: **passed**.
- `'/Users/selase/Library/Application Support/Herd/bin/php85' vendor/bin/phpstan analyse --debug --memory-limit=1G --no-progress app/Services/Community/MemberIdentity.php app/Services/Community/MemberImport.php`: **passed, 0 errors**, baseline unchanged.
- `PATH=/Users/selase/.nvm/versions/node/v24.16.0/bin:$PATH npm run lint`: **passed**.
- `PATH=/Users/selase/.nvm/versions/node/v24.16.0/bin:$PATH npm run build`: **passed**, 2,634 modules transformed, 31.91s. Updated `public/build/manifest.json` and replacement `public/build/assets/app-CkzhsdGv.js` are included in this commit. Existing >500kB minified-chunk warning remains (main application chunk 1,906.50kB); no new build error.
- `git diff --check`: **passed**.

## Self-Review Report

“Is there anything I have overlooked? Is the solution optimal? Let me take a step back.”

### Touchpoints updated

- Tests: HTTP/service regressions cover both collision timings, good-row persistence/exactly-once, manual agreement, planned duplicates and unchanged historical attributes; component cases cover all three UI findings and zero/one/many text.
- Navigation: fixes remain in the already linked People Import, Community Money and Finance pages; no new route or navigation entry.
- Permissions/seeders/roles: no new permission or changed gate, so no seeder/role propagation change applies.
- Config, `.env.example`, AGENTS.md and memory: no new dependency, environment variable, architectural convention or persistent memory decision applies. The approved identity/cap rules remain unchanged; the existing milestone plan records follow-up ownership.
- Built assets: rebuilt and tracked alongside source. No new package or unused dependency.

### UI Gap Analysis

- Discoverability: present through existing links. The preview's contact change appears in the same table before confirmation.
- Empty states: existing no-file/no-row, no-contact dash, empty Finance and no-arrears states retained; zero-count grammar covered.
- Permission gating: unchanged existing import/manage-members and Finance/arrears gates; no additional exposed fields or actions.
- Loading/error states: existing disabled preview/confirm and date-submit states retained. Row ambiguity uses existing error table/filter/result rendering; expired/used preview behavior remains covered.
- Mobile/responsive: phone/email use stacked lines within the existing horizontally scrollable table; date controls retain wrapping. No new browser acceptance performed by this fix agent; parent retains outstanding narrow Finance/PaymentSettings/CSV visual checks.
- Accessibility: contacts have visible labels, existing labelled controls and modal semantics retained; no new interactive controls.

### Solved (auto-fixed)

I1 plus all three minor findings. The first implementation's duplicate-outcome regression was caught and corrected before commit. Test cleanup prevents one failed fetch assertion contaminating later cases.

### Recommended for user decision

Before high-volume SMS rollout, the organisation owner/admin must manually establish the outcome of uncertain provider submissions. Keep those reservations held until the outcome is known; never blindly refund or retry. The auditable reconciliation operator workflow belongs in Messages milestone2, reusing the allowance lock and accounting for existing reserved/sent reminder usage. No operator screen/command is in this fix scope. Parent owns remaining full-suite/final-review/browser acceptance decisions; no merge, push, deploy or production command was attempted.

### Not gaps

- Money writes, commission/currency rules, cap counts, tenant/token locks and member update whitelist are unchanged.
- A phoneless member still reserves their email; phone-bearing family records may still share email and differently named people may still share a phone. Ambiguity rejects a row instead of guessing which record to change.
- Expected duplicate and row validation outcomes are handled before writes; unexpected database failures remain visible rather than globally swallowed.
- No production/owner database, `.env`, isolated browser QA data, root checkout, permissions or ledgers were changed. Boost documentation tools were unavailable; implementation follows checked-in service/test/component patterns.
