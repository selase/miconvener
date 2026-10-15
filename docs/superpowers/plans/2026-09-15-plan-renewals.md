# Plan Renewals Implementation Plan

> Executed inline (TDD, one commit per task). Steps use checkbox syntax.

**Goal:** Paid plans renew, lapse and end on schedule: saved reusable authorizations are charged on the renewal date, everyone else is emailed a pay link, unpaid plans get 7 days' grace and then move to Free. Token packs become purchasable in GHS.

**Architecture:** Plan payments stay one-off Paystack charges. The first payment's authorization is stored (encrypted) on the tenant's subscription. A daily `billing:process-renewals` command walks each tenant's current subscription through a small state machine (active → due → past_due with grace → ended), charging via `/transaction/charge_authorization` with a deterministic reference, and sending emails through `BillingNotifier`. Renewal payments, from the charge or from a pay link, are fulfilled by one idempotent `SubscriptionRenewalService::recordRenewal()` that extends the period from its old end.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 5, PostgreSQL (landlord), Paystack.

**Spec:** Decisions made in conversation 2026-09-15 (memory `subscription_renewal_decisions.md`). Paystack behaviour verified against PaystackOSS/paystack-mcp-server `src/data/paystack.openapi.yaml`: charge_authorization charges "authorizations marked as reusable"; customer mandates (`/customer/authorization/initialize`) are `direct_debit` only, so not available in Ghana; the live account holds GHS only.

## Decisions (binding)

| Decision | Value |
|---|---|
| Token packs | Starter 500k GHS 60 · Standard 2M GHS 180 · Enterprise 10M GHS 600 |
| Auto-charge | only when the stored authorization has `reusable: true` (not decided by channel) |
| Pay-link reminders (non-reusable) | 7 and 3 days before period end |
| At period end, reusable | charge once per day; first failure → past_due, grace 7 days, PaymentFailedMail with next retry date |
| At period end, non-reusable | past_due, grace 7 days, daily overdue reminder with pay link |
| Grace over, unpaid | `switchToFree` + SubscriptionEndedMail |
| Renewal period | extends from the previous `current_period_end` (early and grace payments don't lose or gain days) |
| Renewal price | the package's price at renewal time, for the subscription's interval |
| Pending downgrade at period end | to Free → end now (SubscriptionEndedMail); to a paid package → renew at that package, then apply it |
| Complimentary | `tenants.billing_complimentary`; renewal engine skips; set for tenants on a paid package with no Paystack plan payment (purpledot on prod); `billing:complimentary {tenant} {--off}` |
| ugmc | real payer (GHS 29, 2026-09-10) → normal renewal on 2026-10-10 via pay link (its authorization was never stored) |
| Charge reference | `renew-{subscription id}-{period end Ymd}-{attempt}` |
| Metadata | sent as a JSON object; routers decode a string defensively |

## Global Constraints

- Money in integer minor units; plan price `(int) round(price * 100)`.
- Landlord connection; migrations in `database/migrations/landlord/`; Postgres-only tests.
- `Model::shouldBeStrict()`: new attributes fillable.
- Authorization codes are secrets: `encrypted` cast, never logged or rendered.
- All billing emails via `BillingNotifier` → `BillingEmailSender` (once-only).
- Pint, PHPStan (baseline only shrinks), curly braces, return types, PHPDoc over inline comments.

## Tasks

### Task 1: Token packs in GHS
- Test: `LlmCheckoutController` sends GHS 6000 minor for starter; the buy-tokens modal renders `GHS 60.00`, not `$`.
- `config/llm.php` prices/currency; `admin/tenant/llm-usage/index.blade.php` shows `{{ $pack['currency'] }} {{ number_format(...) }}`.

### Task 2: Remember how a plan was paid
- Migration: `subscriptions` + `interval` (string, nullable), `authorization_code` (text, nullable), `authorization_reusable` (bool, default false), `authorization_email` (string, nullable), `authorization_label` (string, nullable), `grace_ends_at` (timestamp, nullable), `renewal_attempts` (unsigned small int, default 0); `tenants` + `billing_complimentary` (bool, default false). Data step: complimentary = on non-free package AND no transaction with provider paystack and `meta->package_id`.
- `Subscription`: fillable, casts (`authorization_code` encrypted, bool, datetime), `isPastDue()`, `intervalOrDefault()`, `planAmountMinor(Package)`.
- `provision()` stores `interval`, `authorization_*` from dto keys `interval`, `authorization` (Paystack authorization array), `customer_email`; label via `BillingNotifier::paymentMethod`.
- Webhook plan_subscription branch and callback plan handler pass them.
- Tests: webhook + callback store a reusable card authorization (encrypted at rest: raw DB value ≠ code); non-reusable stored with reusable=false; migration data rule marks a hand-provisioned tenant complimentary and leaves a payer alone.

### Task 3: Record a renewal once
- `PaystackGateway::chargeAuthorization(string $email, int $amountMinor, string $currency, string $authorizationCode, string $reference, array $metadata): array{status: string, reference: string, gateway_response: ?string, authorization: array, channel: ?string, paid_at: ?string, amount: int, currency: string}`.
- `SubscriptionRenewalService::recordRenewal(Subscription $subscription, string $reference, int $amountMinor, string $currency, array $provider): bool` — landlord transaction; false if reference recorded; extends `current_period_end` by interval from old end; clears grace/attempts; status active; applies pending paid downgrade package; refreshes authorization if the payment returned a reusable one; Transaction meta `type=plan_renewal`, `package_id`, `provider_plan_id`, `subscription_id`.
- Webhook `plan_renewal` branch (by metadata `subscription_id`) + callback branch → recordRenewal + receipt.
- Routers: decode string metadata.
- Tests: extends from old end; duplicate reference no-op; callback+webhook once; receipt says "Growth plan, billed monthly"; string metadata routed.

### Task 4: The daily renewal run
- `RenewalScheduler` service + `billing:process-renewals` (scheduled daily 07:00).
- Per tenant's latest subscription with provider_status active|past_due, tenant not complimentary, tenant package not free:
  1. pending downgrade to Free and period ended → switchToFree + ended mail.
  2. not due yet, non-reusable, 7 or 3 days before → `BillingNotifier::renewalDue()` (RenewalDueMail, pay link `billing.renew`).
  3. period ended, grace not set → attempt (reusable) or set past_due + grace (non-reusable, overdue reminder).
  4. past_due within grace → retry charge once per day (reusable) / overdue reminder daily (non-reusable).
  5. grace over → switchToFree + ended mail.
- `GET /billing/renew` (subdomain, auth, `billing.renew`): initialize a one-off charge with metadata `plan_renewal` for the current subscription, redirect to Paystack.
- Tests with `Http::fake` and `travelTo`: each branch; idempotent when run twice the same day; complimentary skipped; free skipped; charge failure email shows next retry; lapse after 7 days; renewal link redirects with correct metadata/amount.

### Task 5: Show it and control it
- Billing page: status (Active / Past due — grace ends {date}), renews/ends date, payment method label or "Renews by pay link", `Renew now` button when non-reusable within 7 days or past due.
- `billing:complimentary {tenant} {--off}`.
- AGENTS.md invariants; tests for props and command.

### Task 6: Verify and ship
- Full suite, Pint, PHPStan, lint/build; render new emails; deploy; prod: migration applied, purpledot complimentary, ugmc not, schedule registered; dry run `billing:process-renewals --pretend` output on prod.
