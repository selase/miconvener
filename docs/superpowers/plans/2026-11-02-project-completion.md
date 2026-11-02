# MiConvener Remaining Work: Phased Implementation Plan

> **For agentic workers:** Execute natively with `superpowers:executing-plans`, one bounded release at a time. The owner authorised this plan and systematic implementation on 2 November 2026. Start with Phase 1; continue unblocked work without asking for routine permission again. Preserve the explicit decision and external-action gates below.

**Goal:** close the unreleased fixes and acceptance gaps first, then complete the remaining product roadmap without losing existing features or confusing tested software with approved commercial rollout.

**Architecture:** reuse Laravel services, React/Inertia screens, shared finance/identity controls and per-organisation rollout switches. This master plan orders independently reviewable workstreams; existing detailed plans remain binding, and new feature work gets a focused child spec/plan before code. Do not combine unrelated money, consent and marketplace expansions in one PR.

**Tech stack:** existing Laravel13/PHP8.5, React/Inertia, PostgreSQL, private storage, Paystack, Pest/Vitest and tracked Vite assets. No dependency additions assumed.

**Sources:** `docs/superpowers/community-roadmap.md`; `docs/superpowers/marketplace-roadmap.md`; `docs/superpowers/plans/2026-10-21-community-followups.md`; Community design, Giving/member-benefit/Library plans; Marketplace S2b design and acceptance plan. Read AGENTS finance/session/commerce invariants before relevant work.

## Execution and release constraints

- Use the owned `artifact-completion` worktree; never edit, reset, clean, remove or migrate Claude's checkouts/databases. Reuse inactive branches where appropriate. Inventory exact paths before cleanup.
- Native implementation. New fixes start with a failing regression; existing reviewed work is reconciled with current main, then retested. Tick steps in the commit carrying the work and record commands/results honestly.
- One proportionate self-review and one fresh review per release; fix review findings with regressions rather than repeating whole reviews. Use affected local tests and one final CI gate; rerun failed CI jobs only when appropriate. Never merge pending or failed checks.
- Commit rebuilt `public/build` with JS/CSS changes; run Pint on changed PHP. Rebuild generated conflicts rather than choosing old assets.
- Owner authorised systematic implementation and release of agreed fixes. New commercial terms, real charges/transfers, historical financial cutovers, public identity publication, broad tenant activation and irreversible data deletion retain their specific approval gates. Reuse already approved test-mode accounts and synthetic recipients only within their authorised scope.
- Purpledot remains the production pilot. Other organisations stay unchanged. Giving online and historical cutover are independent from venue/merchandise activation.
- Never invent real photos, venue facilities, stock, customer identities, passwords, consent or successful payment/delivery evidence. No bulk resend/retry of historical financial or mail jobs.
- Preserve original verified-email ownership, member/representative isolation, consent withdrawal and financial idempotency. Save tickets only through explicit offline selection; never cache private money/messages/documents.

## Review Focus

1. An old PR may pass CI but remain unreleased or overlap current main; compare actual source and final-head checks before merging.
2. A provider-accepted send/capture is not recipient delivery or reconciled money; record terminal evidence and any ambiguity separately.
3. No-dues churches and volunteer groups must use Giving, Library, services and polls without accidental membership/dues prerequisites.
4. Accessibility/offline improvements must preserve access revocation, representative boundaries and signed-out/expired-proof behaviour.
5. Calendar renewal dates, storage limits, public profiles and marketplace expansion have business/privacy consequences; do not silently turn recommendations into live policy.

## Phase 0 — Reconcile the inventory

- [x] Read current roadmap/follow-up records and actual source; inspect open PRs and worktrees.
- [x] Confirm PR48 (`codex/ticket-layout`, `e4c2a200`) is still draft/unmerged; current ticket component retains the squeezed note/QR row. Earlier CI was green but is not a current-main release gate.
- [x] Confirm PR47 ticket-mail retries are already merged; `ping_threshold=9` and bounded retry code exist. Do not rebuild this fix from stale unchecked notes.
- [x] Confirm PR59 is an obsolete S2a documentation update; S2a/S2b and later pilot records already supersede it. Close it with a concise explanation after preserving any unique information.
- [x] Confirm Community milestones1–7/4b are deployed; remaining items below are acceptance, policy, follow-up features and marketplace expansion, not unbuilt original milestones.
- [x] Confirm existing editable marketing pack exists; refresh it rather than create an unrelated replacement.

## Phase 1 — Release the ticket layout correction (start here)

**Files:** `resources/js/Pages/Public/Events/AttendeePortal/panels/MyTicketPanel.jsx`; `resources/js/test/portal/MyTicketPanel.test.jsx`; tracked assets; this plan.
**Input:** reviewed PR48 implementation and current main. **Output:** readable responsive ticket notice, QR, details/actions with original verification/payment/transfer/certificate behaviour intact.

- [x] Reconcile current main into the existing ticket branch without rewriting reviewed history. Source merges cleanly; generated asset conflicts require a rebuild.
- [x] Compare the merged source with PR48's reviewed change; retain later main features and identify any new interaction regression.
- [x] Add a failing test for any newly demonstrated gap (including theme-aware primary action text), then implement only that correction.
- [x] Run the ticket/attendee tests and existing enlarged-text/long-name/no-QR cases; lint, Pint, rebuild assets and check whitespace.
- [x] Browser-check the real built component at desktop/390px, light/dark and long values. Earlier enlarged-text evidence is retained; fresh physical-device/200% checks remain explicitly in Phase3. Keep synthetic/local evidence distinct from live verification.
- [x] Self-review touchpoints, empty/loading/error, permission, keyboard and responsive states; one independent review of the reconciled change.
- [x] Update PR48's description to its final scope; mark ready; push normally; require green CI on the exact final head; squash-merge and monitor automatic Cloud deployment.
- [x] Verify the live ticket surface using normal member access. Owner completed fresh email verification; desktop/390px and dark-theme checks passed. No new ticket or unnecessary mail sent.

## Phase 2 — Giving and paid member-benefit acceptance

**Files/services:** `app/Services/Giving/{GivingRollout,GivingCheckout,GivingPaymentConfirmer,GivingReceipts,GivingRefunds,GivingBalance}.php`; `tests/Feature/Giving/*`; `tests/Feature/Events/MemberBenefit{Checkout,Payment,Quote,Lifecycle,Webhook}Test.php`; existing Giving/benefits plans.
**Output:** a source-specific financial readiness record; corrections only where a failure is reproduced.

- [x] Inventory effective flags, settlement/account configuration and existing acceptance records read-only; do not assume venue tests certify Giving or ticket pricing.
- [x] Prepare isolated church and volunteer-group fixtures with no dues. Reuse approved test-mode provider credentials/synthetic recipients; never replace production keys.
- [x] Verify voluntary giving, optional member attribution, receipt and Money/inbox access; failed/abandoned/duplicate callbacks; exact amounts and balanced/idempotent ledger entries.
- [x] Verify active-member prices and admission, ordinary/nonmember prices, quote expiry, lapsed membership during checkout, free/paid recovery and representative restrictions.
- [x] Verify Giving refund/correction funding and payout/OTP reconciliation with original accounts/references; owner performs any required hosted/OTP action privately.
- [x] For each defect: failing regression → minimal fix → affected suite → review/CI/release. Restore only temporary test publication; retain honest financial evidence.
- [x] Present a Giving online readiness recommendation. Enabling Purpledot online Giving needs its specific approval; historical contribution association/cutover requires a separate tenant-bounded inventory and approved boundary.

## Phase 3 — Accessibility, offline and representative acceptance

**Files:** personal-app components including `resources/js/Pages/Public/Community/CommunitiesPanel.jsx`; ticket/portal components and `resources/js/lib/offlineTicketStore.*`; existing portal tests and follow-up plan §4.

- [ ] Audit primary controls on both themes; reproduce white-on-bright-accent failures before replacing them with existing `text-accent-ink` semantics. Keep the iconic homepage blue unchanged.
- [ ] Check actual390px reflow, keyboard navigation/focus return, menu/dialog placement and200% text. Correct demonstrated defects with proportionate regressions.
- [ ] Save explicitly selected passes, cold-open offline, sign out/remove proof in another tab and verify clearing. Money, inbox, Library and service notes remain online-only.
- [ ] Exercise personal versus authorised representative contexts, expired proofs, reassigned/shared phone safeguards and cross-organisation denial.
- [ ] Prepare a short real-phone checklist for the owner. Emulation is not physical-device proof; record audio/vibration/offline scanner checks independently from the already-passed online QR scans.
- [ ] Release scoped fixes and record remaining owner-device actions without blocking other independent phases.

## Phase 4 — Library attachments and storage policy

**Files:** `app/Services/Community/{CommunityContentStorage,CommunityLibrary}.php`; `app/Services/Tenancy/StorageMeter.php`; existing Library tests/package allowance configuration.

- [ ] Read effective `storage_bytes` allowances and aggregate usage without downloading private content; identify what retained versions/assets count.
- [ ] Propose concrete tier allowances and over-limit treatment based on that inventory. Owner approves public/package limits before production configuration changes; do not invent prices.
- [ ] Reuse the existing aggregate meter and20MiB/file validation. Add boundary/concurrent-upload/retained-version/orphan-cleanup regressions only for missing behaviour.
- [ ] Exercise a synthetic PDF upload, authenticated view/download, foreign tenant and expired-proof denial, unsigned-object denial and readable history while over quota.
- [ ] Apply approved missing-only configuration, verify capacity/error messages, release and record results. No silent deletion of existing documents.

## Phase 5 — Billing renewal date semantics

**Files:** `app/Services/Billing/SubscriptionRenewalService.php`; `tests/Feature/Billing/SubscriptionRenewalTest.php`; subscription model and billing plan after inspection.

- [ ] Inspect current renewal dates and anchors read-only; prepare a focused child design before changing money-related date behaviour.
- [ ] Recommended rule for owner confirmation: preserve the intended billing day, clamp only invalid dates to month end, and restore the anchor in later valid months; explicitly cover annual29February. Honour already-issued invoices/quoted payment periods.
- [ ] Write failing Jan31/Feb28/Feb29/Mar31 and leap-year regressions, plus grace/idempotency/reusable-authorisation checks; implement using existing renewal writer rather than a second payment path.
- [ ] Release after review and CI. No retroactive invoice rewrite, automatic extra charge or production subscription migration without an explicit bounded approval.

## Phase 6 — Public attendee opt-in profiles

**Sources:** Community follow-ups §6; current public event Going count and registration/identity flows.

- [ ] Produce the consent/visibility design and visual preview first: verified explicit opt-in, optional display name/photo, preview, immediate withdrawal, image safety/retention, cancellation/refund behaviour and public-cache invalidation.
- [ ] Obtain the requested visual/design review before implementing public identity storage/endpoints. Existing attendees are never published automatically; anonymous Going count remains independent.
- [ ] Write the native implementation plan against current code, then implement in a separately reviewed release with absent/withdrawn consent, isolation and unsafe-image regressions.

## Phase 7 — Complete the remaining marketplace roadmap

**Sources:** `docs/superpowers/marketplace-roadmap.md`, original Marketplace handoff and S2b specs. Merchandise and Purpledot venue pilot are live; do not rebuild S1/S2a/S2b.

- [ ] **S2c:** design/implement bank-transfer instructions, pro-forma and private payment evidence/manual finance review. A screenshot/upload never marks paid; require authorised reconciliation, append-only evidence, exact amount/currency/account/reference and duplicate/conflict guards. Owner approves new financial policy before activation.
- [ ] **S3:** focused design/plan for dated equipment quantities/assets, availability, collection/delivery/returns and service schedules/deliverables. Reuse catalog, quotes, verified buyers and commerce; preserve venue calendar and merchandise stock. Confirm damage/security/late-return policy before trading activation.
- [ ] **S4:** complete private booker/host messaging, attachments/live updates, walk-in intake and consent-bound booking→event handoff. Test wrong-tenant denial and idempotent event creation; preserve staff-room boundaries.
- [ ] **S5:** consent/moderation/dispute-aware reciprocal reviews, declared cross-category ranking and clearly disclosed promotions with expiry. Only eligible completed transactions may review.
- [ ] Each slice gets its own concrete spec, native task plan, failure tests, review/CI and pilot acceptance. No mixed multi-seller/type checkout or wider merchant activation is assumed.

## Phase 8 — Marketing pack and pilot content

**Existing artifact:** task visualization directory `marketing-pack/MiConvener Marketing and Proposal Pack.docx`; existing branded QA PDF; current pricing/use-case pages.

- [ ] Read the existing pack and verify every feature/pricing claim against current releases and rollout scope.
- [ ] Refresh editable Word material and audience proposals for churches, associations, volunteer/NGO groups, academia, social/memorial events and venue/facility operators. Show contributions without dues, recurring service notes, Library, communication and commerce accurately.
- [ ] Render/visually review the Word outputs using document skills; deliver an index and editable files. Do not send them to prospects without instructions.
- [ ] Obtain actual classroom photos and any missing commercial content from the owner; never substitute an unrelated venue image or invent a hire rate. Preserve price-on-request until a rate is supplied.
- [ ] Recheck USA onboarding/mobile sign-in using current deployed fixes and international-card settings. Label test-mode/geography simulations; a real international purchase requires an owner-approved payer/amount and owner payment entry.

## Phase 9 — Operational/repository closure

- [x] Close superseded PR59 after recording its replacement; PR48 is now merged and deployed.
- [ ] Identify the old risky PHP test and intermittent badge/component timing failures from current named CI results. Fix demonstrated test defects without dropping assertions or suppressing failures.
- [ ] Measure current CI/setup time before proposing a separate path-aware CI optimisation; frontend-only work should not repeatedly trigger local+remote full runs. Repository protection changes need explicit settings approval; do not bypass checks.
- [ ] Inventory each worktree/branch's uncommitted files and unique commits read-only. Archive/remove only proven redundant owned worktrees; coordinate Claude-owned resources and keep active Herd paths usable. No blanket `git clean` or database-name glob deletion.
- [ ] Retain the quarantined GHS10 test fee mismatch as evidence; only clean exact owned disposable databases after evidence is retained and deletion is approved. Never rewrite financial history or resend that transfer.
- [ ] Consider durable provider-message-ID/delivery-status visibility as a separate scoped operational feature; distinguish queue, SMTP acceptance, recipient-server acceptance and actual receipt. The merged ticket SMTP retry fix is not outstanding implementation.
- [ ] Reconcile outdated roadmap summaries/checklists, mark each phase implemented/tested/deployed/blocked accurately, and publish the final remaining decisions. A completed phase must include its release and acceptance evidence.

## Start state and decision log

- Phase0 inventory complete; Phase1 integration is in progress on the existing ticket branch. Only generated assets conflicted with current main; rebuild them.
- Owner authorisation covers systematic native implementation; it does not manufacture missing storage/renewal/commercial policy, real photos, consent or a person's OTP/device action.
- This plan deliberately separates future large-feature child designs from immediate repair/acceptance tasks. A future phase is not falsely labelled implementation-ready before its exact interfaces and decisions are established.


### Phase 1 execution evidence — 2 November 2026

- Current main `63d0f881` reconciled into PR48; source merged without conflict. Generated conflicts resolved by rebuilding. No other checkout/database changed.
- Two new regressions reproduced the dark-mode primary-action foreground gaps before correction; self-check-in and explicit offline-consent actions now use `text-accent-ink`. Layout/handler changes otherwise remain the previously reviewed PR48 correction.
- Portal group123/123 passed with `--maxWorkers=2`; the first unrestricted run had one5second timeout in unchanged `AttendeeApp.test.jsx` (122pass/1fail). No timeout limit or assertion was weakened. PHP `TicketEmailQrTest` + `SelfCheckInTest`:9passed/27assertions. ESLint, Pint and production build passed. Source whitespace is clean; the generated vendor bundle retains the same one trailing-whitespace line as main and was not hand-edited.
- Fresh real-page local fixture uses synthetic event/registration data and an already normally verified synthetic buyer session; it is presentation evidence, not a live registration, payment, email-delivery or verification-flow pass. Desktop1440: details408px, QR160×160, note outside region, no document overflow. Phone390: details342px, QR160×160, note outside region, no document overflow; light/dark inspected and keyboard focus visible. PR48's earlier40-combination enlarged-text/long/no-QR matrix remains historical evidence; new physical-device/200% acceptance is tracked in Phase3, not claimed fresh here.
- One fresh independent review found no functional/accessibility blockers. No dependency, endpoint, permission, schema, sender/account or financial behaviour changed.
- Ruling: retain the previously reviewed layout rather than redesigning it; fix only the demonstrated foreground issues. Risk if wrong: visual regression, bounded by the component/browser tests. Larger accessibility/offline acceptance remains a separate phase.

### Phase 1 release and Phase 2 acceptance progress — 2 November 2026

- PR48 final head `840e1ef7`: code quality passed3m29s and Laravel passed14m46s, first attempts. Squash-merged as `7825ede7`; production Cloud deployment `depl-a320f39a-9603-4a9a-8f83-580c8ee03b84` succeeded11:35:22UTC. Live protected ticket check correctly requires fresh normal email verification; owner asked to sign in, no bypass or extra email/ticket issuance.
- Read-only production inventory: Purpledot Giving screens enabled, online disabled, platform-default settlement, current Giving rate1%, no funds/gifts/attempts; Library version bytes0, storage limit unset. No production settings changed by these checks.
- Isolated database `miconvener_acceptance_codex_20261102`, Paystack test credentials, log-only mail. Two synthetic organisations (`giving-church-qa`, `giving-volunteer-qa`), each with zero Community members and dues. Native local feature commands and fund service used. No real donations, recipients or live flags.
- Each organisation completed one GHS1 simulated-card payment through the actual hosted Paystack test page and native callback. Each attempt confirmed, gift completed, fee2pesewas, Free/no-plan commission2pesewas, net96pesewas. Each created exactly one Giving sale journal, debit98/credit98. These test fees/rates do not redefine Purpledot's commercial terms.
- Volunteer gift selected anonymity. Private PDF receipt downloaded through normal capability; rendered/readable. Already normally verified synthetic buyer sees both completed gifts in `/my/giving`; receipt email entry itself was not used as verification. Staff console reports receipt sent through log mail, not recipient delivery.
- Church staff recorded a GHS5 offering batch and a separate GHS1 correction through the real controls. Online payout availability stayed GHS0.96; cash created no additional sale journal. Original cash record retained.
- Full online refund preview reverses2pesewas commission and warns the original2pesewas processing fee leaves a shortfall. One native test refund submitted; provider ID18954600 initially processing, its reserve reduced available payout to0. No repeat submission or invented confirmation. Final outcome remains to verify.
- Focused automated acceptance: Giving EndToEnd/Checkout/Confirmation and MemberBenefit Checkout/Quote/Payment,82passed/294assertions. These use mocked provider cases and are distinct from the two hosted test captures. Remaining Phase2 cases, real-device checks and Giving online activation are not marked complete.

- Live ticket acceptance completed after the owner signed in normally: deployed ticket region found, desktop1280px with QR160px/details408px; phone390px with QR160px/details342px stacked below, document width390px (no horizontal overflow), personal-registration notice outside the ticket region and PDF action present. Dark mode kept the region readable and within viewport. Restored normal size/light theme. No check-in, transfer, offline save, new registration or mail action performed; physical-device/200% coverage remains Phase3.

- Refund follow-up: original-account provider lookup returned `processed` for existing test refund18954600. Native staff “Check refund status” reconciled it to confirmed; gift is refunded, report refundsGHS1/net online0, payout availability0. No second refund submitted. Earlier pending status was a stale local acceptance record, not an active queue job. The queue worker is running; pending jobs0. Remaining acceptance work must continue without treating a final chat reply as ongoing background execution.

### Phase 2 completion and Phase 3 start — 2 November 2026

- A further GHS10 Giving test capture confirmed through hosted test checkout; volunteer Giving payable10.56GHS. Native payout-account screen verified the previously approved synthetic MTN0551234987 through Paystack. One GHS2 Giving payout reserved and sent; owner personally entered OTP. Original-reference lookup verified status success, amount200/currencyGHS matching the frozen request; native CommunityPayouts posted it once as paid. All Giving journals balanced.
- Paystack test transfers charge zero; the local-only mobile transfer fee was temporarily0 for this new test intent, recorded requested/net200 and fee0, then original local configuration restored immediately after intent creation. No production fee, old payout, provider evidence or quarantined GHS10 transfer changed.
- Existing normally verified local buyer completed the native member verification code flow, then a real hosted test checkout: standard price200pesewas, active personal member100pesewas. Registration confirmed, attempt fulfilled, charged100GHS minor units, no review reason. No dues required. Ordinary/lapsed/expiry/representative/duplicate and uncertainty paths retain automated evidence rather than invented manual passes.
- Additional focused suites: Giving Payout/Refund/Reconciliation/ReceiptDelivery/MyGivingAccess and MemberBenefit Lifecycle/Webhook/PayoutReservation,109passed/493assertions. Combined with earlier82/294; tests are simulated-provider edge coverage, distinct from hosted test captures/OTP above. No new finance defect reproduced; no finance source code changed.
- Owner explicitly approved standalone Purpledot online Giving at current1% Giving rate, tenant gateway costs/manual payouts, without publishing funds or historical cutover. Native `community:enable-giving purpledot --online` succeeded; remote verification `comm-a3211206-a569-4c47-9508-48027ed135c9`: screens/online true, other organisations' feature hashes unchanged, funds0/gifts0. Actual live fund content remains an owner-created business record, not fabricated acceptance data.
- Owner approved renewal anchor/clamping rule; keep existing invoices/payments unchanged. Owner instructed preserving all current storage limits and reviewing missing allowances before production changes.
- Phase3 audit reproduced5 primary foreground failures: follow verification/follow action, live workspace, pending action and Community messages. RED5failed/16passed; minimal existing `text-accent-ink` correction GREEN21/21. Same solid-accent pattern corrected across14 Community/attendee files (21foreground substitutions), no handlers/permissions/endpoints changed. Whole frontend run was stopped after six failures in unchanged console/marketplace tests under Mac load; it is not green. Final CI must run the whole suite. No release claimed at this point.

### Phase 3 scoped foreground release — self-review

**Touchpoints:**15 Community/attendee JSX components, existing primary-action token only; additive component regressions and rebuilt assets. No dependencies, handlers, data payloads, feature switches, routes, permissions, offline storage rules, homepage blue or financial service code changed. Empty/loading/error and medical-red controls retain existing behaviour.

- Initial5 foreground regressions RED→GREEN21/21. All affected portal/Community suites139/139passed. One fresh reviewer found a multiline normal help-submit foreground missed by the initial search; added normal/medical branch regressions, observed normal RED1failed/5passed, then preserved urgent red/white and switched only ordinary accent/ink. Final affected fix group32/32passed (PrimaryActionContrast, AttendeeInbox, Portal). No assertions/timeouts weakened and no second whole-change review.
- Independent review otherwise found no blockers: replacements are solid-accent only and assets resolve. Review correction source is GetHelpPanel; external full-suite failures remain explicitly disclosed. Build repeated after that new change and passed43.60s. Existing large-bundle warning retained.
- Actual built local page: Community primary action foreground/background light rgb255,255,255/rgb0,115,103 and dark rgb5,32,29/rgb0,216,196. Ratios5.76:1 and9.44:1, old dark white1.81:1. Actual390px document width390; View messages and Back to Communities native navigation work. Normal viewport restored. Synthetic test organisation/normal email proof, not fabricated production UI evidence.
- Larger Phase3 device/offline/200% checks remain open; this is a bounded foreground release, not a claim of full accessibility certification.
- Read-only storage inventory found all five package storage allowances unset, all ten organisations' Library version bytes0. Native aggregate meter for Purpledot188396bytes, effective storage limit null (`comm-a3211324-0ad7-419c-ad08-575cc191fb92`). No content downloaded and no production storage settings changed. Owner reviewing proposed defaults for future organisations only; existing allowances must be preserved.

**Ruling:** use the already-established accent-ink pair at each solid-accent control; do not hide mismatches with a broad CSS override or recolour the brand. Cost if wrong is limited to foreground rendering, covered by additive component and actual theme checks.
**Deferred limitations:** physical-device/offline/200% acceptance and unrelated load-sensitive console tests remain separate tasks. Full final-head CI is mandatory before merge.
