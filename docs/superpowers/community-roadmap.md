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
| D2 | Collecting dues (dues spec M2) | deployed | `member-dues-m2` | [plan](plans/2026-10-13-member-dues-collecting.md) | `61224d2`, 2377 tests green, clicked through in Paystack test mode; merged in PR #26 (`6aa8900`, 2026-10-15), deployed 2026-10-15; verified Cloud release `f8e7ba5` |
| 1a | Shell and re-homing | deployed | `community-1a` (from D2) | [plan](plans/2026-10-15-community-1a-shell-and-rehoming.md) | final review fixes applied; full suite 2391/2391, phpstan 0, lint and JS tests (253) green; deferred minors listed in the 1a plan; merged in PR #26 (`6aa8900`, 2026-10-15), deployed 2026-10-15; verified Cloud release `f8e7ba5` |
| 1b | Charges, periods and commercial terms | deployed | `community-1b` from `0173494` | [plan](plans/2026-10-15-community-1b-charges-periods-terms.md) | `0ad164c`; full PHP 2473/2473 (11621 assertions), JS 258/258, PHPStan 0 with unchanged baseline, Pint/lint/build green and rebuilt assets committed; fresh review findings fixed; isolated QA acceptance only; merged in PR #26 (`6aa8900`, 2026-10-15), deployed 2026-10-15; verified Cloud release `f8e7ba5` |
| 1c | Import, reminders and Finance | deployed | `community-1c` from `0ed29f6` | [plan](plans/2026-10-15-community-1c-import-reminders-finance.md) | [Clean CI](https://github.com/selase/miconvener/actions/runs/38792860573) on `1881507`: PHP 2,607/2,607 (12,701 assertions), JS 282/282, PHPStan 0, Pint/lint/build green. Fresh whole-branch review plus scoped fixes/integration/test-isolation reviews approved; final source `67f9e36`. PR #26; browser acceptance completed with focused UI fixes `0abb15b` (6 JS tests, lint/build/Pint and scoped review). SMS reconciliation limits remain in the plan; merged in PR #26 (`6aa8900`, 2026-10-15), deployed 2026-10-15; verified Cloud release `f8e7ba5` |
| 2 | Messages and groups | deployed; Purpledot pilot enabled | `codex/community-2` | [plan](plans/2026-10-15-community-2-messages-groups.md) | Owner approved merge. PR27 merged as b659e66 and Cloud deployment depl-a2fe17c8-cad4-4bc3-8364-9eef240d4859 succeeded on2026-10-16. Tested tree matches merge exactly;2706 PHP/296 JS checks passed. New tables/permission verified. Owner standing approval applied2026-10-16: missing-only entitlement sync completed and exactlyPurpledot enabled. Scheduling and group announcements available. Other rollout switches unchanged. |
| 3 | People, Following and Join | deployed | `codex/community-3` | [plan](plans/2026-10-16-community-3-people-following-join.md) | Backend `a1f5540` plus final client-only link fix, native implementation. Fresh independent review2 Critical/6 Important findings fixed; complete local PHP2,871/13,838 assertions, JS318, PHPStan0, Pint/lint/build green and rebuilt assets committed. Isolated browser acceptance and operational/minor limits recorded in plan. CI uses4 workers with a disposable-service lock allowance; local gate used2. PR #28 merged as `3be4329d` after green CI run `38894497631`: PHP2,871/13,838 assertions, JS318, quality checks passed. Deployed exact merge `3be4329d` via Cloud retry `depl-a2fec526-2057-45b0-8e25-26c7d7075198`,2026-10-16 11:46:50UTC; initial placement-capacity failure ran no deploy command. Redis/shared session-lock table verified; Purpledot Community+Following on, phone entry off, other8 organisations unchanged. Live health/public/staff read-only acceptance passed; live recipient OTP/ticket proof remains pending. |
| 4 | The `/my` app | not started | | brief below | |
| 5 | Giving | not started | | brief below | |
| 6 | Member benefits in Events | not started | | brief below | |
| 7 | Library and polls | not started | | brief below | |

Merge policy (owner's decision): D2 is not merged on its own. 1a, 1b and 1c land on top of it; the branch is merged to `main` when the owner says so (the spec recommends after 1a at the earliest, since 1a keeps money logic unchanged).

## Next action (handover, 2026-10-16)

- Owner approved integration after review. D2 + Community 1a + 1b + 1c are **deployed in production**, via [PR #26](https://github.com/selase/miconvener/pull/26), merge commit `6aa8900`. The merged tree exactly matches tested head `327ac1a`.
- [Final CI](https://github.com/selase/miconvener/actions/runs/38810540132) passed both code-quality and Laravel tests on `327ac1a`. Browser acceptance completed on isolated QA: imports/reminders, Finance date filters and real CSV downloads, dues-only payment settings, existing pay links, desktop/mobile and light/dark payment fields. Independent reviews approved the implementation and final UI fixes.
- Owner clarified that pushes/merges to `main` automatically deploy. Cloud releases `6aa8900` and `f8e7ba5` succeeded; all eight Community/dues migrations and permission seeding completed. Public homepage/login/assets returned HTTP 200; signed-out Community access redirects to login. Targeted setup added the missing `community_members` feature, four standard package caps and four tenant limit rows; existing rows, prices, commissions and other features were preserved. Purpledot was subsequently enabled alone at the owner's request; production verification confirmed exactly one enabled organisation. Other organisations remain off. Before high-volume SMS, assign an operator and auditable reconciliation workflow; keep uncertain reservations held. The plan records remaining operational and historical rollback limitations.
- The original checkout remains on `community-1a` at `0173494`; Claude's checkout and owner Purpledot data/settlement mode remain untouched. The owned QA worktree and private acceptance artifacts are retained.
- Milestone2 is merged and deployed: [PR #27](https://github.com/selase/miconvener/pull/27), merge `b659e66`, Cloud release `depl-a2fe17c8-cad4-4bc3-8364-9eef240d4859` completed2026-10-16 03:42:42 UTC. [Final PR CI](https://github.com/selase/miconvener/actions/runs/38832355334) passed2706 PHP tests/13067 assertions and296 JS tests. Live schema/permission checks pass; homepage, tenant login and production-built assets are healthy. Purpledot was enabled2026-10-16 03:53 UTC under the owner’s standing instruction after the narrow entitlement sync. Readback confirmed enabled organisations=[purpledot], Community/messages=true, scheduling/group messaging=true. No outbound test sends occurred.
- Live authenticated smoke checks completed2026-10-16 after owner sign-in: Messages/Groups/Overview navigation, empty states, email preview and future schedule preview in Africa/Accra, stale-preview invalidation, keyboard dismissal/focus return, dark readable fields and390px layout (dialog388/scroll388). Browser console errors0. Purpledot has0 members/groups and message history remains empty; no records saved or outbound messages sent. Actual group edits/delivery were covered on isolated QA, not production.
- Milestone3 is deployed from PR #28 (`3be4329d`) after green remote CI `38894497631` (PHP2,871/13,838 assertions; JS318; quality checks passed). Cloud retry `depl-a2fec526-2057-45b0-8e25-26c7d7075198` succeeded after one temporary placement-capacity failure. Purpledot Community and Following are on; phone entry stays off pending sender/cap and recipient-authorised SMS acceptance. Other8 organisations and Purpledot settlement/joining settings were preserved. Live health, public organisation, email-first entry, staff People/Followers/review queues and add-member modal verified. Real OTP/private-ticket acceptance remains pending a designated recipient; no live message or payment was sent. Review the spec/roadmap brief before starting milestone4.
- Milestone 2 release-status notes have been carried into `codex/community-3-plan` with the new plan. These documentation changes do not trigger another production deployment.

## How to continue

**Standing owner instruction (2026-10-16):** Always enable approved, deployed Community milestones for Purpledot. Pilot activation for `purpledot` is already authorised; do not request it again. Other organisations retain their rollout settings and normal plan entitlements still apply.

1. Read the spec, then the next milestone's plan (or brief).
2. Work on that milestone's branch. Never on `main`.
3. Follow the plan's tasks in order. **Tick each `- [ ]` step in the plan file in the same commit as the work**, so progress is visible in git to every agent. Add a short `Ruling:` line under a step when you deviate from the plan, with why.
4. Before marking a milestone `built`: full suite (CI: `php artisan test --parallel --processes=4 --compact`; on this Mac use two workers because four schema rebuilds exceed the stock PostgreSQL lock table), `vendor/bin/pint --dirty`, phpstan (0 errors, baseline must not grow), `npm run lint`, `npm run test`, `npm run build`, and one review of the whole branch by a fresh reviewer.
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
- **Builds:** email-first `/my` verification with independent phone support (throttled per number and IP, platform-paid, daily cap); exact-email access and independently claimed member-scoped phone access; revocable representative grants; follow from organisation and event pages; join with approval (default), immediate, or invitation only; existing `pending` status; independent followers tab; reviewed announcements of published events to followers; `/my` Communities data and minimal usable flows (the app redesign is milestone 4).
- **Done when:** verifying one identifier never reveals records holding another (tests across two organisations); phone proof alone reveals no historic member/name/balance; shared numbers expose only individually approved records; contact edits/credential rotation revoke access immediately; ambiguous imports never silently merge members; Following works with Community off; a pay link never opens `/my`.
- **Risks:** identity leakage across organisations; SMS sign-in abuse.
- **Policy agreed 2026-10-16:** email primary; phone additional or phone-only alternative; independently claim historical memberships, explicitly approve representatives and provide private recovery credentials, never SMS-only recovery. Age thresholds and name selection are not ownership proof. See amended spec6.1. Owner review of the implementation plan is next; no implementation approval is inferred.

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
- **Builds:** an event setting for members-only and member ticket prices; eligibility = an `active` member authorised under spec6.1, checked at checkout and again at confirmation. Phone match or representative access alone does not establish personal eligibility.
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

Local test resource note (M3): database suites share the cluster-wide PostgreSQL lock table even when their database names differ. Do not overlap a full parallel suite with refreshing/race suites. CI provisions its disposable service with `max_locks_per_transaction=256` for four workers; do not change/restart the owner’s shared local database server merely to match CI worker count.
