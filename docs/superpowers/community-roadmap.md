# Community roadmap

The single place to see where the Community work stands and how to continue it. Claude, Codex or a person picking this up starts here.

- **Spec:** [specs/2026-10-15-community-design.md](specs/2026-10-15-community-design.md) (binding; plans argue from it).
- **Earlier dues spec:** [specs/2026-10-13-member-dues-design.md](specs/2026-10-13-member-dues-design.md) (superseded from its milestone 3 on).
- **Owner:** Selase Kwawu. Production changes (merge, deploy, migrations on production, seeding) need the owner's go-ahead.

## Status

Status values: `not started` → `planned` (plan written) → `in progress` → `built` (on its branch, tests green) → `merged` → `deployed`. Whoever changes a status updates this table in the same commit and fills in the evidence.

| # | Milestone | Status | Branch | Plan | Evidence |
|---|---|---|---|---|---|
| D1 | Ledger categories (dues spec M1) | deployed | `main` | [plan](plans/2026-10-13-ledger-categories-foundation.md) | PR #23, deployed 2026-10-13 |
| D2 | Collecting dues (dues spec M2) | built, not merged | `member-dues-m2` | [plan](plans/2026-10-13-member-dues-collecting.md) | `61224d2`, 2377 tests green, clicked through in Paystack test mode |
| 1a | Shell and re-homing | built (reviewed) | `community-1a` (from D2) | [plan](plans/2026-10-15-community-1a-shell-and-rehoming.md) | final review fixes applied; full suite 2391/2391, phpstan 0, lint and JS tests (253) green; deferred minors listed in the 1a plan |
| 1b | Charges, periods and commercial terms | built (reviewed), not merged | `community-1b` from `0173494` | [plan](plans/2026-10-15-community-1b-charges-periods-terms.md) | `0ad164c`; full PHP 2473/2473 (11621 assertions), JS 258/258, PHPStan 0 with unchanged baseline, Pint/lint/build green and rebuilt assets committed; fresh review findings fixed; isolated QA acceptance only |
| 1c | Import, reminders and Finance | in progress | `community-1c` from `0ed29f6` | [plan](plans/2026-10-15-community-1c-import-reminders-finance.md) | Tasks 1–4 implemented; caps, import and reminders reviewed. Task 4 focused PHP 22/22 (189 assertions), related Community/Finance/Dues/Payments 547/547 (2,960 assertions), JS 35/35, scoped PHPStan 0, Pint/lint/build green with rebuilt assets; Finance task review and final milestone-wide verification pending. |
| 2 | Messages and groups | not started | | brief below | |
| 3 | People, Following and Join | not started | | brief below | |
| 4 | The `/my` app | not started | | brief below | |
| 5 | Giving | not started | | brief below | |
| 6 | Member benefits in Events | not started | | brief below | |
| 7 | Library and polls | not started | | brief below | |

Merge policy (owner's decision): D2 is not merged on its own. 1a, 1b and 1c land on top of it; the branch is merged to `main` when the owner says so (the spec recommends after 1a at the earliest, since 1a keeps money logic unchanged).

## Next action (handover, 2026-10-15)

- `community-1b` at implementation commit `0ad164c` holds D2 + 1a + 1b, built and reviewed, **not merged**. The plan contains completed steps, review rulings and verification evidence.
- Isolated checkout: `/Users/selase/Sites/miconvener/.worktrees/artifact-completion`. The original checkout remains clean on `community-1a` at `0173494`; the owner’s local Purpledot database and settlement mode were not changed. 1b migrations and command acceptance ran on isolated QA databases.
- The owner can review 1b before deciding integration. **Do not merge, deploy or change production without the owner’s go-ahead.**
- Milestone 1c is in progress on `community-1c`: Tasks 1–4, fresh whole-branch review and scoped review fixes are complete (`5c39e8b`). Full JavaScript and PHPStan passed; the local full PHP run had 4 errors, followed by 67/67 focused tests and a deterministic pagination fixture correction (`1890366`, 14/14). A draft PR will run the final suite on clean CI; status stays in progress until green. Finance has per-currency collections, recorded cash, current balances/arrears, streamed CSVs and dues-only Paystack settings. See the 1c plan for acceptance limits and SMS reconciliation follow-up. Read the 1a plan’s deferred minors and PaymentSettings ruling as well as 1b’s final self-review. Voids remain cancelled; rejoin starts next period; unpaid advance charges cancel on exit/lapse.

## How to continue

1. Read the spec, then the next milestone's plan (or brief).
2. Work on that milestone's branch. Never on `main`.
3. Follow the plan's tasks in order. **Tick each `- [ ]` step in the plan file in the same commit as the work**, so progress is visible in git to every agent. Add a short `Ruling:` line under a step when you deviate from the plan, with why.
4. Before marking a milestone `built`: full suite (`php artisan test --parallel --processes=4 --compact`), `vendor/bin/pint --dirty`, phpstan (0 errors, baseline must not grow), `npm run lint`, `npm run test`, `npm run build`, and one review of the whole branch by a fresh reviewer.
5. Update the status table here, with the commit as evidence.
6. For a milestone that only has a brief: write its plan first (superpowers writing-plans format: header, Global Constraints, Review Focus, tasks with failing tests first), save it under `plans/`, link it here, set status `planned`, and get the owner's review before building.

**Built assets are tracked** (`public/build`). Any commit that changes `resources/js` or `resources/css` runs `npm run build` and commits the new `public/build` in the same commit; never revert build output to an older state (it leaves stale or blank screens).

Always green: Finance, Payments, Dues, Community, Events and attendee-portal suites; `PayoutOtpFlowTest`; `php artisan finance:verify-categories` exits 0. Read `AGENTS.md` "MiConvener finance invariants" before touching money.

Local toolchain on the owner's Mac: `export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; PHP="$HOME/Library/Application Support/Herd/bin/php"`. PostgreSQL only.

## Briefs for milestones without a plan yet

Each brief is enough to write the plan from. Write the plan against the code as it is when the milestone starts, not from memory.

### 2. Messages and groups

- **Goal:** organisations message their community by email and SMS, now or scheduled, to an audience; groups exist and are audiences.
- **Spec:** sections 4 (Messages, People > Groups), 8 (audiences and consent), 13 (plan entitlements: scheduling on Starter+, group messaging on Growth+), 13.1.
- **Depends on:** 1a (Community section), 1c (reminders' delivery and SMS spending limit, which messages reuse).
- **Builds:** groups (flat, many-to-many with members); an audience resolver shared with event blasts (tenant-bounded, deduplicated by normalised address, re-evaluated at send time); organisation-level messages (own records; event blasts keep theirs and call the shared delivery and audience services); marketing preferences separate from service messages; permission `send community messages`; arrears audiences also need `read finance`.
- **Done when:** a member who is also a follower gets one message; an unsubscribe before a scheduled send is honoured; the SMS spending limit stops a send with a clear message; every existing event blast test passes unchanged.
- **Risks:** breaking event blasts during extraction (extract one service at a time); SMS cost (spending limit and credit checks before queueing).

### 3. People, Following and Join

- **Goal:** anyone can follow an organisation; people can ask to join; `/my` knows a person by a verified email or phone.
- **Spec:** sections 6.1 (identity and access), 6.3 (following and joining, member statuses), 4 (People > Followers), 13.1 (Following has its own switch and no plan gate).
- **Depends on:** 1a; 2 (announcing new events to followers uses messages).
- **Builds:** SMS sign-in for `/my` (throttled per number and IP, platform-paid, daily cap); identifier-exact access rules; follow from organisation and event pages; join with approval (default), immediate, or invitation only; `pending` status; followers tab; announcing a new event to followers; `/my` Communities data endpoints (the app UI is milestone 4).
- **Done when:** verifying one identifier never reveals records holding another (tests across two organisations); contact changes move access; shared numbers show each member separately; a pay link never opens `/my`.
- **Risks:** identity leakage across organisations; SMS sign-in abuse.
- **Decisions needed from the owner before planning:** protection for recycled and shared phone numbers beyond spec 6.1 (for example, whether a newly verified number sees member records older than a set age, and how members sharing a number are told apart).

### 4. The `/my` app

- **Goal:** `/my` becomes a native-feeling PWA on phones and a left-rail, two-column app on desktop.
- **Spec:** section 6.2 (tabs Home, Tickets, Communities, Money, Me; accessibility, states, offline boundary). Owner's reference images (2026-10-15): greeting header, image cards, pill filters, wallet-style ticket passes with QR, bottom tab bar; use MiConvener tokens and dark mode, not the references' colours.
- **Depends on:** 3 (Communities data, identifiers).
- **Builds:** app shell, five tabs, ticket passes (offline-cached), money tab (dues to pay, giving, receipts, statements), communities list and organisation pages with their logo, Me (identifiers, preferences, sign out).
- **Done when:** WCAG 2.2 AA checks pass; only ticket passes are cached by the service worker; personal caches clear on sign-out and identifier change; every existing `/my` feature still works (attendee-portal suite green); the owner has clicked through on a phone and a desktop.
- **Risks:** caching private data; regressions in the existing attendee portal.

### 5. Giving

- **Goal:** funds and campaigns with give pages, progress, bulk cash and statements; event contributions associated with funds.
- **Spec:** sections 5.1, 5.3, 5.4, 5.2 (giving stream in the terms resolver).
- **Depends on:** 1b (terms resolver), 1c (Finance reporting), 3 (identifiers for givers and `/my`).
- **Builds:** `giving` ledger category; funds (open-ended or campaign with target and optional deadline); give pages; gifts (online, recorded cash, bulk cash with no giver); campaign progress (raised net of refunds and corrections, left to raise, percentage, distinct givers); anonymity rules; giving reports and member statements; association of historical event contributions with event-linked funds and a cutover date for new giving.
- **Done when:** recorded and bulk cash never change the available-to-pay-out balance; historical contributions keep references, receipts, tributes, ledger category and payouts; before-and-after reconciliation passes in tests and on production with a documented rollback.
- **Risks:** misstated balances (the probe tenant holds live charges); anonymity leaks.

### 6. Member benefits in Events

- **Goal:** members-only events and member prices.
- **Spec:** sections 2 (no second events place), 6.3 (statuses), 10 item 6.
- **Depends on:** 3 (verified identifiers and active members).
- **Builds:** an event setting for members-only and member ticket prices; eligibility = an `active` member holding a verified identifier, checked at checkout and again at confirmation.
- **Done when:** a non-member cannot buy a member price by editing a request; a member who lapses between checkout and confirmation is handled by a stated rule (decide with the owner: honour or refuse); all event checkout suites green.
- **Decisions needed from the owner before planning:** do lapsed members get member prices; what happens when membership changes (lapse, exit, type change) between checkout and confirmation: honour the started price or refuse.

### 7. Library and polls

- **Goal:** organisation-level documents and polls/surveys for an audience, answered in `/my` or from a link.
- **Spec:** sections 4, 7 (polls), 2 (shared services, not shared tables; event materials and live polls keep their rules), 13 (Library Starter+, polls Growth+).
- **Depends on:** 2 (audiences), 4 (`/my` app).
- **Builds:** library items with audience and storage on a named disk; organisation polls and surveys reusing the poll and form engines' services; results and export.
- **Done when:** private documents are never cached offline and are served only to their audience; event materials' release timing and speaker rules and live polls are unchanged (their suites green).

## Decisions log

Decisions live in spec section 3 and the plans' `Ruling:` lines. Add any new owner decision to the spec in the same commit that relies on it.
