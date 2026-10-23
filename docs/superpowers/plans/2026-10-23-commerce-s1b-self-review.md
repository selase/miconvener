# Self-Review Report — S1b checkout

Scope: native implementation of the approved [checkout plan](2026-10-22-unified-commerce-s1b-checkout.md), based on main `b358f658`, branch `codex/commerce-s1b`. Source review candidate; no merge, deployment or production checkout activation. Final independent review/CI evidence will be attached to the scoped PR.

## Touchpoints updated

- [x] AGENTS.md — original-account capture, immutable quote aliases/receipts, locking, Commerce accounting, recovery and privacy rules documented.
- [ ] Memory files — N/A; project decisions are retained in AGENTS.md, plan/roadmap and the execution ledger, rather than a second private source of truth.
- [x] Seeders/roles — `manage store orders` and `manage store finance` added together in PermissionsSeeder, RolePermissions and tenant-safe custom permissions. Owner finance defaults remain separate from ordinary order management; fee approval still uses the global superadmin gate. No live seed was run.
- [x] Navigation — console Catalog/Orders/Finance; buyer orders from My money and the legacy My header; product checkout only with exact flags/approved setup; immutable receipt links from the order and transactional email.
- [x] Permissions — server tenant ownership, fresh role checks, original verified-email buyer reads and explicit financial action guards. Recovery remains available when new checkout is disabled.
- [ ] .env.example — N/A; no new environment variable or dependency. Use existing settlement/mail/queue configuration; flags and agreements are database-backed and default closed.
- [x] Config/scheduling — new exact checkout switch checks, Commerce category, original configured-account identity, bounded reconciliation and receipt sweep. No production flag/credential/mode change.
- [x] Tests — author affected-feature gate: **395 PHP tests / 1,686 assertions**, including marketplace/venue preservation, money/OTP/dues/Giving/permissions, plus two native free/paid quote-to-receipt HTTP scenarios (22 assertions); **39 focused JS/native identity/offline checks**; scoped PHPStan, lint, Pint and committed rebuilt assets. Final CI is the full-suite gate, not a second local full-suite loop.

## UI Gap Analysis

- **Discoverability — present:** native email-proof history and receipts, product review/checkout panel and separate seller order/finance navigation. Buyer quotes remain discoverable; unpaid holds can be released only without an unresolved attempt.
- **Empty states — present:** no orders, missing photos, disabled/unapproved checkout, no refunds/payouts, no verified payout account and zero available balance. Store purchasing does not require dues or Community.
- **Permission gating — present:** private products require active self/email membership at quote; order history belongs to the original verified email. Staff actions are seller-scoped and finance-gated; recovery never borrows another tenant/category's balance.
- **Loading/error states — present:** busy/retry/error focus, countdown/expired state, unknown-payment warning without repeated initialization, explicit payment/collection/email states, bounded outbox retries and readable receipt when email fails. Requests clear/revalidate private history on focus/visibility/proof expiry; late product requests are aborted on leave.
- **Mobile/responsive — present with limits:** local native order/receipt widths 320/390/768/1440 had no horizontal overflow; long options and light/dark were inspected. Native seller navigation, free cancellation and finance zero balance/disabled payout were exercised. Actual 200% device/text zoom is still unverified with the available browser controls.
- **Accessibility — present with limits:** labelled native inputs/radios/selects, keyboard-accessible actions, timer label, error/status roles, error focus and square theme-token surfaces. This is not a claim of an external accessibility audit.

## Solved (auto-fixed)

- Original-account attempts, immutable quotes/lines/agreement versions and canonical tenant→order→sorted inventory locking. Durable append-only observation versions retain later verification evidence without erasing earlier observations or rewriting posted finances.
- Real cash and buyer refund liabilities independently bound seller withdrawals; approved reserves cannot silently fund payouts. Full uncollected refunds require funding and verified provider completion before one eligible restock; extra captures do not restock original goods.
- Quote refresh with a new request key no longer extends the hold: append-only aliases return the original live quote and retain changed-payload idempotency. Bulk listing/member access and held-stock aggregates avoid per-variant eligibility reads and false final-unit availability.
- Platform takedown blocks new charge initialization; already received money remains recoverable. Native staff reconciliation reuses the original reference and cannot create another charge.
- Email fakes missed a real Mailable public-property/view-snapshot collision. Actual receipt rendering reproduced it; a private readonly model property fixes it, with a regression and buyer-fee receipt coverage.
- Queued/sending receipt claims survive queue dispatch/worker/email faults. A stable receipt identity is reused; mail acceptance is not presented as inbox delivery or exactly-once SMTP.
- Tenant deletion now refuses retained agreements before logo removal. Commerce payout destinations/history cannot cascade away with bank removal, including parked/terminal transfers. Bank edits after submission cannot redirect an already submitted transfer or block original-reference recovery.
- Provider responses/private checkout URLs/OTPs are excluded from diagnostics; sanitized commerce errors do not carry raw provider exception chains. Public DTOs omit seller-borne commission/rates/account identity. Original event fee cascade and Giving merchant-note semantics are preserved.

### Source locations for fixed issues

- `app/Services/Marketplace/StoreQuotes.php:25–121` — immutable alias/window handling, bulk eligibility and stock quote.
- `app/Services/Marketplace/StorePayments.php:22–135` — guarded initialization, product takedown and sanitized verification.
- `app/Services/Marketplace/StoreReceipts.php:20–85` and `app/Mail/StoreOrderReceipt.php:15–36` — immutable receipt/outbox and safe real-template rendering.
- `app/Services/Marketplace/StoreBalance.php:23–82` — payable, real cash, refund liabilities and reserve floor.
- `app/Http/Controllers/Admin/TenantController.php:469–490` and `app/Http/Controllers/Tenant/TenantPayoutAccountController.php:69–90` — retention before destructive side effects.
- `app/Http/Controllers/Tenant/Store/StoreFinanceController.php:45–65` — original-reference staff recovery, with no new charge.

## Recommended for user decision

1. **Isolated provider acceptance — required before activation.** Approve the named non-production operator/account/recipients/actions and test-fund limits, then execute the [sandbox runbook](2026-10-23-commerce-s1b-sandbox-acceptance.md). Browser quote→provider→capture and real email/OTP/refund/payout outcomes remain unverified; the owned local QA site intentionally has no payment key, and none was substituted. Recommend a scheduled acceptance session; provider and mail delays determine its duration.
2. **Commercial/funding ownership — required before trading.** Explicit numerical terms, refund fee bearer/reserve funding, support, receipt/tax presentation and pilot products/contact remain owner decisions. No inferred 10%, automatic fee waiver or invented gateway credit.
3. **Collection — next S1c plan/acceptance.** Keep trading disabled until ready/collected handover and refund-vs-collection operations are proven using the same locks. No collection button was added in S1b. Shipping, partial refunds/exchanges/damaged goods, own-gateway/Stripe and rentals/services remain separately scoped.
4. **Actual text zoom/device acceptance — open.** Check native 200% text zoom and target phones in the approved acceptance environment; responsive-width checks and unit tests do not replace that evidence.

## Not gaps

- Existing venue IDs/URLs, rooms/calendar/RFQ/quote/payment continuations remain on their existing paths; no fake Event, venue conversion or legacy balance migration.
- Immutable payment receipts retain original amounts after a refund; the current order separately shows refund/collection state. They are not statutory VAT invoices or proof of collection.
- Unknown provider outcomes and underfunded refunds remain visible for reconciliation; they are deliberately not converted into unsafe retries or invented success.
- Purpledot's standing Community instruction does not name a Commerce pilot. No production organisation was enabled.

## Execution rulings and evidence limits

Complete chronological rulings are retained in `.superpowers/sdd/2026-10-22-unified-commerce-s1b-checkout/progress.md`. Material scope decisions: models stay in owning tasks; root agreement UI follows the existing Blade admin while tenant/buyer UI stays React; paid consumption remained closed until capture/ledger integration; Tasks 4/5 share a commit because callbacks depend on the confirmer; free receipt assertion moved to its owning outbox task; capture corrections are append-only versions, never an erased observation; withdrawable funds are bounded by both payable and real cash; Commerce recipient registration does not cache onto a bank record edited during HTTP; request-key aliases preserve the original quote/window; local payment configuration was not substituted for browser acceptance. No dependency change or extra per-task reviewer.

Harness corrections were also recorded: overlapping early test runs collided rebuilding the original test database; stopped the owned run and moved to a dedicated serialized commerce database. One command omitted that database after compaction and was stopped/rerun correctly. Generated migration stub/type and index-name errors were corrected; an invalid test-name filter ran zero tests and was rerun with human-readable names. These are not presented as successful red/green feature evidence. Local browser fixtures used logged email and synthetic free items only; no real charge/refund/payout or production access occurred.
