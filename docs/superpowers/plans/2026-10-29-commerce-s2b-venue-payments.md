# S2b Venue Payments and Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task using the native approach. Steps use checkbox (`- [ ]`) syntax for tracking. One author self-review and one fresh whole-change review before release; no per-task review loop.

**Goal:** convert an approved venue request into an explicitly accepted financial contract with verifiable rental/security payments, protected funds and finance-led exception handling, while preserving existing calendar and merchandise workflows.

**Architecture:** existing VenueBooking remains authoritative. Immutable VenueFinancialQuote and VenueBookingCharge records connect typed reservation orders to common attempts, observations, receipts and refunds. Extract typed validation/locking/fulfilment dispatch from purchase-specific services; financial truth still comes only from verified provider evidence and LedgerService.

**Tech Stack:** existing Laravel/PHP/PostgreSQL, Inertia/React, Pest/Vitest; existing Paystack gateway, ledger and queue. No new dependency.

**Spec:** `docs/superpowers/specs/2026-10-29-commerce-s2b-venue-payments-design.md` (all sections); current marketplace roadmap; AGENTS.md finance/calendar invariants.

**Status:** proposed 29 October 2026, **not approved for implementation**. The owner must review the spec's commercial recommendations before work starts. Checkpoints A/B/C below are implementation boundaries, not permission to enable an incomplete money flow.

## Global constraints

- Keep listing/booking/order IDs, public URLs, old category statements, staff rooms, event operations and historical quotes/payments intact. Do not bulk-convert legacy bookings or invent merchandise variants/Event records.
- Preserve Purpledot's live merchandise, its approved 3% agreement, supported refunds/collection/delivery and manual payouts. New venue flags default off and are independent of `commerce_checkout`.
- GHS integer minor units only; FeeCalculator/FeeTerms for commission; explicit zero waiver and caps retained; only application superadmin changes commercial rates/caps/bearer. Never adopt legacy MarketplaceFees' default 10% for new venue commerce.
- LedgerService is the only financial writer. Payment/refund/transfer references and original provider account are immutable; unknown outcomes are verified, never blindly retried. Payout OTP remains on.
- Network calls occur outside database transactions. Lock order is tenant → listing → booking → quote/charge → order → attempt/observation/refund. Purchase remains tenant → order → variants. Calendar-only S2a operations never acquire a tenant lock after listing.
- Full capture per charge, at most initial rental + balance + security. Full-capture refunds only initially; no damage deductions/partial security retention. No invoice/bank-transfer evidence workflow until S2c, no own-gateway migration, no automatic payout.
- Tick steps in the same commits, run proportional affected tests and `pint --dirty`, commit rebuilt `public/build` for JS/CSS. One final CI gate, no repeated full-suite loops for unchanged code.
- Local QA is authorised. No production merchant agreement, flag, capture, refund, payout, credential or provider-setting change from implementation approval alone.

## Review Focus

- Initial deposit captured before a hold expires but observed afterwards: confirm only if terms/time/account match and the slot is still free; a conflicting winner is never displaced.
- Legacy references already posted, imported or held as exceptions: no second credit, category rewrite, fabricated acceptance or automatic settlement after a replay.
- Booking deposit versus security: no commission on refundable principal, no security-funded seller/merchandise payout, no inventory/restock side effect on security return.
- Deposit/balance rounding, cap, tenant-rate change and cancellation: allocated fees sum exactly to the accepted contract fee; only captured/refunded allocations affect the ledger.
- Unknown provider initialisation/refund/transfer, wrong tenant/email/source and state drift: one operation, durable evidence, guarded continuation and no misleading paid/refunded/confirmed UI.

## File / API map

**New models/factories and landlord migrations:** `VenueFinancialQuote`, `VenueFinancialDecision`, `VenueBookingCharge`, `VenuePaymentResolution`, `CommercePaymentReference`, `VenueSettlementReserve` under `app/Models`, with factories under `database/factories`. Generate migrations using Artisan at execution time, preserving existing attributes/constraints and refusing destructive rollback once records exist.

**New services under `app/Services/Marketplace`:** `VenueCommercialQuotes`, `VenueCharges`, `VenuePaymentReconciliation`, `VenueSecurityDeposits`, `VenuePaymentRollout`, `CommerceOfferingRegistry`, `PurchaseOffering`, `VenueOffering`, `CommerceOrderLocks`, `VenueSettlementRelease`. Reuse `app/Services/Venue/VenueCalendarLocks.php`, not a second calendar.

**New contract:** `app/Contracts/CommerceOffering.php`:
- `assertCheckout(StoreOrder $order, Session $session, ?string $acceptedHash): void`.
- `captureIssues(StoreOrder $order, StorePaymentObservation $capture): array` (list of stable review-reason strings).
- `applyCapture(StoreOrder $order, StorePaymentObservation $capture): void` (after ledger proof, same transaction).
- `assertRefund(StoreRefund $refund): void`; `applyRefund(StoreRefund $refund): void`.
- `receiptContext(StoreOrder $order): array` (typed, public-safe immutable receipt fields).

**Lookup/locks:** `CommerceOfferingRegistry::for(StoreOrder): CommerceOffering`; `CommerceOrderLocks::order(StoreOrder): StoreOrder`. Missing/foreign/type-inconsistent sources fail closed; no public registry mutation.

**Quote/charge API:**
- `VenueCommercialQuotes::issue(VenueBooking, User, array $validated, string $key): VenueFinancialQuote`.
- `VenueCommercialQuotes::withdraw(VenueFinancialQuote, User, string $reason, string $key): VenueFinancialDecision`.
- `VenueCommercialQuotes::accept(VenueFinancialQuote, Session, string $hash, string $key): VenueFinancialDecision`.
- `VenueCharges::order(VenueFinancialQuote, string $purpose): StoreOrder` — idempotent server-generated amount only; purpose is initial/balance/security, not a buyer-provided allocation.
- `VenuePaymentReconciliation::verify(VenueBookingAction, User, string $key): VenuePaymentResolution`.
- `VenuePaymentReconciliation::resolve(VenuePaymentResolution, User, string $decision, string $reason, string $key): VenuePaymentResolution` — retain/refund/confirm with strict source-specific preconditions; cannot directly toggle paid.
- `VenueSecurityDeposits::returnFull(StoreOrder, User, string $reason, string $key): StoreRefund`.
- `VenueSettlementRelease::complete(VenueBooking, User, string $reason, string $key): VenueSettlementReserve`.

**Existing shared services touched:** StoreCommercialTerms and StoreCommercialAgreement/its Form Request, StorePayments, StorePaymentEvidence, StorePaymentConfirmer, StorePaymentNotifications, StoreRefunds, StoreOrderAccess/Payload, StoreReceipts, StoreBalance, StorePayouts, CommerceInventoryLocks, LedgerService and appropriate ledger-account constants. Existing purchase bodies move behind PurchaseOffering without weakening validation. All existing call sites of the old lock helper must be audited.

**HTTP/UI:** new tenant `VenueFinancialQuoteController` and `VenueFinanceController`, public `VenueChargeController`, dedicated Form Requests following their namespaces; existing public VenueBookingController/dossier and settlement webhook; tenant Inquiries/Calendar and new Venue/Finance page; common Orders/receipt screens. Existing verification return helper remains strict. Add `EnableVenuePaymentsCommand` in the existing commands directory, PermissionsSeeder and `app/Libraries/RolePermissions.php`. Do not give bank-transfer upload or legacy payable RFQ endpoints new capabilities.

## Checkpoint A — contracts, typed foundation and payable quote

### Task 1 — Immutable contracts, charge sources and reference ownership

- [x] Write failing schema/factory tests in `tests/Feature/Marketplace/VenueFinancialQuoteSchemaTest.php`: quote versions, immutable dates/terms/fees, separate decision records, one accepted contract per booking, unique `(quote_id,purpose)` charge, composite tenant/shop/booking/order ownership, optional venue variant only for `reservation`, no fake stock. Test old purchase/made-to-order rows and rollback refusal.
- [x] Confirm failure, then add the six models/migrations. Quote fields include rental/extra/security totals, deposit/balance amounts and deadlines, once-per-contract fee allocation, source agreement/account fingerprint, cancellation/support snapshot, hold/deadline/version and operation hash. Charge/source ownership is enforced at DB level; business decisions/resolutions are append-only.
- [x] Add a `CommercePaymentReference` registry keyed uniquely by provider/account/reference. Record source/booking/order and ledger linkage without secrets; it is not a ledger. Test two workers cannot claim the same original reference for different bookings/orders and existing historical postings cannot be claimed as a new sale. Unknown provenance remains review, not an invented backfill. Add `offering_scope` to existing commercial agreements with default purchase; existing per-shop version uniqueness remains. Test purchase-default and reservation-explicit resolve/approve paths, including an independently amended venue agreement leaving the active purchase agreement unchanged.
- [x] Run schema tests plus existing StoreModelAndMigration/StoreSellerQuotes/StaffRoomBookingSchema tests; Pint; commit and tick steps.

**Task 1 implementation notes (30 October 2026, PR A1):**

- Owner approved on 2026-10-30: 3% default venue commission, adjustable per tenant via a reservation-scoped agreement; existing accepted agreements are preserved untouched. The six proposed safeguards are approved.
- Migrations `2026_10_30_000100_add_reservation_scope_to_commerce` and `2026_10_30_000200_create_venue_financial_contract_tables`. A reservation order must rest on a `reservation`-scope agreement and a purchase order on a `purchase` one (composite foreign key on `offering_type`/`offering_scope`).
- Reservation order lines name the booked venue (`booking_id`), carry no variant and no stock, and a trigger requires the line's order type, listing and shop to agree. Purchase line rules are unchanged.
- Quotes, decisions, charges and resolutions are append-only by trigger; the reserve can only move from held to released once, by a completion decision. The charge trigger requires the order and amounts to equal what the quote names. Quote insertion requires the cited approval hold and the approved dates.
- Departures from the plan: the reference registry service (`CommercePaymentReferences`) and scope-aware `StoreCommercialTerms::resolve/approve` landed in this PR rather than Task 3, because the Task 1 tests require them; `StoreBalance::holdback` and the admin agreement list now read purchase agreements only, so a venue agreement can never alter merchandise payout holdback. Two-worker reference claims rely on the database unique key; no separate real-worker race file was added for this PR.
- Production check (read-only counts, 2026-10-29): zero venue bookings, zero approval-flow rows, zero conflict evidence rows and no organisation with requests enabled.

### Task 2 — Typed reuse and a single money/calendar lock order

- [x] Write failing `tests/Feature/Marketplace/CommerceOfferingDispatchTest.php` for purchase, production quote, reservation and unsupported/forged type. Add real-worker lock tests in `tests/Infrastructure/Marketplace/VenueCommerceConcurrencyTest.php`: tenant payout/refund vs venue confirmation; S2a approval/release/blackout vs money confirmation; same/different booking/tenant controls. No thread can acquire tenant after holding listing.
- [x] Implement the named registry/contract/lock entry point. PurchaseOffering delegates existing stock/production/collection checks without changing them. VenueOffering validates quote/charge/booking ownership, verified buyer, financial acceptance, calendar and type eligibility; never synthesises a stock allocation or collection arrangement. Read immutable source IDs before locking; revalidate all associations under lock.
- [x] Switch all shared money entry points (including evidence recording, callbacks, reconciliation, refunds, balance/reserves and payout funding) to typed locks and eligibility. Inventory-only methods must reject reservation orders. Financial callbacks do not depend on a still-enabled rollout flag to record money already taken.
- [x] Run dispatch tests, targeted merchandise checkout/payment/refund/receipt/collection suites and focused real-worker races against an isolated named DB. Commit and tick; do not activate new payment routes yet.

**Task 2 implementation notes (30 October 2026, PR A2, stacked on A1):**

- `CommerceOffering` has more hooks than the plan listed (`assertNewAttempt`, `settleWithoutPayment`, `onCaptureReview`, `expectedMetadata`) because `StorePayments::start` validates in two places on either side of the idempotent attempt returns; moving everything into one call would have changed merchandise replay behaviour. `PurchaseOffering` holds the extracted purchase logic verbatim; the full Marketplace suite (639 tests) passes unchanged.
- `CommerceOrderLocks` is the single entry point for shared money paths (payments, confirmer, evidence, refunds, receipts, notifications, reconciliation). `CommerceInventoryLocks::order` now refuses a reservation order, so purchase-only code (collections, fulfilment, deliveries, fulfilment options, quotes, inventory) fails closed on one.
- `VenueOffering` validates checkout (accepted contract hash, verified buyer, rollout, open hold or confirmed booking), new attempts (dates still free), capture issues, metadata and receipts. Confirming a booking from a capture, refunds and payouts stay closed (`LogicException` / validation error) until the Checkpoint B tasks; nothing can create a venue attempt yet.
- `VenuePaymentRollout` (`marketplace_venue_payments`, plus requests) defaults off; the enabling command is Task 7. The flag gates new checkouts only.
- Purchase-only surfaces now exclude reservation orders: the buyer cancel endpoint and the expired-hold sweep filter on `offering_type = purchase`. The buyer order list and `StoreOrderPayload` still assume goods; Task 7 adapts them before any reservation order can exist.
- Real-worker lock-order tests (`VenueCommerceConcurrencyTest`, five cases) prove no deadlock and correct serialisation among venue money, calendar writers, tenant-wide work and purchase money; a mutation check (removing the listing lock) fails two of them. Money-confirmation-versus-approval outcome races arrive with the capture slice (Task 4), when a confirmation exists to race.
### Task 3 — Final quote, acceptance and explicit payment schedule

- [ ] Write failing `tests/Feature/Marketplace/VenueFinancialQuotesTest.php`: priced and price-on-request requests; host ownership; full payment; deposit/balance/security split; all-zero quote; bounds/deadlines; quote withdrawal/replacement; policy/rate changes; expired hold; duplicate/stale keys; foreign buyer and forged client amounts. Include rounding/cap cases across both rental instalments.
- [ ] Implement quote/decision/charge interfaces. Require `manage venue`, a valid host-approved hold, explicit actual cancellation/support terms and a supported reservation-scoped approved commercial agreement. Extend `StoreCommercialTerms::resolve(Tenant $seller, string $scope = 'purchase'): StoreCommercialAgreement` and `approve(Tenant $seller, User $actor, array $input, string $scope = 'purchase'): StoreCommercialAgreement`; validate scope and retain superadmin gates. Purchase callers retain their default and original snapshots. Snapshot the financial terms; explicit acceptance hash and verified matching email are mandatory. Record every transition idempotently under typed locks.
- [ ] Calculate total rental commission once via FeeCalculator. Store initial allocation and exact remainder for balance; cap once; security commission zero by source policy. No inline percentage math or staff-supplied fee fields. Initial quote expires at min(hold deadline,event start); balance/security have their own agreed pre-event deadlines. Rate changes never reprice an accepted contract.
- [ ] Create at most one order per due purpose; each attempt pays that full charge. A security payment never confirms the booking; zero-priced acceptance confirms only after a free-slot recheck, with no provider attempt/cash ledger. A missed balance is overdue, not automatic cancellation. New quotes replace indicative prices rather than mutating S2a request snapshots.
- [ ] Run quote/charge tests and existing approval/access tests. Update schema immutability tests for every public-safe snapshot field; Pint; commit and tick.

## Checkpoint B — financial verification, exceptions and protected funds

### Task 4 — Verified capture, atomic reservation and recovery

- [ ] Write failing `tests/Feature/Marketplace/VenueCommercePaymentsTest.php`: no accepted quote, wrong identity/account/source/amount/currency/reference, string metadata, duplicate/out-of-order callback/webhook, unknown initialisation, extra capture, ledger-post failure, timely capture delivered late, exact expiry, balance/security after initial hold expiry, and source revoked after charge. Use original-account fake responses, never real keys.
- [ ] Extend existing common metadata/verification with source-specific expected chronology/expiry, not a second verifier. New venue attempts retain common store-source metadata with validated DB charge links; routing never trusts a browser-supplied source. Add server-side start/continue/reconcile endpoints using existing payment-attempt machinery; an ambiguous attempt cannot be replaced until conclusively failed.
- [ ] Post valid rental money through LedgerService, then update charge/booking confirmation and decision evidence in the same transaction. Delayed timely initial money may confirm only while the slot is still available; hold expiry alone cannot make arrival time override provider paid_at. Invalid/conflicting/late money gets durable reserved review liability and no occupancy. A ledger failure leaves durable capture evidence but no false paid/confirmed state.
- [ ] Ensure S2a unpaid release/cancel cannot release paid bookings; retain calendar rules during refund/review. Full confirmation is not inferred from security or an unfinished balance. Add reference claims to both new and legacy posting boundaries without changing historical commission/category. Never use an unverified legacy callback to invoke the new confirmer.
- [ ] Run payment, calendar and merchandise compatibility tests. Prove callback/webhook race posts once and reserves once with independent workers. Commit and tick.

### Task 5 — Legacy reconciliation without duplicate money or consent

- [ ] Write failing `tests/Feature/Marketplace/VenueLegacyPaymentResolutionTest.php`: already-posted marketplace reference, unposted S2a conflict, repeated verification/resolution, cross-tenant/ref, changed account, unverifiable amount/terms, cancelled winner, closed booking and paid-again capture. Existing conflicts remain unresolved on callback replay even if space frees.
- [ ] Implement finance-only verify/resolve API and reference registry joins. Verify using the approved original provider account. Link existing legacy MKT ledger history; do not repost, change category, rewrite rates or include it in commerce payable. Require a category-aware settlement/refund adapter and tests before allowing money operations on already-posted legacy funds; otherwise mark explicitly blocked for reconciliation, not resolved.
- [ ] For verified unposted money, import a typed `legacy_review` charge/attempt/observation once. Preserve original observation chronology and an explicit import origin; no forged accepted contract or backdated new record. Unknown commercial provenance remains refund-only/review. Adapt evidence checks narrowly for imported history, never relax every payment's metadata/time validation.
- [ ] Implement append-only retain/refund/confirm decisions with original evidence links and reason. Refund uses verified capture/funding; confirmation requires provable terms, explicit buyer agreement, valid policy and fresh locked availability. An audit click cannot settle or refund by itself. Failed/unknown network operations remain reconcilable under the same reference.
- [ ] Run legacy payment, S2a writer/conflict, registry and privacy tests; commit and tick. Record legacy categories that cannot yet be safely transacted as activation blockers for those records, not permission to invent money.

### Task 6 — Security liability, refunds and manual payout eligibility

- [ ] Write failing `tests/Feature/Marketplace/VenueSecurityAndSettlementTest.php` and real-worker refund/payout cases: security-only capture/return, tenant gateway-fee funding deficit, rental cancellation with multiple paid charges, in-flight refund/payout, duplicate refund completion, OTP parking/release, completed period, unresolved dispute and another merchandise order sharing the tenant's commerce cash.
- [ ] Add LedgerService-only security capture/return methods and typed refund effects. Security principal credits a refundable liability; fees debit the tenant position. Rental commission reversals use the exact stored captured allocation. Full security return does not cancel rental occupancy or create inventory movements. Provider completion, not submission, finalises refunded state.
- [ ] Implement VenueSettlementReserve and release-after-event-completion checks. Update StoreBalance available/cashBuffer/refundBacked, scoped current-agreement holdback reads and StorePayouts claim/finalise/reconcile: security and unresolved review cash cannot fund venue or merchandise payouts; reserved venue earnings cannot be paid twice; backed refunds may use their own reserves. No duplicate reserve journal that double-counts an existing payable/liability.
- [ ] Keep manual payouts and per-transfer OTP. Tenant bears transfer fees and actual gateway costs; recognise transfer cost only on release. Pending/unknown transfer cannot be resent. Add one end-to-end integer ledger assertion per path, including liability/payable/clearing balances returning to expected values.
- [ ] Run affected finance/payout/refund/merchandise tests and dedicated worker races separately from DB-truncating suites. Commit and tick. Partial damage retention/security appropriation remains unavailable and clearly held for later policy.

## Checkpoint C — permissions, UI, acceptance and review

### Task 7 — Discoverable, private and truthful payment journeys

- [ ] Write failing HTTP/JS tests for host final quote, booker verification return/acceptance, initial/balance/security cards, due/overdue/expired/review/refund states, charge receipts/aggregate statement, legacy exceptions and gated finance controls. Assert seller-borne commission is not in public payloads and historical receipt snapshots are unchanged.
- [ ] Add controllers/Form Requests and routes from the file map, reusing Inquiries/booking dossier. Add tenant Venue/Finance navigation, private quote/statement links and secure POST actions. No paid-flag toggle, browser fee override, unknown retry button or public raw evidence. Accessible loading/errors/focus and exact timezone/deadline text are required; refresh server state on focus and deadline.
- [ ] Add the three venue-money permissions to PermissionsSeeder and RolePermissions together (Superadmin/Org Superadmin defaults only). Use server gates plus owning tenant and action-specific permission; `manage venue` alone cannot refund, reconcile or pay out. Existing store-finance permissions do not silently expand to venues. Add command `venue:enable-payments {tenant} {--off}` with explicit default-off feature registration and original request/merchant/account prerequisites.
- [ ] Adapt common order/receipt screens through type-safe payloads; no collection/stock labels or passes for venue charges. Email receipts remain durable/idempotent for each real capture; statement totals distinguish received/refunded/security-held money. Existing event/operations links survive.
- [ ] Browser-check local-only fixtures at desktop/390px, dark mode and keyboard: priced/price-on-request, full/deposit/balance/security, wrong email, stale expiry, changed policy, mail failure and staff exception. No production migrations/fixtures or shared browsing DB reset. Run affected Vitest/HTTP tests, lint/Pint and rebuilt assets; commit with ticked steps.

### Task 8 — Financial acceptance, one independent review and release gates

- [ ] Run final affected tests once: quotes/sources/fees/holds, shared purchases and production quotes, all capture/refund/security/payout paths, legacy ledger links, staff-room/event access, permissions and reference ownership. Correct failures with targeted rechecks; no repeated full local suite for unchanged code.
- [ ] Run the named isolated real-worker races independently: approval/release/blackout vs initial capture, late capture vs new occupancy, balance/refund/cancellation, security return vs seller payout, duplicate callback/webhook and duplicate legacy import/resolution. Assert ledger and financial/reservation outcomes, not only response codes.
- [ ] Complete an author self-review and one fresh whole-change review covering every lock and money/UI touchpoint. Record actual dependencies, all permissions/role propagation, empty/loading/error states and unimplemented decisions honestly. Preserve AGENTS.md finance rules; add only genuine new invariants.
- [ ] Prepare a draft PR with rebuilt assets and one final CI gate. Merge/deploy only on separate approval, flags off. Sandbox acceptance requires separately approved isolated scope/recipients/accounts: rental deposit + balance + security return, rejected/unknown/late money, refund funding and OTP. Provider outage simulations/fakes and actual provider evidence must be labelled separately.
- [ ] Update the roadmap with exact implementation/release/acceptance state. No production venue activation until owner approves the merchant, cancellation/security/payout policy, financial agreement and completed acceptance. S2c bank-transfer/pro-forma/manual evidence follows separately; no supplier/venue promotion inferred from Purpledot merchandise being live.

## Plan self-review / owner gate

The plan covers the design's quote/fee/schedule, locking, reference provenance, legacy money, security cash protection, UI and release boundaries. Corrected three tempting shortcuts: reusing purchase payment services without removing stock requirements, treating security as zero-commission seller revenue, and letting a new venue agreement override the current merchandise agreement. Cross-category legacy money remains an explicit blocked case unless its adapter is proven, not a bookkeeping rename. Rounding and cash-liability tests are assigned to Tasks 3/6, privacy and stale UI to Task 7, and concurrent outcome proofs to Tasks 2/4/8.

Before execution, the owner must approve/amend the design's deposit/due-date, security, refund, commission-basis and payout-release recommendations. Native implementation with one final independent review is recommended. This document does not approve implementation or financial operations.
