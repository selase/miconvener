# Ghana price eligibility: design

Date: 2026-10-18. Status: draft for owner review, to be built later. Follows regional pricing (PR #38, live 2026-10-18).

## 1. Purpose

The Ghana (GHS) price may be lower than the USD price converted to GHS. For example, a plan at US$ 50 converts to about GHS 583, while Ghana pays GHS 300. Only organisations that are really in Ghana should get the Ghana price. Nobody should be able to choose it from abroad: not with the currency switch, not with a VPN, and not by editing a request.

Owner decision (2026-10-18): "No one should be able to switch to Ghana when they are not accessing the website from Ghana. We would have to think of other mechanisms to ensure location."

## 2. What is true today

- **Display:** the `mc_currency` cookie (the switch) wins. Otherwise Cloudflare's `CF-IPCountry` decides: GH means GHS, everyone else USD.
- **Checkout:** uses the same choice, so anyone can switch to GHS and pay the Ghana price.
- **Exposure today:** none. The Ghana prices are currently higher than the converted USD prices (GHS 249 against US$ 19 ≈ GHS 222). The gap only matters once a Ghana price is set below the USD equivalent. **This design must be built before any price is set that way.**
- **Location evidence the app already holds:**
  - **Tenant `country`:** self-declared on the superadmin tenant form. Weak.
  - **Payout accounts:** resolved and verified through Paystack Ghana (Ghana banks, or MoMo numbers such as MTN). Strong. A verified payout account means a Ghana bank account or Ghana mobile money wallet in the organisation's control.
  - **Paystack payment details:** every verified charge returns `authorization.country_code` (the card's issuing country, or GH for mobile money) and `channel`. The app stores the authorization for renewals but does not yet read the country.

## 3. Signals, from weakest to strongest

| Signal | How it can be faked | Strength |
|---|---|---|
| Currency switch (cookie) | Anyone clicks it | None. Remove it as a way to get GHS |
| `CF-IPCountry` = GH | A VPN or proxy with a Ghana exit | Weak alone; fine for display |
| Declared country on the organisation | Typing "Ghana" | Weak |
| Paying with Ghana mobile money | Needs a Ghana SIM with a registered MoMo wallet (KYC with a Ghana Card) | Strong |
| Paying with a Ghana-issued card (`country_code` GH) | Needs a Ghana bank card | Strong |
| A verified Ghana payout account | Needs a Ghana bank account or MoMo wallet in the organisation's name | Strong |
| Superadmin grant | Only staff | Authoritative |

The IP address tells us where someone browses from. A Ghana payment method or payout account tells us where their money is, which is what matters for a Ghana price.

## 4. Recommended design

**Principle:** the IP decides what people see. Ghana money decides what they pay.

### 4.1 Display

- Prices follow `CF-IPCountry` only: GH sees GHS, everyone else USD.
- The switch goes. Alternatively it can become "Show prices in USD" for visitors in Ghana; it never offers GHS outside Ghana.
- A visitor outside Ghana sees one line under the prices: "Organisation based in Ghana? Ghana pricing applies when you pay with Ghana mobile money or a Ghana bank card."

### 4.2 Charging: Ghana price only with Ghana money

At checkout, a Ghana price is offered only when the organisation is **Ghana-eligible**. Two routes:

1. **Already eligible (no friction):** the organisation has a verified Ghana payout account, or a superadmin has granted eligibility. Charge the GHS price, with all payment channels open.
2. **Proving it at payment:** the visitor is in Ghana by IP, but the organisation is not yet eligible.
   - Initialise Paystack at the GHS price with `channels: ['mobile_money', 'card']`.
   - On verification, accept it only if `channel` is `mobile_money`, or `authorization.country_code` is `GH`.
   - A successful Ghana payment marks the organisation Ghana-eligible (`tenants.pricing_region = GH`, with the evidence and date). Later payments and renewals don't need to prove it again.
   - If a non-Ghana card pays a Ghana price, see 4.3.

Everyone else is charged the USD price converted to GHS, exactly as today.

Paystack's Ghana account takes only Ghana mobile money, so mobile money is proof on its own. A Ghana-issued card is proof through its issuing country. Both need KYC in Ghana, which a VPN can't fake.

### 4.3 When the evidence doesn't match

The card's country is only known after Paystack has charged it. Three options:

- **A. Card first, then top up (recommended):** keep the payment. Charge the difference to the USD price with the saved authorization (`charge_authorization`). If that fails, give the plan for the period already paid at the Ghana price, mark the organisation USD from the next period on, and tell the superadmin. The customer is never left without what they paid for.
- **B. Refund and retry:** refund the payment in full and ask them to pay the USD price. Clean, but unfriendly, and Paystack refund fees apply.
- **C. Mobile money only for Ghana prices:** offer only `mobile_money` for the Ghana price, so no mismatch is possible. Ghanaian organisations paying by card would pay the USD price unless they have a verified payout account. Simplest and strictest.

Recommendation: **A**, with **C** as the fallback if card mismatches turn out to be common.

### 4.4 Renewals

Renewals use the subscription's `billing_currency` (built in PR #38). A Ghana-priced subscription renews at the Ghana price while the organisation stays Ghana-eligible. If eligibility is removed (by a superadmin, or a mismatch under 4.3), the next renewal is converted from USD, and the organisation is emailed beforehand.

### 4.5 Superadmin

The tenant page shows the pricing region and its evidence: "Ghana since 18 Oct 2026, paid with MTN MoMo"; "Ghana, verified payout account"; "granted by …". A superadmin can grant or remove eligibility with a reason, and the change is logged.

## 5. Also in this piece: editable add-on and pack prices

The owner wants to set Ghana and USD prices freely. Plans already have both prices in the superadmin package form. Add-ons, SMS/email packs and AI token packs have theirs in code (`TenantAddonService::CATALOG`, `config/llm.php`).

Proposal: a small `platform_prices` table (item key, GHS minor, USD minor). It overrides the code values and is edited in a superadmin "Prices" page. `PriceBook` reads the override first, so nothing else changes.

Coupons and time-limited promotions are not included; they are a separate piece if wanted.

## 6. Out of scope

Event tickets, dues and giving (organisations set their own prices); countries other than Ghana; checking a person's identity document.

## 7. Questions for the owner

1. **Mismatch rule (4.3):** A (top up, recommended), B (refund) or C (mobile money only)?
2. **The switch:** remove it entirely, or keep "Show prices in USD" for visitors in Ghana?
3. **Payout account as proof:** should a verified Ghana payout account alone make an organisation eligible (recommended), or must they pay with Ghana money once?
4. **Visitors outside Ghana:** may an organisation in Ghana whose staff travel or use a VPN still get the Ghana price at checkout, when its payment method proves Ghana (recommended)? Or must the IP also be Ghana?
5. **Existing customers:** treat organisations that have already paid with mobile money or a Ghana card as eligible from day one? This can be read from stored transactions.

## 8. Rough build plan (for the implementation plan)

1. `tenants.pricing_region` and its evidence. Eligibility from a verified payout account, a superadmin grant, or a past Ghana payment, with a backfill from stored Paystack transactions.
2. Display: remove the GHS switch outside Ghana, and add the explanatory line.
3. Checkout: the eligibility check; the Ghana price with channel limits; verification of `channel` and `country_code` in both the callback and the webhook; marking eligibility.
4. Mismatch handling per the owner's choice in 4.3.
5. Superadmin: show, grant and remove eligibility, with an audit log.
6. Editable add-on and pack prices (section 5).

Tests must cover:
- a VPN visitor from Ghana paying by foreign card is charged the USD price, or topped up;
- a visitor abroad cannot get GHS by any request;
- mobile money and Ghana cards grant eligibility;
- renewals follow eligibility;
- the existing GHS checkout is unchanged for eligible organisations.
