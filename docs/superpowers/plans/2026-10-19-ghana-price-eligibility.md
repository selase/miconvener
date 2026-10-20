# Ghana Price Eligibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Tick each step in the same commit as the work.

**Goal:** Only organisations that are really in Ghana pay the Ghana (GHS) price; everyone else pays the fixed USD price converted at the Bank of Ghana rate; nobody can get the Ghana price by choosing it.

**Architecture:** Display follows the visitor's country and, once signed in, the organisation's recorded `pricing_region`. At checkout a Ghana price is charged only to an organisation that is Ghana-eligible (verified Ghana payout account, a past Ghana mobile-money or Ghana-card payment, or a superadmin grant). An organisation not yet eligible may pay the Ghana price only by Ghana mobile money or a Ghana-issued card, and the verified payment proves it. A foreign card on a Ghana price is topped up to the USD equivalent on the same card.

**Tech Stack:** Laravel 13, PHP 8.5, Pest, PostgreSQL (landlord), Paystack, Inertia React console, Blade admin.

**Spec:** `docs/superpowers/specs/2026-10-18-ghana-price-eligibility-design.md` (owner decisions in section 7). Builds on regional pricing (PR #38): `PriceBook`, `ChargeQuote`, `ExchangeRates`, `VisitorCurrency`.

## Global Constraints

- **Must ship before any Ghana price is set below the USD equivalent.** Until then no behaviour changes in what customers pay.
- Currency is settled at onboarding: the GHS option leaves the switch for everyone; a USD organisation keeps USD prices even when its people are in Ghana; a Ghana-eligible organisation keeps the Ghana price wherever its people are.
- Eligibility evidence is exactly: a verified Ghana payout account (bank or mobile money), a successful Ghana mobile-money payment or Ghana-issued card payment (`channel = mobile_money`, or `authorization.country_code = GH`), or a superadmin grant. Stripe is not evidence until Stripe Connect is finalised.
- Foreign card on a Ghana price: charge the difference to the same card (`charge_authorization`); if that fails, the plan runs for the period already paid, the organisation moves to USD from the next period, and a superadmin is alerted.
- Paystack is always initialised in GHS. Commission percentages and event/dues/giving payments are untouched.
- Every grant, removal and automatic change of eligibility is recorded with who or what, the evidence and the date.
- PHP: strict types, final classes, explicit return types, Form Requests, PHPDoc; `pint --dirty`; phpstan 0 with no baseline additions; JS changes commit a fresh `public/build`. Tests: Pest, `Http::fake` for Paystack; `PAO_DISABLE=1` if test output is empty; separate test database while another agent runs tests.

## Review Focus

1. A visitor abroad on any request (cookie, header, direct POST to checkout) cannot obtain a GHS price. (Tasks 2, 3)
2. A Ghana organisation paying by a foreign card on a Ghana price: the top-up, the top-up failing, and the webhook arriving before the callback. (Task 4)
3. A USD organisation whose staff are in Ghana keeps USD; a Ghana organisation whose staff travel keeps GHS. (Task 2)
4. A renewal after eligibility is removed: converted from USD, with warning first. (Task 5)
5. Backfill: an organisation with only foreign-card payments must not become eligible; one with a mobile-money payment must. (Task 6)

---

### Task 1: Eligibility record and rule

**Files:**
- Create: `database/migrations/landlord/2026_10_23_100000_add_pricing_region_to_tenants.php`, `app/Services/Pricing/GhanaEligibility.php`
- Modify: `app/Models/Tenant.php`
- Test: `tests/Feature/Pricing/GhanaEligibilityTest.php`

**Interfaces:**
- Produces: `tenants.pricing_region` nullable string (`GH` or `INTL`, null until set), `tenants.pricing_region_evidence` jsonb `{source, detail, at, by}`; `Tenant::isGhanaEligible(): bool`; `GhanaEligibility::grant(Tenant $tenant, string $source, array $detail, ?User $by = null): void`, `::revoke(Tenant, User $by, string $reason): void`, `::evaluate(Tenant): ?array` (the evidence found, or null) checking a verified `TenantPayoutAccount`, then stored `Transaction` rows (`meta.channel` mobile_money, or `meta.authorization.country_code` GH). Sources: `payout_account`, `ghana_payment`, `superadmin`.

- [x] **Step 1: Failing tests:** `a verified ghana payout account makes an organisation eligible`, `an unverified payout account does not`, `a mobile money payment makes it eligible`, `a card issued in Ghana does; a card issued elsewhere does not`, `grant and revoke are recorded with evidence and the person`.
- [x] **Step 2:** Run `php artisan test --filter=GhanaEligibilityTest`. Expected: FAIL.
- [x] **Step 3:** Implement the migration, `Tenant::isGhanaEligible()` (true when `pricing_region = GH`) and `GhanaEligibility`; `grant` writes the columns and an activity-log entry.
- [x] **Step 4:** Rerun; PASS. Commit `feat(pricing): Ghana eligibility record and rule`.
  Ruling: `grant()` returns a bool (whether it changed anything) instead of void, and an automatic grant never overrides a superadmin's removal (`source = revoked`); only a superadmin grant restores it. Payments store a normalised `meta.payment_origin = {channel, country_code}` (written in Task 4); `evaluate` also reads the older `meta.authorization`/`meta.channel` and only counts what the organisation paid the platform (`PLATFORM_PAYMENT_TYPES`), never its own ticket sales.

### Task 2: Display and the switch

**Files:**
- Modify: `app/Services/Pricing/VisitorCurrency.php`, `app/Http/Controllers/Marketing/CurrencyController.php` and its route and request, `resources/views/livewire/pricing-table.blade.php`, `resources/js/Components/Console/CurrencySwitch.jsx` and the pages using it, the registration controller that creates the tenant, `app/Http/Controllers/Billing/BillingController.php` and the add-on and token controllers
- Test: `tests/Feature/Pricing/PriceDisplayTest.php` (update), `tests/Feature/Pricing/CurrencyIsSettledAtOnboardingTest.php`

**Interfaces:**
- Produces: `VisitorCurrency::resolve(Request $request): string` ignores any cookie asking for GHS: Ghana country gives GHS, otherwise USD; signed-in console pages use `Tenant::pricing_region` (`GH` gives GHS, `INTL` gives USD) when set. The switch offers only "Show USD" to a visitor in Ghana who is not a customer, and nothing to customers. Registration records `pricing_region` as `INTL` for a USD visitor and leaves it null for a Ghana visitor until evidence exists.

- [x] **Step 1: Failing tests:** `a cookie asking for GHS from abroad still shows USD`, `a visitor in Ghana sees GHS and may choose to see USD`, `an organisation onboarded in USD sees USD even from a Ghana address`, `a Ghana eligible organisation sees GHS from any country`, `the switch is not rendered for customers`, `the console pages carry the organisation's currency`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Change `VisitorCurrency` and the three console controllers to take the tenant into account; remove the GHS choice from the switch and the controller's validation (`in:USD`); set `pricing_region` at registration.
- [x] **Step 4:** Rerun; PASS; run `npm run lint`, `npm run build` and commit `public/build`. Commit `feat(pricing): currency follows the organisation, and nobody can choose GHS`.
  Ruling: `VisitorCurrency::resolve(Request, ?Tenant)` falls back to the active tenant context when no tenant is passed, so every console caller follows the organisation without being changed. The console switch is removed entirely (component deleted); on the marketing page only a visitor in Ghana with no organisation sees a single "Show prices in USD / GHS" toggle. Asking for GHS from outside Ghana is accepted and ignored, and asking for GHS from Ghana clears the USD cookie. Registration records `pricing_region = INTL` for a visitor outside Ghana and leaves it null for a visitor in Ghana (`VisitorCurrency::forVisitor`).

### Task 3: Checkout charges the Ghana price only to eligible organisations

**Files:**
- Modify: `app/Http/Controllers/Billing/CheckoutController.php`, `app/Services/Billing/TenantAddonService.php`, `app/Http/Controllers/Tenant/LlmCheckoutController.php`, `app/Contracts/PaymentGateway.php` and `app/Services/Payment/PaystackGateway.php` (`createOneTimeCheckoutSession(..., ?array $channels = null)`), `app/Services/Pricing/PriceBook.php`
- Test: `tests/Feature/Pricing/GhanaCheckoutTest.php`

**Interfaces:**
- Produces: `PriceBook::currencyFor(Tenant $tenant, Request $request): string` (the single rule: eligible gives GHS; otherwise USD, except a Ghana-country visitor who is not yet eligible, who is offered GHS with proof required); `PaystackGateway::createOneTimeCheckoutSession` sends `channels` when given; checkout metadata gains `pricing.proof_required` (true when a not-yet-eligible organisation is offered the Ghana price) and passes `['mobile_money', 'card']` as channels in that case.

- [x] **Step 1: Failing tests:** `an eligible organisation is charged the ghana price on every channel`, `an organisation abroad is always charged the USD equivalent in GHS`, `a not yet eligible organisation in Ghana is offered the ghana price with mobile money and card only and proof required`, `a posted currency or price field changes nothing`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement `PriceBook::currencyFor` and use it at the three checkout paths in place of reading `VisitorCurrency` directly; extend the gateway signature with an optional `channels` argument (update the contract and any test doubles).
- [x] **Step 4:** Rerun with `tests/Feature/Pricing` and `tests/Feature/Billing`; PASS. Commit `feat(pricing): the Ghana price is charged only to eligible organisations`.
  Ruling: instead of `PriceBook::currencyFor` and a gateway signature change, the currency still comes from `VisitorCurrency::resolve($request, $tenant)` and a new `CheckoutTerms::for($tenant, Money $price)` decides proof from the *price actually charged* (a GHS price to an organisation that is not Ghana eligible), so the fallback GHS price when USD is paused or missing also needs proof. Channels travel in the checkout metadata (`allowed_channels`) and `PaystackGateway` turns them into Paystack's `channels`; the `PaymentGateway` contract is unchanged, so no mock changed. Enterprise agreed prices use `CheckoutTerms::agreed()`. Plan renewals (pay link and auto-charge) are Task 5.

### Task 4: Proof at payment, and the foreign-card top-up

**Files:**
- Create: `app/Services/Pricing/GhanaPaymentProof.php`, `app/Mail/Billing/GhanaPriceAdjusted.php`
- Modify: `app/Http/Controllers/Billing/CallbackController.php`, `app/Http/Controllers/Billing/WebhookController.php`, `app/Services/Billing/SubscriptionProvisioningService.php`
- Test: `tests/Feature/Pricing/GhanaPaymentProofTest.php`

**Interfaces:**
- Consumes: the verified transaction array (`channel`, `authorization.country_code`) and the quote in `metadata.pricing`.
- Produces: `GhanaPaymentProof::settle(Tenant $tenant, array $transaction): string` returning `proven`, `topped_up`, `top_up_failed` or `not_applicable`. `proven` calls `GhanaEligibility::grant($tenant, 'ghana_payment', …)`. For a foreign card it computes the difference between the USD-converted charge (current rate) and what was paid and calls `PaystackGateway::chargeAuthorization` with reference `topup-{original reference}`; on success the payment stands and the organisation is marked `INTL`; on failure the subscription runs for the paid period, `billing_currency` becomes USD for the next, and a superadmin is alerted. Both paths call `settle` and it is idempotent by reference.

- [x] **Step 1: Failing tests:** `a mobile money payment on the ghana price makes the organisation eligible`, `a ghana card does`, `a foreign card is topped up on the same authorization and the organisation becomes USD`, `a failed top up keeps the paid period, switches the next to USD and alerts`, `the webhook and the callback settle once`, `no proof is needed for an already eligible organisation`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement `settle` and call it from both fulfilment paths for payments whose metadata has `pricing.proof_required`.
- [x] **Step 4:** Rerun; PASS. Commit `feat(pricing): Ghana payment proves eligibility; foreign cards are topped up`.
  Ruling: `GhanaPaymentProof::settle` returns the outcome string (`proven`, `topped_up`, `top_up_failed`, `paid_in_full`, `unresolved`) and is called from the callback's `sendReceipt` and the webhook's `$sendReceipt` closure, the one place each path passes after fulfilling. It takes a cache lock on the reference, stores the outcome on the payment (`meta.ghana_proof`) and records `meta.payment_origin` on every priced platform payment, so a second delivery changes nothing; the top-up also uses the fixed reference `topup-<reference>`, which Paystack rejects if repeated. Checkout metadata now carries `pricing.usd_alternative_minor` so the top-up can be computed at the rate on the day. When the foreign card already paid at least the USD price (as it does today, because the Ghana list prices are above the USD equivalents) nothing is charged and the organisation is simply settled in USD; the overpayment is not refunded automatically. A payment with no USD price to compare to is left `unresolved` and finance is emailed. `GhanaEligibility::ensure` (called when the currency is chosen) notices new evidence, with a ten-minute negative cache; an organisation settled in USD is upgraded only by a verified Ghana payout account, never by a payment. Failed top-ups email the organisation (`GhanaPriceAdjusted`) and platform finance (`GhanaTopUpFailedAlert`).

### Task 5: Renewals follow eligibility

**Files:**
- Modify: `app/Models/Subscription.php`, `app/Services/Billing/RenewalScheduler.php`, `app/Services/Billing/BillingNotifier.php`, the renewal reminder mail view
- Test: `tests/Feature/Pricing/GhanaRenewalTest.php`

**Interfaces:**
- Produces: `Subscription::chargeQuoteFor` uses the Ghana price while the tenant `isGhanaEligible()` and USD otherwise; when eligibility is removed, the next renewal reminder states the new amount and that it is converted from USD on the renewal day.

- [x] **Step 1: Failing tests:** `an eligible organisation renews at the ghana price`, `after revoke the renewal is the usd price converted on the day`, `the reminder before such a renewal names the new amount`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement; also add the line "converted from USD at the Bank of Ghana rate on the day" to every USD renewal reminder.
- [x] **Step 4:** Rerun with `tests/Feature/Billing/RenewalSchedulerTest.php`; PASS. Commit `feat(pricing): renewals follow Ghana eligibility`.
  Ruling: `Subscription::renewalCurrency()` prices the next period by where the organisation stands today (Ghana eligible gives GHS, settled in USD gives USD) and falls back to the stored `billing_currency` for an organisation nobody has classified, so existing customers are not repriced until the backfill (Task 6) classifies them. Renewals do not ask for Ghana proof: pay-link and auto-charge renewals keep working for every payment method, and the payment's origin is still recorded as evidence. The reminder carries `priceNote` (USD price, conversion on the day, today's figure, and the change from cedis when there is one). A recorded renewal updates `billing_currency` to the currency it was priced in.

### Task 6: Existing customers

**Files:**
- Create: `app/Console/Commands/Pricing/BackfillGhanaEligibilityCommand.php`
- Test: `tests/Feature/Pricing/BackfillGhanaEligibilityTest.php`

**Interfaces:**
- Produces: `pricing:backfill-ghana {--dry-run}` marking eligible every tenant with `GhanaEligibility::evaluate` evidence (source `ghana_payment` or `payout_account`), reporting counts per source, idempotent.

- [x] **Step 1: Failing tests:** `a tenant with a mobile money payment is marked and one with only foreign cards is not`, `dry run changes nothing`, `a second run changes nothing`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement the command.
- [x] **Step 4:** Rerun; PASS. Commit `feat(pricing): backfill Ghana eligibility for existing customers`.
  Ruling: the command and the runtime check share `GhanaEligibility::grantableEvidence()`, so what the backfill would do and what checkout would do cannot disagree: an organisation settled in USD is upgraded only by a verified Ghana payout account, and one a superadmin removed is never touched. Organisations with no evidence are left unclassified (not set to USD), so their renewals keep the currency they were on until someone decides. The dry run prints counts per evidence source.

### Task 7: Superadmin

**Files:**
- Create: `app/Http/Controllers/Admin/PricingRegionController.php`, `app/Http/Requests/Admin/UpdatePricingRegionRequest.php`
- Modify: `resources/views/admin/tenants/show.blade.php`, `routes/web.php`
- Test: `tests/Feature/Pricing/PricingRegionAdminTest.php`

**Interfaces:**
- Produces: the organisation page shows the pricing region with its evidence; `POST tenants/{tenant}/pricing-region` grants or removes eligibility with a reason, behind the `manage-commercial-terms` gate (same permission as ticket terms).

- [ ] **Step 1: Failing tests:** `a superadmin grants and removes with a reason and it is logged`, `platform finance without the permission cannot`, `an organisation cannot`.
- [ ] **Step 2:** Run; FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Rerun; PASS. Commit `feat(pricing): superadmin controls Ghana eligibility`.

### Task 8: Verification and rollout

- [ ] **Step 1:** Run the full suite, `pint`, phpstan, `npm run lint`, `npm run test`, `npm run build`. Expected: all green.
- [ ] **Step 2:** Open a PR; one review of the whole branch by a fresh reviewer, given Review Focus above.
- [ ] **Step 3 (needs the owner's go-ahead on production):** after deploy run `pricing:backfill-ghana --dry-run`, then without `--dry-run`, then check three known customers by hand. Only after this may a Ghana price be set below the USD equivalent.
