# Scheduled Payouts with Human Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** Proposed for owner review. NOT approved for implementation, production migration, financial changes or activation.

**Goal:** Prepare organisation-specific payout proposals on a schedule, with explicit human authorisation and existing Paystack OTP protection before money moves.

**Architecture:** Add a versioned policy and proposal layer above the existing category-specific payout services. A proposal is not a payout reservation or ledger entry. Human approval revalidates eligibility and atomically creates a reservation; submission then uses an immutable transfer intent, existing payout services and verified settlement. Do not merge streams, rebuild ledgers or disable OTP.

**Tech Stack:** Existing Laravel, PostgreSQL, queues, scheduler, React/Inertia console, Pest and Vitest. No new dependencies.

**Spec:** Owner-approved amended design in this chat (9 November 2026); its exact first-release decisions and constraints are captured below. Later unattended transfers require their own design, plan and approval.

## Global Constraints

- GHS only; platform-held funds only. Own-gateway organisations are ineligible.
- Modes in this release: manual and scheduled-human-approval. Unattended execution is unavailable, including through direct API calls.
- Existing organisations remain manual. No policy is inferred from an existing schedule, plan or commercial agreement.
- No changes to commission, fee bearer, refund rights, contractual release rules, historical records or Paystack account security settings.
- Payout amount means total organisation payable consumed; show transfer fee and recipient net separately. Amounts are integer pesewas; no currency conversion.
- Scope policies by organisation, stream and currency; ticket proposals additionally belong to one event. Commerce uses the existing Commerce balance pool; venue and merchandise earnings are not fabricated as separate spendable pools. Preserve venue-specific liabilities and release rules inside that pool.
- Background jobs create proposals only. Only an explicitly authorised person may reserve and submit a payout. OTP remains on.
- LedgerService remains the only ledger writer. Proposal, approval and provider acceptance alone do not post a completed payout.
- Production audits are read-only; historical corrections and pilot activation require separate owner approval.
- Preserve Claude worktrees and all unrelated changes. Implement from current main in a suitable isolated checkout, after comparing already-delivered features.

## Recommended first-release policy (for review)

- Weekly, Monday 09:00 organisation-local time; daily is supported but platform-approved. No per-sale scheduling.
- Platform finance approves policy, minimum, per-transfer/day/rolling-24-hour caps and contractual reserve. These values must be explicit before activation; no invented monetary defaults.
- Organisation owners may request changes and pause. Approved ceilings cannot be raised by tenant requests.
- Platform policy approver must differ from requester. Per-payout human approval requires current payout authority; Paystack owner OTP is a second provider control, not a substitute for application authorisation.
- Destination changes require recent password confirmation, enrolled MFA, independent approval, verification and a 24-hour cooling period. Notify the prior authorised contact. Block activation if those security prerequisites are unavailable.
- Proposal expires after 24 hours or when its policy/destination changes. Approval creates a reservation; authorisation to submit expires after 30 minutes. Expired unsent reservations may be cancelled atomically, never if a transfer may have been submitted.
- Scheduling and approval require a successful reconciliation checkpoint no older than 24 hours, with no unresolved material exception for the affected scope. Unknown/in-flight transfers continue reserving funds.
- Provider liquidity is checked immediately before submission. Provider-account-wide allocation includes all streams and manual submissions, with a platform-approved cash buffer; no assumed zero buffer.
- Unattended account changes, automatic retries, bulk transfers, other currencies and own-gateway automation are excluded.

## Review Focus

1. Two humans approving a proposal while another sends a manual payout: one reservation, no overspend (Tasks 3–4).
2. Pause/account/role changes between preview, approval, send and OTP: fresh authority checks, preserve submitted transfer identity (Tasks 2, 4–5).
3. Paystack timeout or delayed reversal: retain uncertain commitment and compensate exactly once (Tasks 1, 4, 6).
4. Shared provider liquidity across different organisations: cash cannot be allocated twice or confused with entitlement (Tasks 4, 6).
5. Expired proposals, timezone/DST changes and missed scheduler runs: one current proposal, no historical transfer backlog (Task 3).

## Evidence from current code

Inspected checkout head: 94554f0c (same source tree as merged main after PR #96). Refresh baseline before implementation.

- `CommunityPayouts::reserve/send/finalize` covers Dues/Giving and locks their payable accounts.
- `StorePayouts::reserve/send/finalize/reconcile/cancel` provides Commerce reservations, original destination/account safeguards and operation identity.
- `StoreBalance`, `DuesBalance`, `GivingBalance` and `MemberBenefitBalance` contain existing availability protections; reuse and extend only where an explicit test proves a gap.
- `PayoutSender`, `PayoutRelease`, `ReconcileStuckPayouts` and verified webhook handlers are shared. Their legacy event semantics differ from Commerce: do not assume uniform safe retry or network-lock behaviour.
- `ReconcilePayoutSchedulesCommand` creates a scheduled EventPayout then calls recordPayout without submitting a transfer. It also falls back to another verified destination. This path must be fenced and reconciled, not simply scheduled more frequently.
- EventFinanceController allows manual paid-status updates and has its own balance/reservation logic. New scheduled proposals must not reach that shortcut; preserve legitimate external-settlement handling separately with evidence and authority.
- `SettlementGateway` exposes transfer operations but no available-balance method. A provider liquidity adapter and evidence checkpoint are necessary before scheduled submission.
- `payouts:reconcile` already runs every fifteen minutes. Extend this recovery path rather than adding competing transfer retriers.

## Task 1 — Fence legacy schedules and establish accounting/recovery safety

**Files:** Modify `app/Console/Commands/ReconcilePayoutSchedulesCommand.php`, `app/Http/Controllers/Tenant/EventFinanceController.php`, `app/Services/Finance/PayoutSender.php`, `app/Services/Finance/PayoutRelease.php`, `app/Console/Commands/Events/ReconcileStuckPayouts.php`; test `tests/Feature/Events/EventLedgerAndPayoutScheduleTest.php`, `tests/Feature/Events/PayoutSendingTest.php`, `tests/Feature/Events/ReconcileStuckPayoutsTest.php`.

**Interfaces:** Preserve existing manual endpoints. Legacy schedule reconciliation becomes preview-only for payout creation/posting; reserve retention/release changes require their own idempotent lock-protected checks. Produce a read-only inventory of scheduled/processing/OTP/paid payouts and linked ledger postings for review.

- [ ] Write failing tests: legacy scheduling makes zero provider calls and creates zero payout ledger postings; no fallback recipient; scheduled proposals cannot be manually marked paid; unknown event transfer outcomes cannot be blindly resent.
- [ ] Run affected files individually and confirm each new regression fails for its intended reason.
- [ ] Implement the fence; preserve existing payout history and expose historical mismatches as review exceptions. Do not auto-correct postings or auto-import legacy policies.
- [ ] Ensure every scheduled submission path persists original account/reference/net/fee before HTTP, calls provider outside business locks, and settles verified success once. Record compensating entries for verified reversals through LedgerService. Where manual paths share those functions, include their regression suites.
- [ ] Run the three affected files plus `tests/Feature/Finance/PayoutOtpFlowTest.php`; commit source and tick these steps together.

## Task 2 — Versioned policy, permissions and destination approvals

**Files:** Create migrations for `organisation_payout_policies`, `payout_policy_actions`, `scheduled_payout_proposals`; create models `app/Models/OrganisationPayoutPolicy.php`, `PayoutPolicyAction.php`, `ScheduledPayoutProposal.php`; create `app/Services/Finance/PayoutPolicies.php`, `app/Policies/OrganisationPayoutPolicyPolicy.php`; modify `database/seeders/PermissionsSeeder.php`, `app/Libraries/RolePermissions.php`, `app/Http/Controllers/Tenant/TenantPayoutAccountController.php`. Test `tests/Feature/Finance/ScheduledPayoutPolicyTest.php`.

**Interfaces:** `PayoutPolicies::request(Tenant $tenant, User $actor, array $input): OrganisationPayoutPolicy`; `approve(OrganisationPayoutPolicy $policy, User $actor): OrganisationPayoutPolicy`; `pause(OrganisationPayoutPolicy $policy, User $actor): void`.

Policy stores immutable version/stream/currency/settlement-account identity/destination fingerprint/timezone/cadence/local time/weekday/explicit limits/reserve terms, requester/approver, activation time and supersession. Actions append; direct edits to approved terms are forbidden. Proposal stores policy version, scope, window key, entitlement/reserve/available snapshot, proposed gross/fee/net, reason, expiry and optional reserved payout ID. Financial snapshots contain no full bank credentials.

- [ ] Write failing tests for tenant isolation, same-person policy approval, inactive users, organisation owners raising ceilings, destination edits after verification, cooling period, missing MFA, GHS-only and own-gateway refusal.
- [ ] Confirm failures, then implement typed validation through Form Requests and policies. New permissions: tenant-safe `manage payout schedules`; platform-only `approve payout policies`. Existing payout permissions remain necessary to reserve/send. Event Organiser receives none.
- [ ] Invalidate unsubmitted proposals/approvals when destination, commercial terms, settlement account, policy or security authority changes; submitted payouts keep original snapshots and reconcile normally.
- [ ] Add immutable action evidence, source uniqueness and database checks. No existing schedule is enabled by migration/seeding.
- [ ] Run `ScheduledPayoutPolicyTest.php` and `tests/Feature/Billing/TenantPayoutAccountVerificationTest.php`; commit.

## Task 3 — Scheduled previews, without reserving or moving money

**Files:** Create `app/Services/Finance/ScheduledPayoutPlanner.php`, `PayoutEligibility.php`, `app/Console/Commands/Finance/PrepareScheduledPayouts.php`; modify `app/Console/Kernel.php`; test `tests/Feature/Finance/ScheduledPayoutPlannerTest.php`.

**Interfaces:** `PayoutEligibility::inspect(OrganisationPayoutPolicy $policy, ?string $eventId = null): array` (typed shape: eligible/reason/gross entitlement/reserves/available/currency); `ScheduledPayoutPlanner::prepare(OrganisationPayoutPolicy $policy, CarbonImmutable $now): array` returns zero or more persisted proposals, one per eligible scope/current schedule window.

- [ ] Test duplicate workers and missed days produce one current proposal per policy/scope/window; an expired or superseded policy cannot generate one. Scheduler makes zero transfer, reservation or ledger calls.
- [ ] Test Monday local boundary, daily mode, DST skipped/repeated time (run at first valid instant; once per local date), timezone policy version change and 24-hour expiry.
- [ ] Confirm failures; implement bounded cursor jobs with continuation, due-policy selection and database uniqueness. Run every fifteen minutes without overlap; database uniqueness remains authority.
- [ ] Use category services; exclude cash, unverified captures, pending refunds, disputes, security liabilities and unreleased venue earnings. No cross-event ticket pooling or cross-category/currency offset. Unknown eligibility fails closed with a visible reason.
- [ ] Test minimum/fee equality, explicit reserves, withheld venue earnings, missing agreement and stale reconciliation. Run `ScheduledPayoutPlannerTest.php`; commit.

## Task 4 — Human approval, atomic reservation and guarded submission

**Files:** Create `app/Services/Finance/ScheduledPayoutApproval.php`, `PayoutSubmissionGuard.php`, `ProviderLiquidity.php`, `PayoutReconciliationCheckpoint.php`; create provider-liquidity contract/Paystack adapter following existing gateway binding; modify category payout services and EventFinanceController only as required to share guards. Tests `tests/Feature/Finance/ScheduledPayoutApprovalTest.php`, `tests/Infrastructure/Finance/ScheduledPayoutConcurrencyTest.php`.

**Interfaces:** `ScheduledPayoutApproval::reserve(ScheduledPayoutProposal $proposal, User $actor, string $operationKey): EventPayout`; `submit(ScheduledPayoutProposal $proposal, User $actor): array`; `ProviderLiquidity::refresh(string $accountFingerprint, string $currency): array` (verified provider available balance, observation time, account identity); `PayoutSubmissionGuard::claim(EventPayout $payout, User $actor): void` verifies/records a guarded submission claim, not a ledger posting.

- [ ] Write failing tests for expired/changed proposals, revoked permission, changed account, paused policy, altered hidden amount, repeated operation key and refund racing approval. Reject changed values; require a fresh proposal, never silently approve a different amount.
- [ ] Implement reserve through existing category services under canonical locks, with current actor permission and live availability. Capture policy/proposal/actor in immutable approval evidence; no synthetic staff user in scheduler.
- [ ] Implement a provider-account liquidity allocation record using a local transactional lock shared by all platform manual and scheduled senders. Refresh provider balance outside locks; allocation uses a maximum 60-second-old observation, subtracts buffer and commitments, and conservatively avoids treating recently completed debits as immediately reusable cash. Reconcile observations/commitments without double counting or inventing new capacity.
- [ ] Before submission recheck limits, authority, reconciliation, policy and availability excluding this reservation. Count gross commitments against local-day AND rolling-24-hour limits; apply platform aggregate cap. Insufficient cash leaves a visible awaiting-liquidity state with no transfer submitted.
- [ ] Persist reference/account/recipient/gross/net/fee identity before HTTP; retry uncertainty only through reconciliation. Approval expires after 30 minutes if unsent. Cancellation and submission must race safely.
- [ ] Prove with real PostgreSQL workers: two approvals, manual versus scheduled payout, payout versus refund, two tenants sharing one provider cash pool, pause versus send, expiry cancellation versus send. Assert financial outcomes and ledger counts, not only lock ordering.
- [ ] Run approval/concurrency tests plus DuesPayoutTest, GivingPayoutTest, StorePayoutsTest, PayoutSendingTest and PayoutOtpFlowTest; commit. No provider-account-wide change is released without all manual-stream regressions passing.

## Task 5 — Finance controls, approval queue and transactional notices

**Files:** Create `app/Http/Controllers/Tenant/PayoutScheduleController.php`, `app/Http/Requests/Tenant/Finance/StorePayoutScheduleRequest.php`, `ApproveScheduledPayoutRequest.php`, `resources/js/Pages/Tenant/Finance/Schedules.jsx`, platform policy approval controller/view following existing admin patterns; modify `routes/subdomain.php`, existing admin routes, finance navigation and `resources/js/Pages/Tenant/Finance/Payouts.jsx`, `resources/js/Pages/Tenant/Events/panels/FinancePanel.jsx`. Test `tests/Feature/Finance/ScheduledPayoutAccessTest.php` and adjacent `Schedules.test.jsx`.

**Interfaces:** Tenant-scoped list/request/pause/approve-proposal/submit/cancel endpoints; platform-only policy approval and global pause. All server routes re-authorise; UI permission flags are presentation only.

- [ ] Test access through forged tenant/proposal/payout IDs and finance read-only roles; Event Organiser denied; signed-out and stale-role sessions denied. No full bank data or OTPs in DTOs/logs/cache.
- [ ] Build schedule form, masked destination, policy approval status, next local run, preview breakdown, review queue, explicit approve and submit actions, existing OTP entry, and needs-review reasons. Exact explanation: “The schedule prepares a payout for review. No transfer is sent until an authorised person submits it.”
- [ ] Provide empty/manual/paused/expired/stale/insufficient-liquidity states, approval expiry and cancellation. Show balances as entitled / reserved / available, fees and net separately; no “paid” until verified completion.
- [ ] Add durable, deduplicated transactional notices for proposal ready, destination/policy change, OTP needed, payout confirmed and review required. Send only to authorised finance contacts; failure to send never changes money state. Reuse existing outbox conventions, not marketing credits.
- [ ] Test keyboard, 390px, dark/light, double click, stale tabs and mail/queue failures; run focused PHP/Vitest, build public/build, Pint; commit.

## Task 6 — Recovery, reconciliation freshness and operational gates

**Files:** Extend `app/Console/Commands/Events/ReconcileStuckPayouts.php`, existing provider webhook and LedgerService reconciliation touchpoints; create `app/Console/Commands/Finance/AuditScheduledPayouts.php`; tests `tests/Feature/Finance/ScheduledPayoutRecoveryTest.php`.

**Interfaces:** `PayoutReconciliationCheckpoint::assertFresh(string $accountFingerprint, string $scopeKey): void`; audit command dry/read-only by default, bounded by tenant/account. A checkpoint requires completed comparison of provider transfer/fee/reversal evidence with local intents, postings and clearing, not merely a recent job timestamp.

- [ ] Test lost response followed by success, duplicate/out-of-order webhooks, OTP expiry, known failure, unknown outcome, provider outage, delayed reversal, revoked actor at OTP and ledger-post failure. Preserve liabilities until conclusive evidence; post reversal once.
- [ ] Test failed audit cannot refresh checkpoint; an unresolved transfer remains reserved and blocks the affected scope; unallocatable provider discrepancy blocks the account. Cross-organisation mismatches cannot be silently netted away.
- [ ] Implement checkpoints, exception ownership/escalation and platform kill switch. Pauses stop new reservations/submissions, not settlement/reconciliation of submitted transfers. No automatic replacement transfer or auto-release of uncertain reservations.
- [ ] Run recovery and affected existing reconciliation suites; commit.

## Task 7 — Whole-change review, staged release and Purpledot pilot

- [ ] Self-review “Is there anything I have overlooked? Is the solution optimal? Let me take a step back.” Cover money lifecycle, all sender paths, permission/seed/navigation/config, original account, sensitive data, old records and rollback.
- [ ] Run configured PHPStan, Pint, affected suites and real-worker races; build assets. CI runs full existing suites once on final reviewed head; fix/retest only affected areas locally unless new evidence requires broader work.
- [ ] Independent whole-change financial/security review before merge; resolve confirmed findings with failing regressions. Keep one final review cycle unless changes warrant another.
- [ ] Browser walkthrough: draft policy → distinct approval → scheduled proposal → human approval → submission → owner OTP → verified paid. Repeat cancelled/expired proposal, insufficient liquidity and refund race using approved test-mode setup.
- [ ] Deploy disabled, no migrated organisation enabled. Read-only production inventory validates old schedules, permissions, ledger/transfer exceptions and provider account prerequisites. Obtain separate approval for historical remediation.
- [ ] Owner approves Purpledot stream, destination, monetary limits, buffer/reserves, finance contacts and staffing. No pilot values inferred from previous test-commerce agreements.
- [ ] Complete capped test-mode transfer/failure/reversal/reconciliation first; then separately approved small live transfer. Validate ledger, cash movement, fees and recipient net before activating one weekly stream.
- [ ] Observe two scheduled cycles and reconciliation; expand streams/organisations by explicit approval. Rollback is pause/disable, preserving proposals, approvals, transfers and evidence; never delete monetary history or abandon reconciliation.

## Subsequent release — not part of this implementation

Fully automatic execution needs a separate plan for authenticated server approval, per-request validation and replay protection, all-platform manual authorisation, provider OTP setting change, account-wide incident response and additional acceptance tests. No placeholder route or dashboard toggle may enable it in this release.

## Self-review of this plan

- Resolved proposal-versus-reservation ambiguity: scheduled proposals reserve nothing; humans reserve and then submit, with distinct expiry boundaries.
- Corrected stream model: venue and merchandise share Commerce liquidity under existing rules; no invented separate balance.
- Added provider liquidity adapter because SettlementGateway has no balance operation; manual sends must participate in allocation to prevent bypass.
- Kept legacy schedule/early posting investigation as the first gate, with no automatic historical adjustment.
- Explicitly retained OTP and separated unattended execution. No production writes, transfers, tests, migrations, invitations or feature activation performed while preparing this plan.
- Monetary caps/reserves and pilot contacts remain activation inputs requiring owner approval, not unspecified coding decisions. The liquidity observation algorithm is a dedicated Task 4 review gate; shared-account correctness takes priority over transfer throughput.
