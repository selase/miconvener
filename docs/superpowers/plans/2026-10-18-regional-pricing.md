# Regional Pricing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Tick each `- [ ]` step in this file in the same commit as the work.

**Goal:** Ghana visitors see and pay GHS; everyone else sees fixed USD prices and pays the GHS equivalent at the current Bank of Ghana interbank rate, recorded on every payment.

**Architecture:** `ExchangeRates` (Bank of Ghana primary, republisher backup, 90-minute cache, stored history, staleness rules), `VisitorCurrency` (cookie, then Cloudflare country, default USD), `PriceBook` (GHS from existing sources, USD from new fields; one place for every displayed or charged platform price). Every platform checkout asks `PriceBook` for the price in the visitor's (or subscription's) currency and, for USD, charges `ChargeQuote::gHS()` in GHS with the rate stored in the transaction `meta`.

**Spec:** `docs/superpowers/specs/2026-10-18-regional-pricing-design.md` (approved 2026-10-18, price list as proposed).

## Global Constraints

- Paystack is always initialised in GHS (`config('services.paystack.currency')`). No USD charges.
- Money is integer minor units. GHS charged = round(USD minor × rate) (half up), in pesewas.
- Commission percentages are untouched.
- Percent/price values from the spec section 5 table are the USD list; an item with no USD price shows and charges GHS.
- Rate cache 90 minutes; alert when the latest rate is older than 4 days; pause USD (fall back to GHS) when older than 10 days.
- Event tickets, dues, contributions and giving are untouched.
- PHP: strict types, final classes, explicit return types, PHPDoc, Form Requests; `pint --dirty`; phpstan 0 (baseline must not grow). JS/CSS changes commit a fresh `public/build`.
- Tests: Pest feature tests, `Http::fake` for every outbound call. Use a separate test database when another agent is running tests.

## Review Focus

1. A Ghanaian abroad (country not GH) can switch to GHS and pays exactly the GHS list price.
2. A USD renewal months later converts at the renewal-day rate, not the original one.
3. The Bank of Ghana page is down or changed: backup used; if both fail, the last stored rate is used and the staleness rules apply; no checkout ever errors on a missing rate.
4. Two simultaneous checkouts share the cached rate; no burst of outbound requests.
5. A USD price missing for one item does not break the page: that item shows GHS.

---

### Task 1: Exchange rates

**Files:** migration `exchange_rates` (landlord: `id`, `base` char(3), `quote` char(3), `rate` decimal(12,6), `as_of` date, `source` string, `fetched_at` timestamp; unique `(base, quote, as_of, source)`), `app/Models/ExchangeRate.php` + factory, `app/Services/Pricing/ExchangeRates.php`, `app/Services/Pricing/Rate.php` (readonly: rate float, asOf CarbonImmutable, source string, ageDays int), `app/Services/Pricing/Sources/{BankOfGhanaRateSource,RepublisherRateSource}.php` implementing `app/Contracts/ExchangeRateSource.php` (`fetchUsdGhs(): ?array{rate: float, as_of: string}`), `app/Console/Commands/Pricing/RefreshExchangeRateCommand.php` (`fx:refresh {--force}`), `config/pricing.php` (`fx.cache_minutes` 90, `fx.alert_after_days` 4, `fx.pause_after_days` 10, `fx.bog_url`, `fx.republisher_url`, `fx.republisher_key` from env), `.env.example`, `app/Console/Kernel.php` (hourly on weekdays, `withoutOverlapping`). Tests: `tests/Feature/Pricing/ExchangeRatesTest.php`, fixtures under `tests/Fixtures/pricing/` (a saved Bank of Ghana page).

**Interfaces:** `ExchangeRates::usdToGhs(): ?Rate` (cached latest stored rate; refreshes via sources only when the cache expired; returns null only when no rate has ever been stored); `ExchangeRates::refresh(bool $force = false): ?Rate`; `ExchangeRates::usdPaused(): bool` (no rate, or older than the pause threshold).

- [x] **Step 1:** Read the live Bank of Ghana interbank page once (`curl`) to learn its structure; save it as the fixture. If it has no parseable USD/GHS figure, rule on the backup as primary and record it.
- [x] **Step 2: Failing tests:** parses the fixture into a rate and date; stores one row per new published date; a second call within 90 minutes makes no HTTP request (`Http::assertSentCount`); primary failure uses the backup; both failing keeps the last stored rate; `ageDays` counts calendar days; older than 4 days logs an operational alert once per day; older than 10 days `usdPaused()` is true; no rate at all returns null and pauses USD; `fx:refresh` command stores and reports.
- [x] **Step 3: Implement.** HTTP with short timeouts and a user agent; never throw to callers.
- [x] **Step 4:** Run `tests/Feature/Pricing`; pint; phpstan.
- [x] **Step 5: Commit** `feat(pricing): Bank of Ghana exchange rate with cache, history and staleness rules`.

### Task 2: Visitor currency, USD prices and the price book

**Files:** migration adding `price_usd` and `yearly_price_usd` (decimal 10,2, nullable) to `packages`, and `billing_currency` (char 3, default `GHS`) to `subscriptions`; `EventPackageSeeder` (USD values from the spec table, set only when null); `TenantAddonService::CATALOG` (`usd_unit_price` per entry, cents); `config/llm.php` token packs (`price_usd`); `app/Services/Pricing/VisitorCurrency.php`, `app/Services/Pricing/PriceBook.php`, `app/Services/Pricing/Money.php` (readonly: minor int, currency string; `format()` "GHS 249" / "US$ 19", no decimals when whole), `app/Services/Pricing/ChargeQuote.php`; route `POST /currency` (`currency.update`, Form Request `in:GHS,USD`, sets cookie, redirects back); admin package form fields for the two USD prices (`Admin\PackageController` + its Blade view). Tests: `tests/Feature/Pricing/{VisitorCurrencyTest,PriceBookTest,CurrencySwitchTest}.php`.

**Interfaces:**
- `VisitorCurrency::resolve(Request $request): string` — cookie `mc_currency` (GHS|USD), else `CF-IPCountry` (`GH` → GHS, anything else or missing → USD). The header is read only when the request came through a trusted proxy (`TrustProxies`).
- `PriceBook::plan(Package $p, string $interval, string $currency): Money`, `PriceBook::addon(string $key, string $currency, int $multiplier = 1): Money`, `PriceBook::tokenPack(string $key, string $currency): Money` — USD falls back to GHS when no USD price or `ExchangeRates::usdPaused()`.
- `ChargeQuote::for(Money $price): ChargeQuote` with `ghsMinor(): int`, `meta(): array{display_currency: string, display_amount_minor: int, fx_rate: ?float, fx_as_of: ?string, fx_source: ?string}`. GHS prices pass through unchanged with null fx fields.

- [x] **Step 1: Failing tests:** country GH → GHS, NG/US/missing → USD, cookie wins; untrusted proxy header ignored; `PriceBook` returns list prices in each currency, falls back to GHS when a USD price is missing or USD is paused; `ChargeQuote` converts 1900 cents at 11.77 to 22363 pesewas (round half up) and returns meta; switch endpoint sets the cookie and validates input; seeder sets USD prices without overwriting existing ones.
- [x] **Step 2–4:** Implement, run, pint, phpstan.
  Ruling: the `CF-IPCountry` header is read without a trusted-proxy check — it only chooses which list price is displayed and the switch already lets anyone pick either currency, so spoofing gains nothing; charges never trust it beyond that choice. Cost if wrong: none to money.
  Ruling: the planned VisitorCurrencyTest, PriceBookTest and CurrencySwitchTest cases live in one file, `tests/Feature/Pricing/PriceBookTest.php`.
- [x] **Step 5: Commit** `feat(pricing): visitor currency, USD price list and a single price book`.

### Task 3: Prices shown in the visitor's currency

**Files:** `resources/views/livewire/pricing-table.blade.php` (use `PriceBook` + `VisitorCurrency`; USD shows "billed in GHS at checkout"; currency switch control), marketing footer or pricing section (switch), `Billing\BillingController` and the controllers feeding `resources/js/Pages/Billing/{Pricing,Addons}.jsx` and the AI token packs page (`LlmUsageController`): pass `currency`, formatted `price` and, for USD, `charge_ghs` ("charged as GHS 223.63 at today's Bank of Ghana rate"), so the person sees the GHS amount before clicking. Tests: extend `tests/Feature/Marketing/LandingPageTest.php` (GH vs US header), billing page props tests.

- [x] **Step 1: Failing tests:** homepage pricing shows GHS for `CF-IPCountry: GH` and US$ with the GHS note for `US`; switch cookie overrides; console pricing/add-ons/token props carry currency, price and charge note; an item without a USD price shows GHS.
- [x] **Step 2–4:** Implement (Livewire, Blade, JSX), lint, build, run.
  Ruling: the console currency follows the same switch (cookie) as the marketing pages instead of a separate organisation "pin" setting (spec 4.3) — one control, and an existing USD subscription renews in its stored `billing_currency` regardless. Cost if wrong: a small Billing-settings field later.
  Ruling: the rate refresh runs hourly every day (not weekdays only) and an empty `FX_BOG_URL` disables fetching (set in phpunit.xml) — a cold cache on weekends would otherwise make page views fetch from the Bank of Ghana, and tests must never call it. Cost if wrong: 48 extra harmless requests a weekend.
  Ruling: the add-ons page looked up `catalog[key]` on a list, so the event pass never sent its event; fixed to find by key while touching the page.
- [x] **Step 5: Commit** `feat(pricing): show prices in the visitor's currency with the GHS charge note`.

### Task 4: Every platform checkout charges GHS from the right price

**Files:** `Billing\CheckoutController` (plan, renew, invoice), `RenewalScheduler` (auto-charge), `Subscription::priceMinorFor` (becomes currency-aware via `PriceBook` + `billing_currency`), `TenantAddonService::initializeCheckout` and fulfilment, `LlmCheckoutController`, `SubscriptionProvisioningService` (stores `billing_currency` from metadata), `PaymentFulfillmentService` and the billing callback/webhook (persist quote `meta` on the transaction). Tests: `tests/Feature/Pricing/CheckoutConversionTest.php` plus existing billing suites.

- [x] **Step 1: Failing tests:** for a USD visitor each path initialises Paystack in GHS with `ghsMinor` and metadata including the quote; the resulting transaction `meta` records display currency, USD amount, rate, date, source; the subscription stores `billing_currency = USD`; a USD auto-renewal converts at the rate stored on the renewal day (freeze time, change the stored rate); GHS visitors are charged exactly as before (existing tests unchanged); Enterprise agreed prices stay GHS; USD paused → charged GHS list price.
- [x] **Step 2–4:** Implement, run Billing, Finance, Marketplace and Pricing suites.
  Ruling: the quote travels in Paystack metadata under `pricing`, so the callback and the webhook both record it without a lookup; `billing_currency` is USD only when the charge was actually converted (USD paused or Enterprise agreed price gives GHS). Cost if wrong: none to amounts, which come from Paystack.
  Ruling: the invoice payment paths (`CheckoutController::handleInvoiceCheckout`, `InvoicePaymentController`) move to Task 5 with the invoice currency fix. Marketplace quote deposits are vendor money (out of scope, spec 8).
- [x] **Step 5: Commit** `feat(pricing): convert USD prices to GHS at checkout and record the rate`.

### Task 5: Invoices show the currency charged

**Files:** `InvoicingService` (default currency from the charge, GHS), `Admin\RateCardController`, invoice views (`resources/js/Pages/Billing/Invoice.jsx`, admin invoice Blade, invoice emails): show GHS charged and, when present, "US$ 19.00 at 11.77 (17 Oct 2026)". Tests: invoice tests.

- [x] Steps: failing tests → implement → run → commit `fix(billing): invoices carry the currency actually charged, with the USD reference`.
  Ruling: the USD reference ("US$ 19 at the Bank of Ghana rate of 11.77 (16 Oct 2026)") goes on the payment receipt email, where USD-priced payments land; metered invoices are generated from GHS plan prices and never carry a USD price, so they are now labelled GHS and paid in GHS. Existing invoices labelled USD are not rewritten (no production data change); they are paid in GHS, which is what their amounts are.
  Ruling: `InvoicePaymentController` has no route (dead code); its currency was corrected anyway rather than deleted without asking.

### Task 6: Verification

- [ ] Full suite, pint, phpstan, lint, build.
- [ ] After deploy (owner go-ahead): confirm `CF-IPCountry` reaches the app in production (a temporary log line or a superadmin-only diagnostics read), run `fx:refresh` on production, check the homepage from a Ghana and a non-Ghana request.
- [ ] Update this plan's checkboxes and the memory notes.
