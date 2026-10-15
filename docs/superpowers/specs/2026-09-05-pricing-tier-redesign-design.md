# Pricing / Package Tier Redesign — Design Spec

**Date:** 2026-09-05
**Status:** Draft — awaiting review
**Origin:** Split off from roadmap item #11 (Finance/Settlement rigor) once that
conversation grew into "let's rethink pricing altogether" — kept as its own
initiative per explicit user direction ("Split them"). Informed by studying
Luma's and RSVPify's public pricing pages (research confined to those two by
explicit instruction).

## 1. Goal

Replace the platform's current *absence* of a real pricing/package model with
four subscription tiers (Free/Starter/Growth/Enterprise) that gate usage
volume and features, plus a per-tier default platform commission on paid
tickets that decreases at higher tiers — reusing the app's existing
Package/Feature/Subscription and Usage-metering infrastructure rather than
building a third parallel system alongside the two that already exist.

## 2. Confirmed starting conditions

These are verified facts about the current codebase, not assumptions — they
shape every decision below:

- The `packages` / `features` / `package_features` / `subscriptions` tables
  are real, already built, and currently seeded for an older meeting/AI-secretary
  product line (feature slugs like `meeting_recording`, `meeting_transcription`,
  `secretary_markers`). **Confirmed defunct** — no live tenants depend on this
  data (user-confirmed) — so it is safe to clear/replace, not just add
  alongside.
- **Correction to earlier research: the relational Package↔Feature
  system is NOT fully disconnected — it has one real, live bridge.**
  `Tenant.package_id` (migration
  `2026_01_17_132016_add_package_id_to_tenants_table.php`) is the live
  source of a tenant's current package, and
  `Tenant::syncFeaturesFromPackage()` (`Tenant.php:154-199`) already
  correctly reads each `Feature`'s `slug`/`type` and its
  `package_features.value` pivot and writes/updates the tenant's
  `TenantFeature` rows accordingly (`meta = {value, type, source:
  'package', package_id}`), disabling any previously package-sourced
  feature no longer in the new package.
  `App\Services\Billing\SubscriptionProvisioningService` (`provision()`,
  `upgrade()`, `downgrade()`, `switchToFree()`, `cancelAtPeriodEnd()`)
  already calls this on every real package-change path and logs a
  `PlanChange` row. **This mechanism is reused unchanged by this batch**
  — only the seed data changes (§6), not the sync code.
  What genuinely IS dead code: the `EnsureFeatureEnabled` /
  `EnsureFeatureLimit` middleware aliases (registered in Kernel but
  attached to zero routes) — a *separate*, unused enforcement path
  layered on top of the same tables, not the only bridge to them.
- The flat `TenantFeature` key/value store (read via `FeatureService` /
  `FeatureMeteringService`, table `tenant_features`) is what actually
  gates `custom-domains` today, checked in `OrgSettingsController.php:82`
  via `Facades\Feature::enabled()` — a tenant-context-scoped boolean-only
  check, distinct from `Tenant::canUse()`'s explicit-tenant,
  limit-aware check (§5 uses both, for different call sites).
- **Correction to earlier research on which seeder is live:** the
  seeder actually wired into `DatabaseSeeder.php` is `PackageSeeder`
  (config-driven from `config('product-page.plans')`), **not**
  `MasterFeaturePackageSeeder` — the latter is referenced only from
  tests (`tests/Feature/Billing/MasterFeaturePackageSeederTest.php`),
  never from the live seed path. §6's reseed replaces `PackageSeeder`'s
  entry in `DatabaseSeeder.php`, not `MasterFeaturePackageSeeder`'s.
- There are, in fact, **two** separate live limit/metering systems, not
  one, and they are not interchangeable:
  - **System A** — `TenantFeature` (table `tenant_features`: tenant_id,
    feature_key, enabled, meta jsonb with `type`/`value`) +
    `TenantFeatureUsage` (table `tenant_features_usage`: tenant_id,
    feature_slug, period_start, period_end, used_count) +
    `Tenant::canUse()` / `Tenant::recordUsage()`
    (`app/Models/Tenant.php:250-305`) + `FeatureMeteringService`. This is
    the system that **actually backs the LLM-token-quota/topup feature**
    end-to-end (`LlmUsageService::canConsume()`/`record()`, gated live in
    production by `EnsureTenantHasLlmTokens` on `routes/api.php:28`).
    `canUse()` compares a lifetime `used_count` against the feature's
    configured `meta.value` limit; periodic reset is a scheduled command
    (`ResetTenantUsageCommand`) that deletes usage rows, not
    period-boundary tracking.
  - **System B** — `UsageEvent` / `UsageLimit` / `UsageRollup` /
    `UsagePrice` (all keyed by the `UsageMetric` string-backed enum) +
    `PricingService`. `App\Enum\UsageMetric` defines 16 cases, but
    `EnforceUsageLimits` middleware — the only live consumer — checks
    **exclusively** `REQUEST_COUNT`, hardcoded; it does not loop over
    other metrics. `EMAIL_COUNT` and the rest of the enum are defined but
    nothing ever emits or enforces them. `PricingService` is a cost
    *calculator* (turns a usage quantity into a $ amount for
    billing/overage), not a limit gate.
  - The dead `EnsureFeatureLimit` middleware (§ below) is a *third*,
    unused code path that also calls into System A's `canUse()` via a
    generic route-param feature slug — confirmed attached to zero routes.
- `platform_fee_percentage` exists as a column on both `Tenant` and `Event`.
  `Event::effectivePlatformFeePercentage()` resolves event → tenant → null.
  The only writer today is the CLI command `events:set-platform-fee`,
  explicitly commented in `EventController.php` as "superadmin-only for now."
- SMS sending does not exist anywhere in the codebase — no driver, no
  config entry, no service class. The only hits are UI label text.
- Custom domains are real and fully functional (DNS verification flow,
  health check), gated via the live `TenantFeature` flag.
- The speaker and participant portals
  (`PublicEventController::attendeePortalPreview` /
  `speakerPortalPreview`) are static design previews only — explicitly
  commented as "not wired to a real registration/speaker yet."
- Outbound webhooks exist (`WebhookEndpoint`, `WebhookCall`,
  `SendWebhookJob`) but have no tenant-facing management UI and are wired
  to exactly one event type (`InvoiceIssued`) today.
- White-labeling today is email-sender-name and footer-text only. No
  branding control exists on public event/registration pages.

## 3. Explicitly out of scope

Several cells in the approved tier table (see §4) name features that don't
exist yet. This spec defines where they *will* plug into the gating
architecture, but does not implement them — building the underlying feature
is a separate, later batch in each case:

- **SMS credits.** Gate cannot be implemented until SMS sending itself
  exists. This spec reserves the feature key name (`sms_credits`, a
  System A `TenantFeature` slug — see §5) but implements no SMS-sending
  code and no SMS enforcement.
- **Speaker/participant portal white-labeling by tier.** The portals
  aren't live features yet (static previews only). Tier-based
  customization of something that doesn't exist isn't a gating problem to
  solve now — deferred until the portals themselves are built.
- **Premium badge template tiers.** Badge printing exists as a feature
  already; "basic vs. premium templates" is a badge-template-library
  design task, not a gating-infrastructure task. This spec reserves a
  feature-flag name but does not design the template library.
- **"Full exports + dashboards" reporting.** CSV export already exists
  today (the settlement statement export from batch #11's
  `EventFinanceController`) and is gated as a simple boolean flag in §5.
  A richer analytics/dashboards reporting feature does not exist yet —
  the Growth/Enterprise "dashboards" cell in §4 is aspirational until
  that feature is built; only the CSV-export gate is implemented by this
  batch.
- **Self-service webhook/API management.** The underlying webhook models
  exist but there's no tenant-facing UI to create/manage endpoints.
  Gating "API access" by tier is meaningful only once that UI exists —
  deferred. This covers both the Growth tier's "Limited" webhooks cell
  and Enterprise's "Full API" cell in §4; neither is implementable today.
- **Single sign-on (SSO).** No SAML/OIDC integration exists anywhere in
  the codebase — the app's only auth stack is Laravel Breeze. The
  Enterprise-tier SSO cell in §4 is aspirational until an SSO integration
  is built as its own batch; this spec does not design one.
- **Support-tier differentiation** (Help center → Email → Priority+chat →
  Dedicated manager). This is an ops/support-routing concern — which
  queue or contact a tenant's ticket lands in — not something enforced in
  application code. No gating mechanism is designed for it; it's a
  process decision for whoever operates support, once tiers exist to key
  off of.
- **Final USD/GHS pricing figures and commission percentages.** The
  numbers in §4 are a working engineering draft, not a confirmed business
  decision — pricing itself is not re-litigated in this document.
- **Deleting the old meeting-product `Feature` rows.** Confirmed safe to
  leave inert (no live dependents); no cleanup task is included in this
  batch, to avoid unrelated scope creep.

## 4. Tier definitions (as approved in discussion)

| | **Free** | **Starter** | **Growth** | **Enterprise** |
|---|---|---|---|---|
| Price (monthly / annual-equiv) | $0 | $29 / $22 | $99 / $75 | Custom |
| Concurrent live events | 1 | 3 | 15 | Unlimited |
| Registrations/month | 100 | 500 | 3,000 | Custom |
| Team seats | 1 | 3 | 10 | Custom |
| Email credits/month | 200 | 1,000 | 10,000 | Custom |
| SMS credits/month | 0 | 100 | 1,000 | Custom |
| Document/materials storage | 500MB | 5GB | 50GB | Custom |
| Badge printing | Basic template | Basic templates | Premium templates | Custom-branded |
| Speaker/participant portal | Platform-branded | Platform-branded | Custom branding | Full white-label |
| Remove platform branding | No | No | Yes | Yes |
| Custom domain | No | No | Yes | Yes |
| Reporting/exports | No | CSV only | Full exports + dashboards | + API access |
| Integrations/webhooks | No | No | Limited | Full API + SSO |
| Support | Help center | Email | Priority + chat | Dedicated manager |
| Platform commission on paid tickets | 5% | 3.5% | 2% | Negotiated |

## 5. Architecture: everything on System A, System B untouched

Every new tier gate — boolean and volume alike — is built on **System A**
(`TenantFeature` + `TenantFeatureUsage` + `Tenant::canUse()`/`recordUsage()` +
`FeatureMeteringService`), the one proven end-to-end by the LLM-quota
feature. System B (`UsageLimit`/`UsageEvent`/`PricingService`) is not
extended by this batch — it would need net-new per-metric enforcement logic
it doesn't have today (§2), and System A already does exactly what's needed.

Within System A, two different call patterns are needed, because the tier
table mixes two different kinds of limit:

- **Boolean toggles** (`meta.type = 'boolean'`) — branding removal, custom
  domain access, CSV settlement-statement export, white-label (once
  built), SSO (once built), API access (once built). Checked from web
  controllers with `Facades\Feature::enabled($featureSlug)` — the exact
  tenant-context-scoped, cached pattern `custom-domains` already uses in
  `OrgSettingsController.php:82` — not `Tenant::canUse()`, which is
  reserved for the limit-aware checks below and requires an explicit
  `Tenant` instance rather than resolving the current tenant from
  context.
- **Monotonic-with-periodic-reset limits** (`meta.type = 'limit'`) —
  registrations/month, email credits/month, SMS credits/month (reserved).
  These reuse `canUse()`/`recordUsage()` unchanged (lifetime
  `used_count` vs. `meta.value`), with the existing
  `ResetTenantUsageCommand` extended to also clear these new feature
  slugs on a monthly schedule — the same reset mechanism the LLM feature
  already relies on, not new period-boundary tracking.
- **Concurrent/live-state limits** (a new third pattern this batch adds
  — not needed by LLM quota) — events-in-flight, team seats. These are
  **not** counted via `recordUsage()` at all, because there's no
  corresponding decrement when an event is archived or a seat is
  removed. Instead, a new public read accessor,
  `Tenant::featureLimitValue(string $featureSlug): ?int` (extracting the
  same `meta.value` lookup `canUse()` already does internally, minus the
  usage-counter comparison), lets a call site compare a **live COUNT
  query** against the configured limit at the moment of creation (e.g.
  `EventController::store()` counts the tenant's current non-archived
  events before allowing a new one; the team-invite action counts active
  users). Returns `null` when the feature is disabled/unset, meaning
  unlimited.

`Package` / `Feature` / `package_features` remain the **catalog /
template**, not a new enforcement path: a `Package` row defines what a
tier includes, and assigning a tenant to a package already
writes/updates the tenant's `TenantFeature` rows via the existing
`syncFeaturesFromPackage()` (§2) — no new assignment mechanism is built.
The dead `EnsureFeatureEnabled` / `EnsureFeatureLimit` middleware aliases
are **not reactivated** — doing so would add a second, redundant
enforcement path on top of the one this batch relies on. Whether to
remove the dead aliases entirely is left to a later cleanup batch, not
this one.

## 6. Package reseed

The old meeting-product `Package` / `Feature` / `package_features` rows are
confirmed safe to clear. A new seeder, `EventPackageSeeder`, **replaces
`PackageSeeder`'s entry** in `DatabaseSeeder.php` (§2 — `PackageSeeder`,
not `MasterFeaturePackageSeeder`, is the one actually wired into the live
seed path) with four `Package` rows — Free, Starter, Growth, Enterprise —
each row's `package_features` pivot encoding the boolean flags and
usage-limit values from §4, keyed by feature slugs. `is_free = true` on
the Free package (required by `SubscriptionProvisioningService::switchToFree()`
/ `cancelAtPeriodEnd()`, which look up `Package::where('is_free', true)`).
`CustomDomainFeatureSeeder` / `ChatFeatureSeeder` / `WebhookFeatureSeeder`
are left untouched (out of scope, §3) — `custom-domains` is included
explicitly in the new seeder's own Growth/Enterprise feature lists
regardless, so no reliance on those seeders' side effects is needed.

## 7. Commission-by-tier

`Package` gets a new column, `default_platform_fee_percentage`. Rather than
copying this value into `tenants.platform_fee_percentage` on assignment
(which would make it indistinguishable from a real manual override, and
go stale the moment a tenant changes package), `Event::effectivePlatformFeePercentage()`
gains one new fallback rung, read live from the tenant's current package:

`event override → tenant manual override → tenant's package default → global config default`

i.e.:
```php
public function effectivePlatformFeePercentage(): float
{
    return (float) ($this->platform_fee_percentage
        ?? $this->tenant?->platform_fee_percentage
        ?? $this->tenant?->package?->default_platform_fee_percentage
        ?? config('services.platform.default_fee_percentage'));
}
```
The existing `events:set-platform-fee` CLI command is completely
unaffected — it continues to write only the tenant/event-level manual
override, which still wins outright over the package default.

## 8. Add-on credit-pack store (sketch only)

Full design deferred. The intended shape mirrors the existing LLM-token
top-up flow the app already has proven: `llm_topup_balance` is a plain
integer column on `tenants`, credited by `CallbackController` on successful
payment and drained by `LlmUsageService` once the `TenantFeature` quota is
exhausted (all System A, §5) — email/SMS credit top-ups would follow the
identical pattern (an `email_credits_topup_balance` column, drained the same
way), not the `UsagePrice`/`PricingService` cost-calculator (System B),
which this batch does not touch. Not designed in detail here because the
underlying `email_credits` metering isn't wired yet (§5 is a prerequisite)
and SMS doesn't exist at all yet (§3) — this batch's job is to make the
underlying metering real so a future top-up UI has something real to drain
against.

## 9. Migration/rollout

- Because the old meeting-product package data is defunct and no formal
  event-platform package system has existed until now, every current
  tenant is effectively unassigned (or assigned to a stale
  `PackageSeeder`-created package). All existing tenants are backfilled
  onto the new **Free** package via
  `SubscriptionProvisioningService::upgrade($tenant, $freePackage)` — the
  existing, already-tested method that sets `package_id` and calls
  `syncFeaturesFromPackage()` in one step, run for every tenant as a
  one-off Artisan command or migration data step, not new bespoke logic.
- Any tenant that should be grandfathered onto a higher tier (e.g. an
  existing customer already running larger paid events) needs an explicit
  manual call to the same `upgrade()` method with the appropriate
  package — this is an ops/business decision, not something this
  migration resolves automatically.
- `platform_fee_percentage` values already set via the CLI for existing
  tenants/events are untouched by the reseed — they remain the
  highest-precedence override per §7.

## 10. Testing approach

- Seeder test: running `EventPackageSeeder` produces exactly four
  `Package` rows with the correct `package_features` pivot values per §4.
- Assignment test: assigning a tenant to a `Package` (via `Subscription`)
  correctly writes/updates the corresponding `TenantFeature` rows for
  that tenant (booleans and `meta.value` limits alike).
- Metering test: sending a tenant email actually calls
  `FeatureMeteringService::recordUsage($tenant, 'email_credits')`
  (currently never invoked for mail — this batch closes that gap), and
  `Tenant::canUse('email_credits')` correctly returns false once the
  monthly limit is reached.
- Concurrent-limit test: creating an event beyond the tenant's
  `events_in_flight` limit (read via `Tenant::featureLimitValue()`) is
  rejected with a clear error, and succeeds again once an existing event
  is archived — proving this gate reads live state rather than a stale
  counter.
- `featureLimitValue()` unit test: returns the configured integer for an
  enabled limit-type feature, `null` for a disabled or unset feature
  (meaning unlimited), covering both branches explicitly.
- Precedence test: `Event::effectivePlatformFeePercentage()` returns the
  event override when set, else the tenant manual override, else the
  package default, else the global config default — each rung tested with
  the others absent.
- Regression: existing `EnsureFeatureEnabled`/`EnsureFeatureLimit` dead
  aliases remain unregistered on any route (a `Kernel.php` assertion or
  route-list-based test forbidding new attachments — this batch must not
  accidentally revive them).
