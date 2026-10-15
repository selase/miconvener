# Finance / Settlement Rigor — Design Spec

**Date:** 2026-09-05
**Status:** Draft — awaiting review
**Roadmap item:** #11 of the post-launch outstanding list (see session history) —
scoped down from "real gateway split-settlement or a proper ledger, KYC/KYB, payout
account verification" per explicit direction below.

## 1. Goal

Give the platform a real, reconciled settlement engine: money for "default" tenants
(those without their own payment gateway) flows into Purpledot's own Paystack
account, gets a real ledger entry (gross, actual gateway fee, platform commission,
net), and is paid out automatically to the tenant's registered bank/mobile-money
account via the Paystack Transfers API — replacing today's manual, self-attested
"record a payout, mark it paid" bookkeeping.

Tenants who already run their own Paystack/Stripe account keep today's behavior
completely unchanged: money goes straight to them.

## 2. Explicitly out of scope (confirmed with the user)

- **Pricing/package-tier redesign** (Free/Pro/Business tiers, fee-by-tier, email/SMS
  usage metering, feature gating). Informed by researching Luma's pricing model and
  this app's existing `Package`/`Feature`/`tenant_features` infrastructure, but
  deliberately split off as its own separate, later initiative — it's a product
  strategy exercise, not an engineering task, and doesn't block this batch (the
  settlement engine just reads a commission % from wherever it's configured today).
- **Full KYC/KYB** (business registration docs, ID upload, admin review queue).
  Explicitly declined in favor of lightweight account-resolve verification only.
- **Stripe settlement adapter.** Researched (Stripe Connect "separate charges and
  transfers" + Custom/recipient-configuration accounts is the equivalent pattern),
  but not built now — the user has a Paystack account and can provide sandbox keys;
  no Stripe account exists yet. The `SettlementGateway` contract is designed so a
  Stripe (or any other) adapter can be added later without touching callers.
- **Automatic commission collection for `own_gateway` tenants** (e.g. via Paystack
  subaccount linkage at charge time). These tenants keep receiving 100% of ticket
  revenue directly; how the platform bills them for its commission is a pricing-model
  question, deferred with item 1 above.
- **Live sandbox verification during this build.** The user can obtain Paystack test
  keys, but this spec's implementation phase builds and tests entirely against
  `Http::fake()`, matching this app's existing gateway-test conventions. Real
  sandbox verification (resolve/recipient/transfer/webhook against Paystack's actual
  test environment) happens as a follow-up step once keys are supplied — flagged
  explicitly, not silently assumed to be equivalent to mocked coverage.
- **SMS sending.** Doesn't exist anywhere in this codebase; irrelevant to settlement.

## 3. Settlement modes (per-tenant)

New `Tenant.settlement_mode` string column: `platform_default` (new, and the
default for new tenants) or `own_gateway` (today's behavior). Set from the existing
tenant payments/settings screen.

- **`own_gateway`** — unchanged. `EventCheckoutController` charges the tenant's own
  Paystack/Stripe credentials via the existing `TenantPaymentGateway` record. Requires
  an active gateway, exactly as today's `hasActivePaymentGateway()` gate already
  enforces for publishing paid events.
- **`platform_default`** — `EventCheckoutController` charges **Purpledot's own**
  Paystack secret key (new platform-level credential, config/env-based — e.g.
  `config('services.settlement.paystack.secret_key')` — never stored per-tenant)
  instead of the tenant's. This is the only new code path in the checkout
  controller; `own_gateway` tenants hit the exact same code they do today.
- A tenant with `settlement_mode = platform_default` does not need a
  `TenantPaymentGateway` row at all — this is what removes the "getting a Paystack
  account is tedious" friction the user flagged.

## 4. Provider abstraction

New `App\Contracts\SettlementGateway` interface — a distinct concept from two
existing gateway abstractions already in this codebase, which must not be confused:

| Contract | Purpose | Status |
|---|---|---|
| `App\Contracts\PaymentGateway` | Bills **tenants** for their own platform subscription | Existing, unrelated, untouched |
| `TenantPaymentGateway` + `PaystackGateway`/checkout | Direct ticket-charge for `own_gateway` tenants | Existing, unrelated, untouched |
| `App\Contracts\SettlementGateway` (new) | Resolve/pay out `platform_default` tenants | **New, this spec** |

```php
interface SettlementGateway
{
    public function resolveAccount(string $bankCode, string $accountNumber): array; // ['account_name' => string]
    public function createRecipient(string $type, string $name, string $accountNumber, string $bankCode, string $currency): string; // returns recipient_code
    public function initiateTransfer(string $recipientCode, int $amount, string $currency, string $reference): array; // ['transfer_code' => string, 'status' => string]
    public function verifyTransfer(string $reference): array; // ['status' => string]
    public function listBanks(string $country = 'ghana'): array; // [['name' => ..., 'code' => ...], ...] — for the account-setup dropdown
}
```

Bound in a service provider via `config('services.settlement.default')`, mirroring
the existing `PaymentGateway` binding pattern. `App\Services\Settlement\PaystackSettlementGateway`
is the only concrete implementation shipped now.

**Open question to verify against real Paystack docs/sandbox, not assumed:**
`resolve_account_number` is documented for bank (NUBAN) accounts; whether Ghana
mobile money numbers resolve identically through the same endpoint and the same
recipient `type`, or need a distinct flow, wasn't confirmed during this design's
research pass. The interface above is written generically (`resolveAccount`,
`createRecipient(type, ...)`) so either shape can be accommodated, but the
implementation plan must verify Paystack's actual mobile-money recipient
requirements against sandbox before assuming bank and mobile-money accounts are
interchangeable through this interface.

Charging itself (the `platform_default` checkout flow) reuses the **existing**
`PaystackGateway::createCheckoutSession`/`charge` methods already used for SaaS
billing — a charge to Purpledot's own account is identical Paystack API-wise
regardless of which internal system initiated it, so this is instantiated with
Purpledot's platform credentials instead of a tenant's, with no new charging code
needed.

## 5. Data model

### `tenants` — add column

| column | notes |
|---|---|
| `settlement_mode` | string, default `platform_default` |

### `tenant_payout_accounts` — add columns

| column | notes |
|---|---|
| `bank_code` | string — a real Paystack bank/mobile-money-provider code, chosen from `listBanks()`, not free text |
| `recipient_code` | nullable string — cached Paystack transfer-recipient code, created once and reused across payouts |
| `resolved_account_name` | string — the account holder name Paystack's resolve API returned, shown back to the host for confirmation |

`is_verified` (existing column) is now actually meaningful: set `true` automatically
when `resolveAccount()` succeeds at creation time; creation is rejected outright if
resolution fails (no silent "unverified" save).

### `event_ledger_entries` (new table, landlord connection, tenant-scoped)

One row per money-movement event — this replaces the ad-hoc `sum()` queries
`EventFinanceController` does today over `EventRegistration`/`EventPayout` fields
directly.

| column | notes |
|---|---|
| `id`, `tenant_id`, `event_id` | |
| `type` | `charge`, `refund`, `payout` |
| `registration_id` | nullable — set for `charge`/`refund` rows |
| `payout_id` | nullable — set for `payout` rows |
| `gross_amount` | integer, minor units |
| `gateway_fee_amount` | integer — **the real fee Paystack reports** on the charge/transfer response, not an estimate |
| `commission_amount` | integer — computed from the effective platform-fee % at the time of the charge |
| `net_amount` | integer — `gross - gateway_fee - commission` for charges; the paid-out amount for payouts |
| `currency`, `provider`, `provider_reference` | |
| `created_at` | |

### `event_payouts` — extend

| column | notes |
|---|---|
| `status` | add `processing`, `failed` alongside existing `scheduled`, `paid` |
| `provider_reference` | nullable — the Paystack transfer reference |
| `failure_reason` | nullable string |

`storePayout` validates the requested amount against the ledger-computed available
balance (`sum(charge.net) - sum(refund.net) - sum(paid/processing payout.net)`) for
the event, rather than trusting an arbitrary host-entered number — this closes the
over-payout gap the research phase found (nothing today stops recording a payout
larger than what was ever collected). This check-and-create must happen inside a
transaction with a row lock on the event (or an equivalent serializing mechanism) —
a plain query-then-insert is a TOCTOU race: two concurrent payout requests (or a
payout racing a refund) could each read the same available balance and both pass,
over-drawing anyway. Given this is the exact failure mode this batch exists to
close, the lock is not optional.

### Ledger entry producers (who writes each row)

- **`charge`** — written where a charge is *confirmed*, not where a registration is
  created: `PublicEventController::register()` for the immediate-free-confirm path,
  and `Tenant/Commerce/WebhookController` for the async paid-confirmation path
  (both `platform_default` and `own_gateway` registrations already funnel through
  this webhook today). Never written at registration-creation time for a
  `pending_payment` registration — the ledger only reflects money that has actually
  moved.
- **`refund`** — written when a confirmed registration is cancelled/refunded for a
  `platform_default` tenant: the cancel flow must call `PaystackGateway::refund()`
  (already exists, currently unused for events) against the original charge
  reference, and only write the ledger row once Paystack confirms the refund
  succeeded. The refund entry reverses **both** the gross amount and the
  commission originally taken on that charge — the platform does not keep a
  commission on money it no longer holds. For `own_gateway` tenants, refunds
  happen on the tenant's own Paystack dashboard/account outside this app's
  control; whether/how those get reflected back into the ledger (e.g. via their
  gateway's refund webhook, if forwarded) is unresolved and should be scoped
  explicitly in the implementation plan rather than assumed.
- **`payout`** — written by the transfer webhook (§6 step 4) once Paystack confirms
  `transfer.success`, not at "Send payout" time — the ledger reflects confirmed
  money movement, and a `processing` payout that later fails must never have left
  a `payout` ledger row behind.

## 6. Payout flow

1. Host adds a payout account: picks a bank/mobile-money provider from
   `listBanks()`, enters an account number. Backend calls `resolveAccount()`;
   success stores `resolved_account_name` + `is_verified = true`; failure rejects
   the request with the provider's error message.
2. Host records a payout for an event (existing `storePayout` UI/endpoint, amount
   now validated against available balance per §5). This step **does not move
   money** — it only creates a `scheduled` record, exactly as today. This keeps a
   human-in-the-loop checkpoint between "I want to pay this out" and "money
   actually moves," which matters precisely because the OTP-disablement prerequisite
   noted below removes Paystack's own per-transfer confirmation.
3. A separate, explicit "Send payout" action (new) on a `scheduled` **or `failed`**
   payout is what actually triggers movement: `createRecipient()` if
   `recipient_code` isn't already cached on the payout account, then
   `initiateTransfer()` with a **freshly generated reference for this attempt**
   (never reused verbatim across attempts/retries — a retry after a network error
   or a re-send after `failed` must not risk Paystack treating it as the same
   transfer, or double-send the money). Payout status → `processing`,
   `provider_reference` stored. There is no automatic/cron-driven sending at
   `scheduled_at` time in this phase — a host must explicitly trigger it, and can
   re-trigger a `failed` payout the same way.
4. New platform-level webhook `POST /webhooks/settlement/paystack` (tenant-agnostic
   route — this fires from Purpledot's own Paystack account, not a per-tenant
   subdomain, so it lives outside the `{subdomain}` tenant-resolution route group)
   handles `transfer.success`/`transfer.failed`, flipping the payout to
   `paid`/`failed` and writing the corresponding `event_ledger_entries` payout row
   (only for `transfer.success`; a failed transfer moves no money and gets no
   ledger row).

   This webhook **must** verify Paystack's `x-paystack-signature` header (HMAC
   SHA512 of the raw body using the platform's Paystack secret key) before trusting
   anything in the payload — without this, anyone who finds the URL could POST a
   fake "payout succeeded" event. It must also be **idempotent**: Paystack retries
   webhook delivery on any non-200 response, so re-processing the same
   `transfer_code` twice (e.g. a payout already `paid`) must be a no-op, not a
   duplicate ledger row or a duplicate notification.

**Deployment prerequisite, not application code:** Paystack requires OTP-per-transfer
confirmation to be disabled on the business account before transfers can be
initiated programmatically at all. This is a one-time dashboard/API setting change
on Purpledot's own Paystack account, done by whoever operates it — not a toggle this
app builds a UI for. Worth naming plainly: disabling it means a compromised
platform Paystack secret key could move money without a human confirming each
transfer. This is accepted as a cost of automation, not hidden.

## 7. Commission rate

No change to the underlying mechanism — `Event::effectivePlatformFeePercentage()`
(`event.platform_fee_percentage ?? tenant.platform_fee_percentage ?? config default`)
stays exactly as it is. The only change: it becomes settable from the tenant
settings / admin console UI, instead of only via the existing
`php artisan events:set-platform-fee` CLI command. The CLI command remains for
scripted/bulk use.

## 8. Settlement statement rework

`EventFinanceController::index()` and `exportSettlementStatement()` are rebuilt to
read from `event_ledger_entries` instead of computing inline from
`EventRegistration`/`EventPayout`. The exported CSV gains a real gateway-fee column
and a reconciliation section (collected, already paid out, available balance) —
closing the "no refund handling, no gateway fee, no reconciliation against payouts"
gaps the research phase identified. Existing `own_gateway` tenants still get
ledger rows so the statement format stays uniform across both settlement modes;
`gateway_fee_amount` is stored as `0` (not null) for these, since the platform has
no visibility into a fee charged on a gateway relationship it doesn't control —
the settlement statement should label this column "not tracked" rather than imply
zero fees were charged for `own_gateway` rows specifically.

## 9. Testing approach

- All new Paystack calls (`resolveAccount`, `createRecipient`, `initiateTransfer`,
  `verifyTransfer`, `listBanks`) tested via `Http::fake()`, matching
  `tests/Feature/Billing/PaymentGatewayTest.php`'s existing convention.
- Ledger arithmetic (gross/fee/commission/net, available-balance computation,
  over-payout rejection, refund reversing both gross and commission) gets direct
  unit-level Pest coverage.
- A test proving the row lock is actually exercised for the
  payout-amount-vs-available-balance check — Pest/Laravel's test client is
  single-threaded, so this can't be a literal two-simultaneous-HTTP-requests test;
  instead, open a second raw DB connection mid-transaction (or assert the query log
  shows a `lockForUpdate`/`FOR UPDATE` query) to prove serialization is really
  happening, not just trust that the code looks right. A single-request
  happy-path test alone would not catch a missing lock.
- The new transfer webhook gets feature tests for: a valid signed payload
  (`transfer.success` moves the payout to `paid` and writes one ledger row),
  an invalid/missing signature (rejected, no state change), and a duplicate
  delivery of the same already-processed event (no-op, not a second ledger row).
- A re-send test for a `failed` payout: triggers a fresh transfer attempt with a
  new reference, not a replay of the failed one.
- Existing `EventFinanceTest.php` assertions (encrypted-at-rest account numbers,
  masked API output, permission gates) must keep passing unchanged.
- **Not covered by this phase:** a real end-to-end run against Paystack's actual
  sandbox. Once the user supplies test keys, a follow-up verification pass (manual,
  against real `resolve_account_number`/transfer endpoints) should happen before
  any of this reaches production use with real money.

## 10. Migration / rollout notes

- `settlement_mode` defaults to `platform_default` for **new** tenants only —
  existing tenants must be backfilled explicitly to whichever mode matches their
  current real-world arrangement (most likely `own_gateway`, since that's the only
  mode that has existed until now) as part of the deploy, not left to silently
  flip behavior for accounts already in production.
- Purpledot's own Paystack secret key must exist in config/env before
  `platform_default` can work at all; until then, that mode should be blocked at
  the settings-UI level with a clear message, not silently fall through.
