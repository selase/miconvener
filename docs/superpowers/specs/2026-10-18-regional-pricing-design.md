# Regional pricing: design

Date: 2026-10-18. Status: draft for owner review. Path: architectural (touches every platform checkout).

## 1. Purpose

Visitors in Ghana see and pay MiConvener's prices in GHS, as today. Everyone else sees fixed, round prices in USD. Paystack charges and settles the platform account in GHS only, so a USD price is converted to GHS at the current Bank of Ghana interbank rate when the person pays, and they are charged that GHS amount, told so before they pay.

## 2. Owner decisions (2026-10-18)

| Topic | Decision |
|---|---|
| Who sees what | Ghana: GHS. Everywhere else, and unknown: USD. |
| USD prices | Fixed per item (not converted live for display). |
| Charging | In GHS, converted from the USD price at the Bank of Ghana interbank rate when paying. |
| Settlement | GHS only (Paystack confirmed). No USD charges or settlement. |
| Rate source | Bank of Ghana published rate, with a republishing service as backup. Cached for an hour or two so the source is not hit unnecessarily. |
| Commission | Percentages never change by region. |
| Order | Build this first; the use-case redesign then uses it for its example prices. |

## 3. What exists today (investigated 2026-10-18)

- No exchange-rate code anywhere, in code or history.
- Platform charges are GHS via `config('services.paystack.currency')`: plans and renewals (`Billing\CheckoutController`, `SubscriptionRenewalService`), add-ons and SMS/email packs (`TenantAddonService::CATALOG`, unit prices in pesewas), AI token packs (`config('llm.token_packs')`, `LlmCheckoutController`), marketplace shop verification and boosts (in the add-on catalogue).
- Plan prices: `packages.price` and `packages.yearly_price` (GHS). The homepage pricing table (`livewire/pricing-table`) prints `config('services.paystack.currency')` before them.
- Subscription invoices label themselves `USD` by default (`InvoicingService`, admin rate card) although checkout charges GHS. This is a mislabel to correct as part of this work, not something to build on.
- Event tickets, dues and giving are priced by each organisation in its own currency; Stripe Connect already covers organisations that take USD on their own account. Out of scope here.
- Paystack test key: initialising a USD transaction returns "Currency not supported by merchant"; GHS works.

## 4. Who sees which currency

`VisitorCurrency::resolve(Request): 'GHS'|'USD'`, in order:

1. An explicit choice from the currency switch (cookie `mc_currency`, one year), so a Ghanaian abroad or a visitor on a VPN can choose.
2. The visitor's country from the hosting network: Laravel Cloud runs behind Cloudflare, which sends `CF-IPCountry`. `GH` means GHS; any other country, or none, means USD. Before relying on it in production, a check confirms the header reaches the application (it is only trusted from the platform's proxy).
3. In the console (signed-in organisation billing pages) the same rule applies; an organisation can also pin its billing currency in Billing settings, which then wins.

The switch appears on the pricing section, the Full features page and the console Billing page: "Prices in GHS · USD".

Pages that show prices must not be shared between currencies by any cache: responses vary by currency (no full-page caching of priced pages across visitors).

## 5. The price book

One service, `PriceBook`, answers every price shown or charged: `PriceBook::for(string $item, string $currency): ?Money`.

- GHS amounts stay where they are (packages table, add-on catalogue, token pack config).
- USD amounts are added beside them: `packages.price_usd` and `packages.yearly_price_usd` (editable by the superadmin in the package admin), `usd_unit_price` on each add-on catalogue entry, `price_usd` on each token pack.
- An item with no USD price shows and charges its GHS price, with no invented conversion.
- Every price on marketing pages and in the console goes through `PriceBook`, so the pricing table, add-ons, packs and use-case examples always agree.

Proposed USD price list (owner to confirm; roughly GHS ÷ 12, rounded):

| Item | GHS | USD |
|---|---|---|
| Starter, monthly / yearly | 249 / 2,490 | 19 / 190 |
| Growth, monthly / yearly | 499 / 4,990 | 39 / 390 |
| Team seat (monthly) | 75 | 6 |
| Usher pack (monthly) | 60 | 5 |
| Live polling (monthly) / event pass | 85 / 50 | 7 / 4 |
| SMS 500 / 1,500 / 5,000 | 35 / 95 / 290 | 3 / 8 / 24 |
| Email 5,000 / 25,000 | 25 / 90 | 2 / 8 |
| Shop verification (yearly) | 150 | 13 |
| Listing boost (weekly) / feature (monthly) | 50 / 200 | 4 / 17 |
| AI tokens: Starter / Standard / Enterprise pack | 60 / 180 / 600 | 5 / 15 / 50 |

Enterprise stays "contact us".

## 6. Exchange rate

`ExchangeRates::usdToGhs(): Rate` (`rate`, `as_of` date, `source`, `fetched_at`).

- **Sources:** primary, the Bank of Ghana interbank rate page (published once per working day, the weighted average of trades before 14:00); backup, a service that republishes the Bank of Ghana rate as data (API key in `.env`, optional).
- **Caching:** the latest rate is cached for 90 minutes; nothing calls the sources on a page view or checkout beyond that cache.
- **Storage:** every fetched rate is stored (`exchange_rates`: base, quote, rate, as_of, source, fetched_at), so the app always has the last known rate and every charge can be traced to the rate used.
- **Refresh:** a scheduled `fx:refresh` runs hourly on working days; it fetches only when the cache has expired, stores a new row only when the published date or value changed.
- **Staleness:** a rate older than 4 days raises an operational alert to the superadmin and is still used; older than 10 days, USD checkout is paused (visitors see the GHS price instead) until a fresh rate arrives. Weekends and Ghana public holidays are why the 4-day margin exists.

## 7. Charging a USD price

At every platform checkout, when the visitor's currency is USD and the item has a USD price:

1. Look up the USD price in `PriceBook` and the current rate.
2. GHS to charge = USD price × rate, rounded to the nearest pesewa.
3. Before redirecting to Paystack, show: "US$ 19.00 is GHS 223.70 at today's Bank of Ghana rate (11.77, 17 Oct 2026). You will be charged GHS 223.70; your bank converts it to your card's currency."
4. Initialise Paystack in GHS for that amount.
5. Record on the payment and invoice: GHS charged, display currency USD, USD price, rate, rate date and source. Receipts and invoices show both.

Paths covered: plan checkout, plan renewal (card auto-charge and pay links), add-ons and packs, AI token packs, marketplace verification and boosts, invoice payment. Renewals for a USD subscriber convert at the rate on the renewal day; the renewal email says so.

Invoices are corrected to carry the currency actually charged (GHS), with the USD reference amount when there is one.

## 8. Out of scope

Event tickets, dues, contributions and giving (organisations set their own currency); USD charging or settlement through Paystack; other currencies; automatic USD price updates from the rate.

## 9. Risks

- **Header not present in production.** Mitigation: verified before launch; without it every visitor would be treated as unknown (USD) except those using the switch.
- **Rate source changes its page.** Mitigation: backup source, stored history, staleness alerts and pause.
- **Customer surprise at the card statement.** Mitigation: the GHS amount and rate are shown before paying and on the receipt.
- **Misleading display.** USD prices are shown as prices with "billed in GHS at checkout" next to them; never a USD charge claim.

## 10. Tests (for the plan)

Country and cookie resolution (Ghana, other, unknown, explicit choice, organisation pin); `PriceBook` fallback when a USD price is missing; rate caching (no second fetch within 90 minutes), storage, backup source, staleness alert and pause; conversion and rounding; each checkout path charges GHS = USD × rate and records the rate; renewal conversion on the renewal day; pricing table and console show the right currency; invoices labelled with the charged currency; Paystack always initialised in GHS.
