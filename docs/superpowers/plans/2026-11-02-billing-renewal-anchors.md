# Billing renewal calendar anchors

**Owner decision:** approved2November2026: preserve the intended billing day, clamp only invalid dates, then restore that day in later months. Existing invoices, captured amounts and historical periods remain unchanged.
**Goal:** monthly31January→28/29February→31March; annual29February→28February in non-leap years→29February in leap years.
**Execution:** native Laravel/Pest, one reviewed release. Parent: `2026-11-02-project-completion.md`, Phase5. Authority: approved decision above and AGENTS billing/finance invariants.

## Global constraints and interfaces

- Keep `SubscriptionRenewalService::recordRenewal` as the only renewal writer and preserve its original-reference idempotency, grace/lapse rules, package protection, pricing snapshots and encrypted authorisations.
- Store nullable anchor day/month on subscriptions. No eager backfill or retroactive financial migration. Legacy subscriptions without an anchor initialise lazily from their existing period end on their next legitimate renewal; an unknown historical day is never invented from account creation time.
- A genuinely lapsed subscription starts a new anchor today, matching its new period. An in-grace payment retains the old anchor/time. Preserve timezone and time-of-day.
- New first-party period calculations use one calendar helper with no-overflow dates and an explicit start. Persist its start-day/month with provisioning so the first February clamp does not permanently lose31January. Provider-supplied explicit period ends remain authoritative and are not rewritten.
- Preserve Stripe legacy paths and signed callback/webhook reference guards. No automatic catch-up charge or production data rewrite. Annual anchor must include month as well as day.
- Existing addon contracts are separate; audit and report them rather than silently changing addon billing policy under this subscription decision.
- Native migration/class generation, no dependencies, Pint dirty. Backend-only change needs no asset rebuild unless actual frontend source changes.

## Review Focus

1. A Feb28 end alone does not establish a Jan31 history. Existing legacy anchors must have a conservative fallback.
2. A monthly clamp must not lose its intended day on the next renewal; annual leap-day must not drift into March or permanently to28February.
3. Duplicate callback/webhook must neither reset the anchor nor extend twice. Failure rolls back anchors and transactions together.
4. Both first-payment callback and webhook, plus development bypass and legacy fallback calculations, must be audited. Preserve provider-explicit dates.
5. No mixed policy change to paid addon periods, old invoices, cancellation/grace or foreign-currency charging.

## Task1 — Reproduce month-end and leap-year defects

- [x] Read current subscription creation/renewal callers and existing tests, and record exact original-date sources before choosing helper parameters.
- [x] Add failing monthly Jan31→Feb→Mar regressions, leap/non-leap years, annual29February recovery and exact time preservation.
- [x] Add grace, duplicate-reference, lapsed restart and legacy-null-anchor cases. Keep original-reference/transaction assertions.
- [x] Run focused tests and observe failures caused by overflow/lost anchors, not fixture or missing-import errors.

## Task2 — Persist and calculate the approved boundary

- [x] Generate nullable day/month anchor migration; extend existing model casts/fillable and schema tests. Do not backfill existing rows.
- [x] Add small pure calendar helper using existing CarbonImmutable and bounded day/month. Test Jan30/31, ordinary month, leap year and annual month preservation.
- [x] Update legitimate renewal transaction to establish/preserve/reset anchors as specified and compute its end through the helper. Retain original amount/currency and idempotency.
- [x] Pass first-party new-period anchors through original payment provisioning, and use helper at its audited date-calculation callers. Preserve existing explicit provider ends and unchanged older contract paths.
- [x] Run renewal/provisioning/first-payment callback/webhook tests. No real provider charges.

## Task3 — Review and release

- [x] Run scoped static analysis, Pint dirty and affected Billing tests in the isolated test database; no acceptance database resets.
- [ ] Self-review all period writers, leap/grace/timezone/idempotency and transaction rollback touchpoints; one fresh independent review.
- [ ] Open scoped PR, require final-head CI, merge/deploy under standing implementation approval. No eager production anchor backfill.
- [ ] Read-only production migration verification and update parent plan with release evidence and any retained legacy/addon limits.

## Execution evidence and self-review — 2 November 2026

- Original renewal reproduction RED: Jan31→Mar3, Jan30→Mar2, leap-day→Mar1; legacy null anchor had no stored field. Pure-helper API tests also failed before implementation.
- Native artisan generated helper, nullable landlord migration and unit file. Helper has no dependency or mutable input state. Model stores paired day/month with a DB validity check; no historical-row backfill. Renewals preserve anchor during grace, reset it after genuine lapse, and retain original reference/amount/currency/authorisation logic.
- Modern callback/webhook and development first periods share `SubscriptionPeriod::starting`; provisioning persists only explicitly supplied anchors, preserving existing ones when provider-explicit dates arrive. Legacy Stripe explicit period ends stay authoritative. Old plan-code interval interpretation is retained; this does not redesign unsupported legacy subscriptions or addon contracts.
- Unit + focused renewal regressions15/15passed. Affected Unit/SubscriptionRenewal/SubscriptionProvisioning/Checkout/BillingEmails/PaymentReferenceConsistency/RenewalScheduler78/78passed,266assertions. Additional invalid-pair schema and Jan31 callback→webhook/webhook→callback across midnight7/7passed,19assertions. PHPStan changed model/controllers/services0errors; Pint dirty and source whitespace clean. No provider charges or assets changed.
- One initial pure-helper unit invocation omitted the dedicated DB environment; the repository's global Unit bootstrap uses its default disposable testing database. Subsequent invocations explicitly use `miconvener_s2b_codex_testing`. Acceptance payment/refund/payout records were re-read intact; no production command/test ran against production and no browsing database was reset. This deviation is recorded rather than claiming every test invocation was isolated correctly.

**Touchpoints reviewed:** nullable schema, casts/fillable, original renewal writer, first-payment provisioning, callback/webhook/development calculations, scheduler consumers, duplicate reference and receipt behaviour. No new permission, route, dependency, UI, gateway/account change, rate/fee change or new payment path.
**Ruling:** a legacy subscription with no provable anchor starts from its existing recorded end on its next legitimate renewal. Do not infer Jan31 from a Feb28 end or account creation date. Cost if wrong: historically lost intended days need an explicit evidence-backed correction, rather than silently revising old agreements.
**Ruling:** preserve provider-explicit dates and existing legacy interval interpretation; native one-off plan renewal policy is the approved scope. Addon calendar anchors are a separate audited follow-up. Cost if wrong: unsupported legacy/addon overflow remains to be investigated rather than being silently changed.
**User-facing gap analysis:** no new screen required; existing Billing dates and receipts reflect the stored end. Existing invoices/paid periods remain unchanged. No retroactive charges, invoice mutation or eager production subscription migration.
**Before release:** one fresh independent review and final-head CI, then migration/deploy and read-only schema verification.
