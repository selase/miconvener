# Community milestone 6: Member benefits in Events Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans for the established native approach. Implement task by task, failing tests first. Tick steps in the same commit as their implementation. One fresh whole-branch review at the end; no review or whole-suite cycle after each task.

**Goal:** Organisations offer members-only event registration and optional member ticket prices, using verified personal membership without requiring dues.

**Architecture:** Extend existing Events, registration pricing and payment fulfilment. Resolve personal membership through current email/self-grant rules; persist a server-generated, payment-bound benefit quote for online payments. A quote freezes a price, not personal access or a guaranteed seat. Existing unrestricted registrations retain their behaviour.

**Tech Stack:** Laravel13/PHP8.5, PostgreSQL landlord connection, Pest5, Inertia/React and existing console primitives; existing Paystack/Stripe gateways and LedgerService. No dependencies added.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md` §§2,6.1,6.3,10 item8; proposed §6.4 below that spec. Roadmap milestone6 and `AGENTS.md` finance invariants apply.

**Baseline:** production/main `b4ad5211c741da0b20d4b18d74d0a90208eae14b` (Giving PR37), verified against fetched main2026-10-18. This planning branch also carries Giving release documentation `4a346e84`. **Owner approved native implementation2026-10-18. Push, merge, deployment and production changes still require approval.**

## Decisions proposed for review

These are recommendations, not recorded owner approval. The owner restated the earlier active-members/short-window recommendation; the exact rules below still need review.

1. **Active members only, without a dues condition.** Pending, lapsed and exited members, followers and representatives are not personally eligible. An active member with no dues, a waiver or arrears remains eligible. Any membership type qualifies while the member is active; type-specific discounts and family/group purchases are outside this milestone.
2. **Verification before benefit application.** Reuse email-first verification. `MemberAccess::role($session, $member) === 'self'` plus exact registration email binding qualifies, including an independently established phone self grant. Preserve the existing event registration email requirement for both free and paid tickets; an email-less member needs to add an independently verified email through the existing contact process before using this event flow. Do not invent an email or treat a newly typed address as verified. A representative's grant must not become their own discount. Phone-only event registration is a separate follow-up, not silently added here.
3. **15-minute online quote.** Start the window when the person explicitly starts payment, after approval/waitlist release where applicable. Snapshot price, identity evidence, member/tenant/event/ticket, answers/promo, currency, fee terms and provider/account/reference. Do not extend the window on refresh or retry. A changed cart invalidates an unpaid quote and requires a visibly accepted fresh quote. Do not create a second provider attempt while the original outcome is unknown.
4. **Honour a valid quote if membership lapses, exits or changes type during its window.** Check membership again at confirmation, but preserve the entitlement snapshot when trusted provider evidence proves payment succeeded before expiry. Event cancellation, cancelled registration, identity/contact/grant revocation, wrong amount/account/currency and unavailable capacity require review; they are not permission to issue a ticket. Delayed webhook arrival alone is not lateness. Sign-out does not cancel a payment already initiated; it does end browser access to private records.
5. **Late/uncertain captured payments need an actionable review, not a silent failure or surcharge.** After expiry, block new initiation until re-verification/requote. Already captured payments outside the window, or without a trustworthy provider completion time, are recorded financially once and held as paid/benefit-review-required with no QR or confirmation email. Staff can refund through the existing authorised refund path; the recipient can reverify to request fulfilment, which still requires current eligibility, original staff approval and capacity checks. No arbitrary membership override and no automatic repeat charge. Provider fixtures must prove timestamp provenance per adapter; a browser timestamp or checkout-session creation time is insufficient.
6. **Offline and free flows.** Free tickets require eligible self proof at final confirmation. Offline proof upload does not freeze eligibility for days: approval requires fresh eligible member identity evidence and active status at approval, otherwise retain proof/payment information for review without a ticket. Existing pending approval/waitlist rows get no standing 15-minute entitlement. If an old price can no longer be offered, show the revised total and require explicit acceptance before starting payment.
7. **Prices and promotions.** Optional nullable member base price on an event without ticket types, or on each ticket type. Null means standard price; zero is a free member ticket. Validate `0 <= member_price <= standard_price`. Reuse the existing order: selected base → form override/additions → existing promo rules → FeeCalculator. A form override still overrides the base. No hidden extra member discount or new promo-stacking rule.
8. **Transfers and rollout.** Preserve all unrestricted ticket transfers. Block self-service transfers of newly benefit-bound tickets with clear copy in this milestone; supporting verified member-to-member transfer is a follow-up. This prevents an unverified recipient receiving a member ticket. Existing registrations are grandfathered when an organiser later enables restrictions. Use an independent `community_event_benefits` switch with Community on; Purpledot-only activation follows an approved release. Switching off stops new benefits but preserves existing quoted/paid ticket history and recovery.

## Global Constraints

- **No second events place.** All settings and registration remain in Events; Community links into them.
- Money is integer minor units; commission and fee bearer resolve through PlatformFeeResolver/FeeCalculator. LedgerService remains the only ledger writer; reference-based idempotency applies independently of ticket issuance.
- Member identity is tenant-bounded and email-first; no global person table, phone-match discovery, implicit representative entitlement or new membership/follow/consent side effect. Re-read contact/grant versions; a pay link or ticket link is not membership proof.
- No forced dues setup, arrears clearance, membership creation, plan upgrade or automatic payment. Preserve paid-ticket plan rules and LiveEventProtection.
- Require existing `update event` permission for settings; recovery/refund controls retain current event/finance permissions. No new staff override permission.
- Defaults are unrestricted, member price null, rollout off; old rows retain behaviour. Do not scan/alter production data while implementing. Do not expose member names, statuses, identifiers or quote evidence in public payloads/logs.
- Commit rebuilt `public/build` with JS/CSS changes; no new dependencies. Use existing Inertia/React patterns, declared Console props, Form Requests for added validation and named routes.
- Follow the native workflow: focused tests per task, one fresh final review, one integrated full CI gate; do not repeat blanket reviews or full suites without new cause. No merge/deploy without owner approval.

## Review Focus

1. Shared email/phone, representative grants and guessed UUIDs cannot let one person buy as another or reveal membership existence (Tasks1,3).
2. A payment made at14:59 and delivered by webhook much later is honoured; a payment at15:00 is expired, recorded once and visible for recovery (Task4).
3. Waitlist promotion, manual approval, free member tickets and offline proofs cannot bypass eligibility or reserve a stale discount indefinitely (Tasks3,5).
4. Membership/contact changes, event cancellation, capacity races and duplicate provider callbacks cannot issue an invalid QR or lose already collected money (Tasks2,4,5).
5. Zero member price, existing form overrides/promos, old ticket transfers and a disabled rollout preserve existing pricing, fees, privacy and historical access (Tasks2,3,5,6).

## Existing touchpoints and proposed file boundaries

Confirmed existing files: `app/Services/Community/MemberAccess.php`, `MemberIdentity.php`; `app/Services/Events/RegistrationPricingService.php`, `PlatformAttendeeVerification.php`; `app/Models/Event.php`, `EventTicketType.php`, `EventRegistration.php`; `app/Http/Controllers/Public/PublicEventController.php`, `EventCheckoutController.php`, `SettlementWebhookController.php`, `AttendeePortalController.php`; `app/Http/Controllers/Tenant/EventController.php`, `EventTicketTypeController.php`, `EventRegistrationController.php`, `Commerce/WebhookController.php`; `routes/subdomain.php`; `resources/js/Pages/Public/Events/Show.jsx`, `Checkout.jsx`, `AttendeePortal/panels/MyTicketPanel.jsx`; `resources/js/Pages/Tenant/Events/EventFormModal.jsx`, `TicketTypesPanel.jsx`, `EditRegistrationModal.jsx`.

New focused services under `app/Services/Events/`: `MemberBenefitEligibility`, `MemberBenefitQuotes`, `MemberBenefitPayments`. New records: `EventMemberBenefitQuote` and `EventMemberBenefitAttempt`, with factories; no production demo seeder. Added request classes live under existing `app/Http/Requests/Tenant/` and `Public/` directories. Create `database/migrations/landlord/2026_10_18_120000_add_event_member_benefits.php` to extend Event/EventTicketType/EventRegistration and create quote/attempt tables, with tenant-bounded foreign keys, unique attempt reference/provider IDs, bigint nonnegative amounts and indexes for unresolved review. Down migration must refuse while benefit quotes/attempts exist; disabling new issuance is the operational rollback, never deleting paid history. Existing member-benefit-free payment branches stay unchanged; do not refactor the whole event payments subsystem.

### Task 1: Authoritative personal eligibility and settings

**Files:** create `app/Services/Events/MemberBenefitEligibility.php`; modify Event/EventTicketType models, existing event/ticket settings controllers and `HandleInertiaRequests`; create `UpdateEventMemberBenefitsRequest.php`, `StoreEventTicketTypeRequest.php`, `UpdateEventTicketTypeRequest.php`; migration/factories; test `tests/Feature/Events/MemberBenefitEligibilityTest.php`, `MemberBenefitSettingsTest.php`.

**Interfaces:** `MemberBenefitEligibility::resolve(Session $session, Event $event, string $registrationEmail): ?Member` returns only an active, same-tenant self member bound to that email. Multiple matching eligible records choose the lowest UUID deterministically, without expanding access. `enabled(Tenant $tenant): bool` requires Community plus `community_event_benefits`. Event fields `members_only:boolean=false`, `member_ticket_price:?int=null`; ticket field `member_price:?int=null`.

- [x] Write failing datasets: active self with no dues/arrears/waiver allowed; pending/lapsed/exited denied; follower, representative, unverified email, bare phone and cross-tenant IDs denied; revoked/suspended/expired grants denied; email proof must match submitted attendee email. An approved phone self grant qualifies only for its exact member and current recorded registration email; an email-less member gets an actionable email requirement, not an invented address. Public failures have generic copy without membership disclosure.
- [x] Run `php artisan test --compact tests/Feature/Events/MemberBenefitEligibilityTest.php tests/Feature/Events/MemberBenefitSettingsTest.php`; observe missing-behaviour failures.
- [x] Add default-off fields/flag and tenant-bounded eligibility using existing proof/services. Add Form Request validation, existing permission checks and ticket-price bounds; reject setting paid member prices when the existing event cannot sell paid tickets. Scope any new route to its actual event and tenant. Keep email/self access-version checks on each eligibility request.
- [x] Run both test files plus `tests/Feature/Community/MemberAccessTest.php` and `MemberAccessRevocationTest.php`; all pass. Run Pint; tick and commit `feat(events): define verified member benefits`.

### Task 2: Server-side pricing and durable quote contracts

**Files:** create quote/attempt models/factories and `MemberBenefitQuotes.php`; extend migration, EventRegistration and RegistrationPricingService; test `tests/Feature/Events/MemberBenefitQuoteTest.php`.

**Interfaces:** `RegistrationPricingService::calculatePrice(Event $event, ?EventTicketType $ticketType, array $cleanedAnswers, ?int $memberBasePrice = null): array` preserves the existing three-argument result. Only trusted eligibility code supplies the fourth argument. `MemberBenefitQuotes::start(Session $session, EventRegistration $registration): EventMemberBenefitQuote` locks/revalidates, freezes accepted totals, expires at `created_at + 15 minutes`, and reuses a still-valid unchanged quote. `assertUsable(EventMemberBenefitQuote $quote, EventRegistration $registration): void` rejects expiry, changed cart or context. All timestamps are UTC, expiry boundary is exclusive.

Persist quote tenant/event/registration/member, identity kind, email/phone digest plus member access_version and applicable credential ID/version, standard/member base and final pricing breakdown, amount/currency/fee bearer/commission/charged total, expires_at, immutable cart fingerprint and state. Do not store verification codes or recovery secrets. EventRegistration stores `member_benefit_required=false` by default, a nullable quote pointer and a hidden nullable `member_benefit_identity` JSON snapshot (proof kind, member/context, current contact/grant versions and original non-sliding proof expiry) for free/offline recipient revalidation; mark new restricted/discounted rows on the server. Attempt stores quote/context, provider, original account/gateway, unique provider reference and provider IDs, frozen expected totals, verified completion time, capture/fulfilment outcome and review reason. One unresolved attempt per quote, enforced atomically; no duplicate network request under row lock. Claim a stable reference/idempotency key before the gateway call, then store outcome in a separate transaction; uncertain outcomes retain the claim.

- [x] Write failing tests for null vs0, price bounds, member base/form override/additions/promo order, attendee vs organiser fee bearer, waived commission, modified request totals, repeated start, cart change, simultaneous starts, expiry at14:59/15:00, changed member identity and frozen gateway/fee terms. No new amount is silently substituted after acceptance.
- [x] Run `php artisan test --compact tests/Feature/Events/MemberBenefitQuoteTest.php`; confirm RED.
- [x] Implement the interfaces and additive constraints on the landlord connection. Use current pricing/promo/fee services; no discount arithmetic in controllers. Creation of a quote does not reserve capacity. New approval/waitlist rows carry the benefit requirement but get their payment quote only when permitted to pay.
- [x] Run quote and existing registration/promo pricing tests discovered under `tests/Feature/Events`; verify unchanged unrestricted callers. Pint, tick and commit `feat(events): snapshot member checkout quotes`.

### Task 3: Public registration and explicit payment initiation

**Files:** modify PublicEventController, EventCheckoutController, routes/subdomain.php and existing verification integrations; new `StartEventMemberCheckoutRequest.php`; test `tests/Feature/Events/MemberBenefitRegistrationTest.php`, `MemberBenefitCheckoutTest.php`.

**Interfaces:** add a session-blocked/throttled POST `public.events.member-benefits` at `/e/{event}/member-benefits`, which returns only current-session eligibility and applicable prices, never a membership directory. Reuse existing email verification and phone/self recovery; no second OTP implementation. Eligibility previews are private/no-store. Update `PublicEventController::validatePromo` to use the same trusted member base when eligible, preserving current promo limits and generic errors. Provider initiation for benefit-bound rows must call Task2 `start`; a registration/workspace URL alone cannot mint a member quote.

- [x] Write failing tests: public member-price discovery cannot be forged; submit someone else's member ID/email denied; free restricted event blocked before proof; approved phone self grant works for its exact recorded-email member; email-less members see the existing event email requirement for free and paid flows without fake email or inferred mailbox verification. Standard-price registration still works for non-members when event is public. Member-only remains restricted even when no discount or promo makes the ticket free. Invite code, form validation, email verification, quota and payment-plan checks still apply.
- [x] Run both new test files; confirm RED. Add tests for pending approval/waitlist getting no payment entitlement until released and revalidated; old unrestricted rows remain valid after enabling members-only.
- [x] Integrate server eligibility/pricing at register and each provider start. Bind quote/attempt to Paystack original reference and Stripe idempotent session/account using existing adapters; preserve legacy metadata handlers. Retry/failed-initiation never extends a quote or starts a second unknown charge. Before a fresh price starts payment, show and require acceptance of that precise new amount. Fail closed with an actionable reverify/requote message.
- [x] Run new registration/checkout tests plus EventRegistrationTest, RegistrationEmailVerificationTest, EventRegistrationIdempotencyTest, PlatformDefaultCheckoutTest and EventCheckoutGatewayMessageTest. Pint, tick and commit `feat(events): apply member benefits at verified checkout`.

### Task 4: Payment confirmation, delayed webhooks and paid recovery

**Files:** create `MemberBenefitPayments.php`; modify platform SettlementWebhookController and tenant Commerce/WebhookController, EventRegistration payment/review fields, existing staff refund/recovery actions and their request validation. Tests: `tests/Feature/Events/MemberBenefitPaymentTest.php`, `MemberBenefitRecoveryTest.php`.

**Interfaces:** `MemberBenefitPayments::confirm(EventMemberBenefitAttempt $attempt, array $verifiedPayment): EventRegistration` consumes `array{reference:string,provider:string,account_key:string,amount:int,currency:string,gateway_fee:int,paid_at:?CarbonImmutable}`, never raw browser claims. A missing completion timestamp is explicitly represented by null. Metadata can locate but cannot authorise an attempt: a benefit-bound registration without a matching quote/attempt must never fall through to the legacy ticket issuer. Reject forged/omitted benefit metadata in tests. States distinguish pending/unresolved, paid-fulfilled and paid-review-required; use `STATUS_PENDING_BENEFIT_REVIEW` on a registration paid but not ticketed, with captured money kept separately from ticket state. Ledger posting is once per original reference even if fulfilment is held; existing paid receipt data must remain reachable under normal private access rules. `reassess(EventRegistration $registration, Session $recipientSession): bool` consumes fresh self evidence for that recipient and resolves only when current event/capacity/identity rules allow, without another charge. Staff cannot manufacture recipient session proof. Add POST `/e/{event}/checkout/{registration}/member-benefits/reassess` named `public.events.member-benefits.reassess`, protected by workspace access, current self eligibility, throttle and session blocking; it only rechecks the existing paid attempt and never starts another charge. The staff screen links to the safe recipient reverify journey instead of accepting a supplied recipient session/member ID.

- [x] Write failing provider datasets for platform Paystack, own Paystack and connected Stripe: exact match, malformed/wrong reference/account/amount/currency, duplicate/out-of-order callbacks, unknown provider result and late delivery vs late payment. Capture14:59 with lapse/type change honoured; capture15:00 held. Missing trustworthy paid_at held. Contact/grant revocation, cancelled event/registration and exhausted capacity held even inside the window. Captured money appears once in the correct existing account/ledger and never disappears because no ticket was issued.
- [x] Run payment/recovery tests; confirm RED. Include simultaneous callback and refund, multiple attempts, post-flag-off confirmation, and replay after recovery. Wrong/mismatched receipts go to operational reconciliation without crediting an unverified source; do not guess an owner.
- [x] Route only benefit-bound attempts through the confirmer; preserve legacy confirmation branches. Lock attempt/registration/member/event in a consistent order; verify current identity versions, immutable quote and authoritative completion time. Before issuance recheck capacity and approval; issue one QR/confirmation/usage event only after fulfilment. Record a held payment truthfully with reason and authorised full refund/reconciliation actions; do not recalculate paid amounts or retry an unknown refund.
- [x] Run both new tests plus PlatformSettlementGuardTest, existing platform/own/Stripe webhook and registration cancel/refund suites discovered in `tests/Feature`. Confirm existing ledger idempotency and event payouts unaffected. Pint, tick and commit `feat(events): verify and recover member ticket payments`.

### Task 5: Close approval, offline, transfer and identity-edit paths

**Files:** modify Tenant/EventRegistrationController, Public/AttendeePortalController, Public/PublicEventController::verifyEmail, relevant existing models and UpdateEventRegistrationRequest; tests `tests/Feature/Events/MemberBenefitLifecycleTest.php`.

**Interfaces:** free confirmation and offline approval invoke Task1 eligibility for the recipient using persisted independent proof evidence revalidated against current contacts/grants; an organiser approval/email-edit is not self identity proof. A missing/expired proof leaves registration awaiting verification with an existing safe recipient verification link. Public verification authorises only the exact registration/member context, never other history. Restricted self-service transfer returns a clear422 and creates no transfer/code send.

- [x] Write failing tests for free confirmation after lapse, waitlist promotion after lapse/type change, old approval links, offline proof reviewed days later, duplicate staff approvals, administrative email/ticket edits and saved browser proofs. Editing recipient email invalidates unfulfilled quotes; confirmed benefit-bound recipient replacement uses the blocked-transfer policy, while harmless name/contact corrections retain existing permissions. Existing ticket transfers, refunds, scans and QR rotation for unrestricted rows remain green.
- [x] Run new lifecycle tests; confirm RED. Assert old unrestricted registrations are not retroactively restricted and disabling rollout never hides existing paid tickets or strands refunds/review items. An already valid issued ticket is not automatically revoked on later membership lapse.
- [x] Add eligibility checks at each listed transition and useful staff explanations, preserving existing approval/waitlist ordering, notification dedupe and offline accounting. Block new member quotes when rollout is off; continue verifying/recovering previously initiated payments using frozen terms.
- [x] Run lifecycle plus EventRegistrationApprovalTest, EventOfflinePaymentTest, TicketTransferVerificationTest, TransferredTicketRecordsTest and relevant cancel/refund/scan tests. Pint, tick and commit `fix(events): enforce member benefits across ticket lifecycle`.

### Task 6: Native UI, self-review and release handover

**Files:** modify the confirmed React event/ticket settings, public Show/Checkout, MyTicketPanel and staff EditRegistrationModal; existing staff registration list/detail and checkout payload serializers as discovered by imports; add tests under `resources/js/test/` for member-benefit settings/checkout/review. Update parent spec proposed §6.4 to approved text, milestone6 row, relevant factories and this plan; public/build with each UI commit.

**Interfaces:** public capability payload contains only enabled/members_only, applicable ticket/member amounts and current-session eligibility; private quote payload contains quote ID, amounts, expires_at and safe next action. Never return stored identity hashes, credential IDs, member status or unrelated memberships. Use existing five-section app and event registration; no new Events area.

- [x] Write failing UI tests: organiser can enable members-only and nullable/zero member prices; attendee sees standard/member prices clearly, verifies email first, sees precise final total and expiration, cannot double-submit, and gets reverify/requote or paid-review guidance. Staff sees captured payment separately from ticket state and an authorised recovery/refund route. Transfer restriction is explained before attempting it. No fake countdown/seat reservation promise. Mobile/dark/loading/error/keyboard states are explicit.
- [x] Run the added UI tests, then implement with current React/console primitives and accessible labels. Add links from Community overview into the existing Events area only where needed; ensure no-dues members see benefits and read-only roles cannot edit. Rebuild and commit assets with the corresponding source, ticking steps.
- [x] Perform self-review against all five Review Focus items and every state transition. One fresh whole-branch reviewer checks identity/payment/race boundaries and UI touchpoints; fix Important/Critical findings with focused regressions. Record honest manual/provider limitations and any deferred minor; do not start a second blanket review without design changes.
- [x] Run focused PHP/JS checks while integrating, Pint --dirty, PHPStan0/no baseline growth, lint/build. After approved push, one full CI gate; avoid a redundant blanket local run if CI supplies that gate. Read-only finance:verify-categories must pass. Verify existing Finance, Payments, Dues, Giving, Community, Events and attendee-portal coverage plus PayoutOtpFlowTest. Never overlap refreshing database suites.
- [x] Browser-test on disposable local QA only: active no-dues member, lapsed member, non-member, representative, delayed paid result, free/paid/review UI and settings at320/390px, desktop, dark/light, keyboard and actual200% if browser control supports it. No real payment or outbound message. Separately approved provider sandbox sessions are still needed; mocks do not prove hosted timing behaviour.
- [ ] After approved upload and green full CI, update roadmap to built with exact evidence and request merge review. Local implementation and review are complete; retain the feature branch pending upload approval. No production flag change/deployment until approved; approved rollout enables Purpledot only, preserves existing settings and other organisations, and verifies schema/permissions/assets/queue plus recovery visibility. Roll back new issuance by switching off, not dropping paid records or stripping quote-reader support.

## Plan self-review

Coverage: settings/eligibility(Task1), frozen authoritative price(Task2), public verification and initiation(Task3), payment/ledger/recovery(Task4), all non-online transitions and transfer/edit boundaries(Task5), UI/privacy/rollout/evidence(Task6). The parent spec's confirmation check is retained with an explicitly proposed time-bounded exception for status/type changes; identity revocation is not waived. Dues independence includes active members in arrears. Review Focus tests are assigned to owning tasks.

Deliberate limits: all active members share the configured member price; no family purchase, representative discount, personalised dues-based pricing, self-service transfer of benefit-bound tickets, phone-only event registration or automatic membership creation. A15-minute price quote is not an inventory reservation. Late/uncertain money is visible and recoverable; gateway time provenance must be proved per adapter before claiming the window works in hosted checkout.

No application code or tests were changed/run for this documentation-only draft. Implementation must begin with RED tests and preserve current finance/identity contracts. Owner review is required for the proposed rules, especially expiry/review, offline timing and restricted transfers.

### Milestone 6 local validation evidence (2026-10-18)

- Integrated PHP gate178/178,640assertions; subsequent focused quote/presentation/webhook36/36,152assertions and original-source recovery10/10,58assertions after browser findings. Giving transport15/15,74assertions retained. Dedicated database `miconvener_community6_testing`; no overlapping schema-refresh suites.
- Frontend427/428 in full run; existing badge-editor timeout under contention passed11/11 in limited-worker rerun. Current member UI18/18; lint, Pint, PHPStan0 with reduced baseline, finance category verification and tracked build passed. Full remote CI remains required after approved upload.
- Disposable `benefits-qa` browser acceptance: native email verification using log-only delivery; active no-dues Volunteer gets100→50 member total; changed email and lapsed member lose discount and cannot start a members-only registration; free member registration through signed verification to confirmed ticket+QR; personal transfer restriction; paid-review recheck to confirmed receipt with no second-charge action; native settings/private source history. Actual light/dark at320/390/1280 widths, no horizontal overflow and contrasting typed text. Keyboard focus responds. Representatives and provider uncertainty are covered by automated boundary tests; no real provider payment or external test email was sent.
- Actual200% browser zoom could not be applied: keyboard shortcut left devicePixelRatio and viewport unchanged. Narrow-width checks are not represented as a zoom test. Provider sandbox/captured-time/refund behaviour still needs separately approved hosted acceptance.
- Browser findings fixed: hide cancelled member QR; explicit receipt and safe recovery links; fee/charged-amount fingerprint normalisation; terminal failure recovery; unknown Stripe session binding; explicit Stripe refund-fee evidence; archived receipt/refund recovery. No new dependencies, permissions, environment variables or production settings.
- Self-review question: “Is there anything I have overlooked? Is the solution optimal? Let me take a step back.” Final independent branch review and its findings will be recorded below before requesting integration.


## Self-Review Report — final local review, 2026-10-18

Is there anything I have overlooked? Is the solution optimal? Let me take a step back.

### Touchpoints updated

- [x] AGENTS.md: existing finance and Community invariants followed; no new global convention requiring an edit. Roadmap, parent spec and this plan carry milestone decisions.
- [x] Memory files: N/A; repository documents are the shared handover, no private memory dependency.
- [x] Seeders: N/A; no new permission, role or production demo data. Disposable browser fixtures stay outside the repository.
- [x] Navigation: native Event settings and Ticket Types expose member access/prices; Guests and private payment history expose recovery; checkout and personal tickets expose owner verification/status/receipt links, including archived payment recovery.
- [x] Permissions: existing read-event/update-event permissions, tenant boundaries and exact recipient ownership checked server-side; no new permission to propagate.
- [x] `.env.example`: N/A; no new environment variables or dependencies.
- [x] Config: default-off `community_event_benefits` requires Community; recovery retains historical-source access when new issuance is disabled. No production switch changed.
- [x] Tests: eligibility, prices, initiation, provider evidence, idempotence, refunds, payout reserve/OTP, lifecycle, recipient correction, UI and existing finance/registration contracts covered. Controller signature change has no direct test callers.

### UI Gap Analysis

- Discoverability: present through existing event settings, ticket types, guest management, checkout and personal-ticket entry points.
- Empty state: present for private source history, unavailable membership/price and verification; no fabricated source or member identity shown.
- Permission gating: present; source details require read-event, mutations require update-event, public ownership is verified independently. Disabled rollout blocks new benefit starts while preserving historical money recovery.
- Loading + errors: present; actions disable while submitting and show server errors. Uncertain/captured money shows review status and prevents a second payment route; no ticket is issued speculatively.
- Responsive: checked at320/390/1280px in disposable QA, including settings/history and checkout. No horizontal overflow observed.
- Accessibility: keyboard focus and native controls checked; light/dark field contrast checked. Actual200% zoom and physical-device acceptance remain unverified, because the automation shortcut did not change zoom.

### Solved (auto-fixed)

One fresh whole-branch review against b4ad5211..d1069411 reported zero Critical, seven Important and zero Minor. All seven were addressed in one grouped test-first pass; no second blanket review was commissioned.

1. Recheck authoritative current price before approval/promotion; stale zero prices cannot issue free tickets (`app/Services/Events/MemberBenefitLifecycle.php`, release).
2. Explicitly accept changed offline/free totals with fresh eligibility, preserve unresolved payment evidence, and align sidebar/current total (`MemberBenefitLifecycle.php`, reverify; `EventCheckoutController.php`, member context; `resources/js/Pages/Public/Events/Checkout.jsx`). Browser caught a cross-domain background-navigation failure after successful issuance: completed transitions now use normal page navigation, and verification forwards the server outcome.
3. Offline proof/approval require the correct released pending-payment state; pending approval, rejected and waitlisted rows cannot bypass admission (`PublicEventController.php`, `Tenant/EventRegistrationController.php`).
4. Recheck refund reserves under the event lock immediately before finalising a parked OTP transfer; retain the original transfer when blocked (`app/Services/Finance/PayoutRelease.php:43`, `PayoutSender.php`).
5. Recheck active personal status and exact current recipient after acquiring the member lock for new quotes and reassessment (`app/Services/Events/MemberBenefitEligibility.php:39`). Original on-time captured-quote lapse exception stays confined to confirmation.
6. Serialize ordinary and member issuance on shared inventory; preserve actual captured money without QR when no place remains, reserve real platform credits, and block second-payment entry points (`app/Services/Events/StandardEventPaymentConfirmer.php:24`, webhook delegates, `MemberBenefitBalance.php`, checkout review UI).
7. Cancelled/archived events never promote; member refunds also refuse draft promotion. Preserve the existing unrestricted administrator draft-waitlist workflow, explicitly covered by legacy tests (`MemberBenefitLifecycle.php:138`, `MemberBenefitRefunds.php`).

Earlier browser/review fixes also retained archived receipts, hid cancelled member QR codes, froze corrected-recipient browser/mail access versions, recovered terminal/unknown provider sources conservatively, required actual Stripe fee/refund evidence, and normalised accepted charged/fee amounts before quote fingerprints.

### Final validation evidence

- Final focused review/legacy compatibility gate on committed `d2fd3ccd`:26/26 passed,138 assertions,62.19seconds. Task6 local contract recorded for ccdde7da..d2fd3ccd; required full remote CI remains pending.
- Post-review broad PHP gate:320 passed/1 failed,1262 assertions. The sole failure was the existing draft administrator workflow; fixed compatibly rather than weakening its assertion. All affected files then passed107/107,481 assertions. Do not represent the broad run as321/321.
- Full frontend suite430/430 across68 files; after final navigation fix, affected member-payment/verification tests14/14. Lint and tracked production build passed after that last change. PHPStan0 errors, no baseline growth; Pint passed. Finance category verification exited0; console component usage5/5 and build-manifest references4/4 passed.
- Latest disposable browser acceptance: changed price50→0 explicitly accepted, confirmed ticket opened at central `/my/events/...`, zero console errors; changed price50→60 explicitly accepted, order summary60 and offline proof form shown. No proof submitted, real gateway payment or external message sent. A wait for a nonexistent literal “Offline payment” label timed out after the successful action; reading the actual page confirmed the native “Choose Payment Method” form and correct total.
- Earlier local browser coverage: active no-dues Volunteer, changed contact, lapsed denial, native signed email verification, free confirmed QR, personal transfer restriction, paid-review reassessment, safe receipt/settings/history. Representatives, provider uncertainty and race boundaries have automated coverage. Local QA changes only; original/Claude checkouts remain untouched.

### Recommended for user decision

- Approve pushing `codex/community-6` and opening a PR for the required full remote CI gate. This is the next integration step; full CI has not yet run for this branch. Merge/deployment remain separate approval decisions.
- Before promoting online member prices, approve hosted Paystack/Stripe sandbox acceptance for capture timing, original-account recovery and refunds (one focused session per provider; duration depends on account access). Automated transport tests do not prove hosted behaviour.
- Complete actual200% zoom and physical-device acceptance in a supported browser session before broad rollout (short manual session). No result is claimed here.

### Not gaps

- Churches/volunteer groups do not need dues: active personal membership is enough, including a zero-dues membership type and members in arrears.
- Followers and representatives are deliberately ineligible; no family discounts, phone-only event registration or personal-ticket transfer in this approved milestone.
- Quotes last15minutes and do not reserve inventory. Unknown or late captured money remains recoverable without speculative admission.
- No dependency was added unused; no new production data/flag, permission or environment setting was introduced. Hosted acceptance remains open rather than represented by mocks.

### Rulings I made

The following is the exhaustive ordered ruling record from implementation, retained here before deleting only this plan's temporary workspace. Costs describe what would need reconsideration if a ruling proves wrong.

1. existing isolated artifact-completion worktree reused on codex/community-6; original and Claude checkouts untouched. Cost if wrong: Relocate only the owned branch; never reset another checkout.

2. migration belongs in database/migrations/landlord, matching all event/community tables; plan root migration path corrected to existing convention. Cost if wrong: Move the migration discovery path before deployment.

3. preserve ticket type request fields on pre-existing callers; no new permissions or seeder grants introduced because update event already gates these settings. Cost if wrong: A separately approved permission would need seeder/role propagation.

4. add focused MemberBenefitIdentity service for hidden proof snapshots shared by quote/free/offline checks; preserve current contact/grant versions and non-sliding proof expiry, never secrets. Quote and attempt FK enforce tenant boundaries and DB partial unique indexes prevent unresolved duplicates across registration and quote. Cost if wrong: Change the shared proof contract and schema together.

5. existing promo reservation is reused for its same registration without incrementing/redenying its own redemption; recheck active/date/ticket applicability and calculateDiscount through existing model when refreshing price. Cost if wrong: needs explicit promo-reservation expiry policy, already absent from legacy registration. Cost if wrong: Define promo-reservation expiry explicitly.

6. dedicated local database miconvener_community6_testing created2026-10-18; all further tests use DB_DATABASE environment override. One combined regression command ended exit1 with an empty report; no pass is claimed. Separate database prevents interference with tests from other checkouts; it does not assert a proven cause for the empty report. Cost if wrong: Revisit local runner isolation; no production database is involved.

7. MemberBenefitGateways reuses existing GivingGateways pure account identity functions and native gateway/commission adapters, without creating Giving records or depending on Giving rollout. Original-account loss requires review rather than selecting a replacement account. Cost if wrong: Extract a separate pure identity helper if Giving coupling becomes undesirable.

8. use abstract EventTicketTypeRequest with final store/update leaves, matching existing MembershipTypeRequest pattern; add explicit update endpoint regression and keep PAO_DISABLE=1 on further test commands so fatal errors remain visible. Cost if wrong: Adjust both leaf request classes and their tests.

9. gateway_fee may be null in provider evidence. Preserve verified captured principal in attempt paid_review/gateway_fee_pending with no QR/payable/guessed zero ledger fee; post original-reference ledger once after exact provider fee arrives. Cost if wrong: finance UI must distinguish captured-awaiting-bookkeeping from payable and expose original-account reconcile. Cost if wrong: Add financial reconciliation evidence before any payable posting.

10. online confirmation ignores proof expiry only for an already initiated quote but still checks current contact/access/grant versions. Fresh paid-review reassessment uses resolveExisting personal eligibility even after rollout off; no new quote can use that recovery method. Cost if wrong: Revisit the documented captured-quote expiry exception without weakening identity revocation.

11. DB uniqueness protects unresolved initialization states preparing/open/unknown only; authenticated extra captures must be durably bookable. Added is_extra_capture/paid_extra, reserved for refund, never overwritten into the current ticket or issued a second QR. Refunding an extra capture must not cancel a valid current ticket. No new initialization is allowed while any captured/unresolved money remains; actual old capture is an operational exception, not permission to charge twice. Need test/refund extra path end-to-end and show held capture count in private checkout. Cost if wrong: Reconcile every real extra capture without double issuance or cancelling the valid ticket.

12. retain an unbound original-account amount/currency mismatch as unknown plus whitelisted hidden observed_payment; do not book guessed expected amounts or automatically attribute a different account/reference. Operator reconciliation exposes evidence, and source stays blocked from new charge. Final broad Task4 contract will re-run serially after commit; no other DB suite may overlap it. Cost if wrong: Operator evidence remains necessary to attribute mismatched money safely.

13. shared lifecycle release/promote helpers preserve legacy approval/waitlist actions and allow new bound-ticket refunds to release queue capacity without running a legacy refund. Failed waitlist member proof becomes pending_member_verification with server-only release_reason on existing hidden proof, never a transferable or global email grant. Recipient fresh proof can reprice/release that prior approval/promotion; pending staff approval and offline review remain separate. No new status field or public member ID introduced. Currency freezes once any benefit-bound registration exists; DB member-price ranges and request validation prevent retained member price exceeding a newly lowered standard price. This is conservative for cancelled historical registrations; separate event needed for another currency. Cost if wrong: A new currency policy would require migration/explicit owner agreement.

14. add member_benefit_access_version default1 (hidden), increment on a bound-recipient correction, bind only new member checkout/registered grants and mail verification/payment links to HMAC(registration,email,version). Exact independently verified current email still authorises; old unrestricted grants/links retain their existing contract. Mail constructs freeze recipient token when queued, not when rendered after a correction. This prevents restoring an old email from reviving an earlier grant. Pending focused recipient/lifecycle/checkout/legacy-verification gate /tmp/m6-task5-recipient-green.log session58204; await exit before another DB run. Comments relocated after Pint's class-element ordering; no functional edits to legacy QR signing. Cost if wrong: Rework revocation tokens/mail snapshots consistently; old unrestricted links remain separate.

15. preserve independent ticket-type capacity, including unlimited types; do not impose Event.capacity across typed tickets, matching existing EventTicketTypeTest. Correct initial overly strict lifecycle/fallback check and reject known full capacity before initialization. Watched payment RED1fail/1pass /tmp/m6-capacity-contract-red.log. Cost if wrong: any future shared room-capacity policy needs its own explicit feature. Cost if wrong: A new shared room-capacity policy needs explicit design.

16. terminal original-provider failure closes only uncaptured sources, retaining original quote expiry/history and requiring a fresh deliberate quote; never downgrade captured or authenticated mismatched-success evidence. Unknown Stripe captured sessions are located by metadata, authorised via original account API and full expected metadata, and bound atomically after exact money assertion. Foreign non-UUID metadata is ignored. Cost if wrong: Provider recovery must remain reference/account-bound; uncertain sources cannot be charged again.

17. Stripe refund remains pending after principal/application-fee return until same-currency provider balance fee evidence exists; no guessed zero fee credit. Existing Giving transport response is extended with an evidence flag, its existing behaviour unchanged. Holdback already debits organizer_payable in LedgerService, so canonical balance retains it without subtracting it twice. Cost if wrong: Refund reconciliation must obtain actual fees instead of inventing credits.

18. normalise charged_amount and platform_fee_amount to the accepted current breakdown before freezing the cart fingerprint; effective legacy null charged amount and stale fee splits must not cause a valid captured payment to be incorrectly held. Initial null-fee test violated nonnull schema, corrected to stale0 and watched real RED; final quote gate passed. Staff recipient recovery link exists in Guests, not Overview; regression corrected to actual section URL,1/1,19assertions passed. Cost if wrong: Rebuild quote fingerprints consistently with accepted authoritative prices.

19. archived benefit receipts retain exact-owner access via explicit archived Event lookup; callback still verifies original account and reference, no new issuance. Original-source refunds on archived events do not promote waitlists. Other unrestricted relation/public visibility untouched. Cost if wrong: Revisit archived ownership lookup without publishing archived events.

20. Task6 includes the final whole-branch review, so commit its verified UI/build first and perform that one review before its final completion line; no per-task reviewer or second blanket post-review. Remote fullCI remains gated on approved upload per plan; status remains locally implemented awaiting review/CI, not deployed. Cost if wrong: Obtain approved remote CI before marking built; do not imply deployment.

21. standard-price recipients do not acquire member proofs/discount/transfer restrictions merely because inventory is shared. Their existing provider/commission/refund flows remain native; captured no-place money is held in pending_benefit_review, its true platform credit is reserved through ledger-entry classification (own merchant gross is excluded). Normal old captures retain behaviour outside shared inventory; a general unrestricted checkout redesign stays outside scope. Cost if wrong: legacy pre-capture duplicate checkout handling remains an existing separate hardening task, not a new member quote fabricated for nonmembers. Cost if wrong: Legacy pre-capture duplicate checkout remains a separate hardening scope.

22. explicit accepted repricing reuses the owned, session-blocked reverify endpoint with a typed FormRequest; positive offline totals must be accepted before submitting new proof, zero totals issue only under fresh identity+event/capacity checks. Existing unresolved offline proof is retained for staff review rather than discarded or treated as an online refund. Cost if wrong: New offline evidence cannot be discarded to simplify repricing.

23. review is complete, all7Important enter this singlefixpass; noMinor. Hosted sandbox/actualzoom/physicaldevice remain recorded manual limits; family/representative discounts/phone-only registration/transfers are explicitly deferred; unrestricted legacy changes are limited to shared inventory/payment-hold guarantees, not a broad redesign. Cost if wrong: Manual provider/device acceptance and deliberately deferred features stay visible.

24. preserve this existing unrestricted admin workflow. Globally prohibit cancelled/archived queue promotion; member-refund-triggered promotion additionally requires published, and every new member-bound release still requires published. This fixes reviewer7 without silently removing legacy draft administration. Cost if wrong: a future global draft-admission policy requires an explicit change, not an incidental member-benefit rollout. Add draft+cancelled refund regression; rerun all affected approval/lifecycle/recovery tests instead of another nine-minute blanket local repeat; full remote CI remains required after approved push. Never label broad local run321/321. Cost if wrong: A future global draft-admission policy needs an explicit compatibility change.

25. Completed member transitions use a normal browser navigation to the central ticket workspace; outcome data is forwarded from verification. Cost if wrong: rework the cross-domain transition contract rather than retrying an Inertia background request that cannot follow it.

### Deferred minors

None reported by the independent review. Manual acceptance limits and approved feature deferrals are recorded above, not treated as passed checks.

Local status: implementation and one whole-branch review completed; commit retained on `codex/community-6`. Required remote CI, upload approval, merge approval and deployment remain pending.


## Dues start-date follow-up — Self-Review Report, 2026-10-18

Separate owner-approved safeguard after M6; implemented on local `codex/community-dues-start-date`, stacked on reviewed M6 `2bcad057`. No M6 remote integration or M7 implementation is inferred.

Is there anything I have overlooked? Is the solution optimal? Let me take a step back.

### Touchpoints updated

- AGENTS.md/memory: existing finance rules retained; the parent spec §7 and roadmap are the shared decision record. No new global convention/private memory needed.
- Seeders/permissions: no new permission or role; existing manage-members and tenant checks remain. A synthetic QA staff grant was scoped to its disposable tenant for browser testing only, never production.
- Navigation: existing Money → Dues → Edit membership type; introducing dues reveals a required date with first-full-period explanation; persisted paid boundary is visible and disabled on subsequent ordinary edits.
- Config/env/dependencies: none added. Two nullable columns on landlord membership_types/members preserve legacy rows without inventing a historical boundary; no data backfill or ledger change.
- Tests: watched10 new cases fail before implementation; later watched3 review cases fail before their fixes. Final six-file affected PHP gate105/105,386 assertions,70.83seconds; focused frontend17/17; Pint, ESLint, build and PHPStan0 passed. Baseline unchanged. Complete project CI has not run for this follow-up; no full-suite pass is claimed.

### UI Gap Analysis

Discoverability/empty/loading/error/permission states: reused existing native membership-type page/form, including field validation and disabled submitting button. No new page or duplicate navigation. Required date has an associated native label, error association and explanatory hint. Local browser: successful no-dues→GHS20 monthly conversion saved2026-11-01; fresh page shows fixed date2026-11-01. At390×844 the dialog is358px wide atx16 and610px high, within viewport. No claim of actual200% zoom or physical-device acceptance.

One browser reopen ran ahead of the existing asynchronous list refresh and inspected the old no-dues row; the test waited for a date field absent in that stale edit snapshot. A fresh page load verified persisted server state and correct controls. No server save failure or missing stored boundary. This pre-existing reload timing is not represented as a successful immediate-reopen test.

### Solved (auto-fixed)

- Scheduler and manual creation share Member::firstDuesPeriod: max(type,member) local-calendar floor rounded up to the first full billing anchor. Current-period-on-join never overrides it. Manual caller dates are normalised as tenant calendar dates, preventing UTC/New York midnight mismatch.
- No-dues→paid type update requires explicit nonhistorical date in the staff endpoint, rechecked under the type lock. Missing/date-format/impossible/past/reset attempts fail. Existing paid schedules cannot silently clear/change the boundary. Direct trusted conversion has conservative local-date default, preserving an already-selected future date.
- No-dues member→paid type switch gets an internal current-local-date boundary; paid→paid retains legitimate catch-up. Existing member identity/active dates, pay links and history remain.
- Existing paid/open/waived/void records stay byte-for-byte unchanged; querying an existing pre-boundary charge returns it idempotently. New manual pre-boundary charge rejected. Stale member/type relations are refreshed under member-then-type locks. Locked type update rechecks period/currency history restrictions.
- One focused independent review of this safeguard only found two Important edge cases (calendar mismatch and unchanged future date), plus paid→paid compatibility concern and one redundant-loop calculation. All fixed; no second independent review and no deferred reviewer findings. Important fixes have watched RED→GREEN regression coverage.
- Rebuilt tracked public/build. No production schema, data, settings, messages, payments, remote push, merge or deployment changed. Original and Claude checkouts remain clean and untouched.

### Recommended for user decision

Review the separate milestone7 plan's audience/representative/poll-identity/file-format proposals before authorising M7 implementation. Approve remote upload/full CI and integration separately when ready; this branch includes unmerged M6 ancestry. No production migration run is implied by the successful disposable QA migration.

### Not gaps

An unset dues amount means no dues; a member zero override still records an explicit waiver. Daily/weekly/monthly/quarterly/yearly anchors are retained, and the organisation-local date governs the new floor. The floor is an eligibility date, not a new debt or ledger balance. Real earlier dues from paid membership are preserved; no automatic waiver/void/import rewrite is performed.

### Rulings I made

1. Reuse the owned isolated checkout on a new branch stacked on M6, retaining the reviewed M6 branch and all Claude checkouts. Cost if wrong: split/cherry-pick the small safeguard for an independent PR before remote integration.
2. First full billing period begins on/after the selected local date, with no proration or implicit retrospective billing. Cost if wrong: a separately approved partial-period policy and tests are needed.
3. Keep old columns null on migration and protect future no-dues transitions, including member moves; do not infer or rewrite historical liability. Cost if wrong: production inventory and an owner-approved reconciliation would be required, never an automatic backfill.
4. Preserve paid→paid catch-up and existing charges; the new member floor applies only to no-dues→paid moves. Cost if wrong: a general dues-rate effective-dating redesign is a separate requirement.
5. Default trusted programmatic no-dues conversion to local today while respecting an unchanged future boundary; staff UI/API still requires an explicit valid date. Cost if wrong: strengthen trusted-service input contracts without weakening the staff endpoint.
6. A narrow review and affected tests verify this fix; full remote CI remains pending rather than repeating an expensive full local milestone suite. Cost if wrong: any integration failure must be resolved before merge; no full-suite claim here.
