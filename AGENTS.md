<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5.0
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/breeze (BREEZE) - v2
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- laravel/telescope (TELESCOPE) - v5
- pestphp/pest (PEST) - v5
- phpunit/phpunit (PHPUNIT) - v13
- rector/rector (RECTOR) - v2
- alpinejs (ALPINEJS) - v3
- eslint (ESLINT) - v10
- laravel-echo (ECHO) - v2
- prettier (PRETTIER) - v3
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `livewire-development` — Develops reactive Livewire 4 components. Activates when creating, updating, or modifying Livewire components; working with wire:model, wire:click, wire:loading, or any wire: directives; adding real-time updates, loading states, or reactivity; debugging component behavior; writing Livewire tests; or when the user mentions Livewire, component, counter, or reactive UI.
- `pest-testing` — Tests applications using the Pest 4 PHP framework. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, browser testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, or needs to verify functionality works.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd and will be available at: `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs for the user.
- You must not run any commands to make the site available via HTTP(S). It is always available through Laravel Herd.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- This project upgraded from Laravel 10 without migrating to the new streamlined Laravel file structure.
- This is perfectly fine and recommended by Laravel. Follow the existing structure from Laravel 10. We do not need to migrate to the new Laravel structure unless the user explicitly requests it.

## Laravel 10 Structure

- Middleware typically lives in `app/Http/Middleware/` and service providers in `app/Providers/`.
- There is no `bootstrap/app.php` application configuration in a Laravel 10 structure:
    - Middleware registration happens in `app/Http/Kernel.php`
    - Exception handling is in `app/Exceptions/Handler.php`
    - Console commands and schedule register in `app/Console/Kernel.php`
    - Rate limits likely exist in `RouteServiceProvider` or `app/Http/Kernel.php`

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces using only PHP — no JavaScript required.
- Instead of writing frontend code in JavaScript frameworks, you use Alpine.js to build the UI when client-side interactions are required.
- State lives on the server; the UI reflects it. Validate and authorize in actions (they're like HTTP requests).
- IMPORTANT: Activate `livewire-development` every time you're working with Livewire-related tasks.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.
- CRITICAL: ALWAYS use `search-docs` tool for version-specific Pest documentation and updated code examples.
- IMPORTANT: Activate `pest-testing` every time you're working with a Pest or testing-related task.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>

# MiConvener finance invariants

These are enforced by tests. Breaking one is a bug, not a style choice.

- **Money is integer minor units (pesewas).** Percentages are the only non-integer money-adjacent values.
- **Community dues use `CommercialTerms`.** Superadmin-only per-stream overrides precede plan defaults (paid 1%, Free or no package 2%). Checkout freezes the rate, charge set, currency, amount and commissions in `DuesPaymentAttempt`, bound to our Paystack reference; confirmation validates that snapshot. Only legacy payments without an attempt use the legacy 1% rate. Tickets retain `PlatformFeeResolver`; giving and contributions overrides are future configuration until those streams are wired explicitly.
- **Dues Finance separates flows and stocks.** Reports read stored paid-charge amounts and fees per currency in organisation-local payment dates; current overdue charges and `DuesBalance` remain independent of that filter. Recorded offline cash appears in collections only, posts no ledger and never increases payable. Paid-charge history keeps Finance reachable when Community is off, including cash-only organisations. Dues own-gateway setup is Paystack-only under `manage payment settings`; Stripe remains ticket-only.
- **Dues generation preserves history.** `dues:process` works in organisation-local dates, catches up bounded missing periods, and never revives a void or overlaps an existing non-void period. Exit/lapse voids unpaid advance periods; paid and historical charges remain. Rejoin/reactivation starts next period even when initial joins include the current period.
- **Dues reminders reserve before delivery.** Automatic and manual reminders share `(member_id, channel, organisation-local date)` uniqueness. SMS uses `SmsAllowance::withLock`/`reserve` and exact `PrepaidCreditReceipt` sources; `consume` and monthly resets share that credit lock. Count both reserved and accepted SMS against the Community monthly cap. Confirmed refusals refund once; unknown/interrupted outcomes stay reserved for reconciliation. Email is transactional and unmetered. Arrears preview/send needs both `manage members` and `read finance`, checked again by each bounded channel delivery job. Audience dispatchers enqueue independent continuations; transport jobs must finish below queue retry-after and retries may claim only untouched recipients. Freeze each queued run’s local day before serialization and preserve it across retries/continuations; stale manual and automatic units stop, while a fresh manual request gets its own current day. Shared family contacts still receive one transactional reminder per member. Future Community messaging must reuse this organisation SMS cap and lock, including existing reminder reservations.
- **Commission terms resolve through one cascade:** event → tenant → package default → `config('services.platform.*')`. Use `PlatformFeeResolver` / `FeeCalculator`; never compute `amount * pct / 100` inline. A stored `0.00` percentage is a waiver. **No plan caps ticket commission** (`default_platform_fee_cap_amount` is null on every package); a cap or a different rate is a deal for one organisation, set on its console page.
- **Only platform staff with `manage commercial terms` set commission percentage or cap, or grant the Ghana price** — the `manage-commercial-terms` gate (the permission held through a platform role, null `tenant_id`; Superadmin by default, grantable to another platform role, never tenant-safe), or `php artisan events:set-platform-fee`. Never authorize by email address: tenant admins control team members' emails and `users.email` is unique globally. Tenants may set only `fee_bearer`.
- **`LedgerService` is the only writer of both ledgers.** Never call `EventLedgerEntry::create` from a controller, command or job — a test scans for it. `EventLedgerEntry` is the flat settlement-statement line organizers export; `LedgerTransaction`/`LedgerEntry` is the double-entry ledger.
- **Ledger posting is idempotent by reference** (unique per tenant + reference + type). Re-posting a reference returns the existing transaction.
- **The gateway fee is booked at sale** (clearing is debited with the cash that actually arrives), so `organizer_payable` clears to zero on payout. The transfer fee is passed through: the organizer is sent `amount − fee`, and it is recorded only when the transfer is actually released.
- **Paystack per-transfer OTP is on.** A transfer answering `otp` parks the payout in `awaiting_otp` and is released via `finalizePayout`; a parked payout must never be re-sent.
- **Permissions reach every environment on deploy.** The Laravel Cloud deploy command runs `php artisan migrate --force && php artisan db:seed --class=PermissionsSeeder --force`. The seeder grants a permission to the built-in roles only on the run that creates it, so re-running never undoes a permission an administrator removed. Add a permission to `PermissionsSeeder` and `RolePermissions` together; a test fails if code checks a permission the seeder does not define.
- **Console components drop props they don't declare.** `Modal`, `ConfirmModal` and most console primitives destructure a fixed prop list — `isOpen`, `onCancel`, `message` and similar are silently ignored, which is how whole dialogs shipped unable to open. `Button` has only the variants in its `VARIANTS` table and no `size`. `ConsoleComponentUsageTest` enforces this.
- **Commission rates are not public.** The public event payload includes the rate only when the attendee is the one paying it.
- **A platform payment is keyed on its Paystack reference, everywhere.** The browser callback and the webhook both report every payment, in either order. Fulfilment, provisioning and `transactions.provider_transaction_id` (unique) all use the reference string — never Paystack's numeric `id`, which is how one token pack used to be credited twice. `SubscriptionProvisioningService::provision()` returns `false` for a reference already recorded.
- **Plans renew through `billing:process-renewals`, not Paystack subscriptions.** Plan payments are one-off charges; the daily run (07:00) charges a stored authorization only when Paystack marked it `reusable`, emails pay links (`/billing/renew`) otherwise, gives 7 days' grace, then moves the tenant to Free. Paystack subscriptions are card-only and customer mandates are Nigeria-only direct debit, so neither covers Ghana mobile money. `SubscriptionRenewalService::recordRenewal()` is the only writer of a renewal and extends from the old period end. `authorization_code` is encrypted and hidden; never log it. `tenants.billing_complimentary` (set with `billing:complimentary`) exempts a tenant.
- **Paystack metadata may arrive as a JSON string.** Read it through `Billing\WebhookController::metadata()`; casting it with `(array)` makes a renewal look like another application's event.
- **A plan change never breaks a live event.** `Tenant::booted()` calls `LiveEventProtection` whenever `package_id` changes. On a downgrade or lapse, every published, not-yet-ended event is grandfathered (`grandfathered_at`): it keeps registering past the smaller plan's ceiling, keeps selling paid tickets, and stays editable (existing paid tickets only) until it ends. Any live event whose commission, cap or bearer would get worse keeps its terms (`terms_locked_at` + `locked_*`); an event's own superadmin override still wins; upgrades lock nothing. Finance, refunds and payment settings use `Tenant::handlesTicketMoney()` (plan sells paid tickets, or the tenant has paid events), never `planAllows('paid_tickets')` alone.
- **Billing is the owner's.** `manage billing` (Superadmin, Org Superadmin only; not in `Permission::TENANT_SAFE`, so custom roles can't be given it) gates the billing and pricing pages, plan/token/renewal checkouts, invoices and `SubscriptionManager` actions. The Paystack callback is deliberately ungated: it records money already taken. The console hides billing UI via `auth.can.manage_billing`, but every route checks the permission itself.
- **Platform prices go through `PriceBook`, and Paystack is always charged in GHS.** The platform account settles cedis only. A price is shown in GHS or USD (`VisitorCurrency::resolve`); a USD price is converted by `ChargeQuote` at the Bank of Ghana rate (`ExchangeRates`: cached, stored, alert after 4 days, USD paused after 10) and the quote is kept in the transaction `meta.pricing`. Never initialise a USD charge, and never read a currency or price from the request.
- **Nobody can choose the Ghana price.** Display follows the organisation (`pricing_region`: `GH` eligible, `INTL` settled in USD) or, for a visitor, their country (`CF-IPCountry`); a cookie can ask only for USD. `GhanaEligibility` is the one rule for earning it: a verified Ghana payout account, a Ghana mobile-money or Ghana-card payment (`meta.payment_origin`), or a superadmin grant; an organisation settled in USD is upgraded only by a payout account and an automatic grant never undoes a superadmin removal. A GHS price charged to an organisation that is not eligible carries `CheckoutTerms` (mobile money and card only, proof required); `GhanaPaymentProof` settles it from both the callback and the webhook, once, and tops a foreign card up to the USD price. Renewals follow `Subscription::renewalCurrency()`.
- **Staff-only rooms are hidden by a global scope.** `StoreListing` carries `StaffOnlyScope`, so every query excludes `audience = staff` unless it opts in (`withStaffOnly`, `visibleToStaffOf`, the console route binding). Never add a public query that bypasses it, and keep `tests/Feature/Venue/StaffOnlyRoomsAreHiddenTest.php` covering every public path. Internal (staff) bookings are `VenueBooking` rows with `kind = internal`: free, no ledger entry, the same availability check as paid bookings (`VenueAvailabilityService`), and the public booking pages serve `kind = marketplace` only. Staff are tagged members or verified addresses at a DNS-proven domain (`StaffIdentity`); the feature is `staff_room_booking` (Enterprise) and the permission `manage room bookings`.
- **Venue calendar writers share one lock order.** Anything that creates, re-times, re-statuses or deletes a `VenueBooking` (public requests, host holds/quotes/declines, blackouts, staff requests, approval transitions, legacy payment confirmation) runs through `VenueCalendarLocks`: listing row first, then at most one booking row, re-reading state and availability under the lock. Never check availability before the lock and never lock a booking first. A test in `tests/Infrastructure/Marketplace/VenueCalendarConcurrencyTest.php` races real PostgreSQL workers; extend it for any new writer.
- **Approval-first venue requests hold nothing until a host approves.** `venue_bookings.approval_flow_version` marks the flow (null is legacy). A `pending_host_approval` request never blocks the calendar; an `approved_hold` blocks only while `hold_expires_at > now()` (exact deadline, no scheduler). Transitions go through `VenueBookingApprovals` with an operation key, leave immutable `venue_booking_actions` evidence (a trigger forbids edits except email-delivery columns) and cannot be paid: legacy checkout and `confirmBookingPayment` refuse approval-flow rows. A late legacy payment that cannot take its dates becomes `payment_calendar_conflict` evidence, never a confirmation. The `marketplace_venue_requests` feature is explicit per organisation (`php artisan venue:enable-requests {tenant}`), never inherited from a plan.
- **Billing emails go through `BillingNotifier` only.** Receipt, payment failed, plan ending and plan ended are sent to the payer and `tenants.email`, once per `(type, dedupe_key)` in `billing_emails`. Call the notifier from every path that sees the event; the guard makes repeats a no-op. They are transactional: not metered against email credits, no opt-out.


# Portal session safety

All stateful web requests use Laravel's native session blocking, backed by the shared landlord `portal_session_locks` table through the lock-only `portal_sessions` cache store. Do not narrow blocking to `/my`: an ordinary concurrent request can otherwise restore proofs after sign-out. The lock lease is 600 seconds; longer work belongs in a queue, and the session driver must not be `cookie`. Portal proof/grant rotations and identifier removal use `regenerate(true)` to destroy the old persisted record while retaining unrelated staff authentication attributes. Test persisted-cookie replay and an overlapping writer, not only in-memory marker removal. Existing unknown aliases created before this protection are not retroactively enumerated or revoked; existing email proofs still expire on their fixed 12-hour boundary.

# Giving contracts

- Giving never requires or creates members, membership types, dues, events, followers or inbox recipients. Money stays in integer minor units and currency is never converted implicitly.
- `GivingGift` is for standalone funds; event-origin Giving keeps its existing `EventContribution` and a typed link. Historical associations never rewrite source/ledger/payout rows. A private event fund is unique per tenant/event/original currency. Only explicitly approved post-boundary starts use the Giving category.
- Public `public.giving.*` routes are accessible to signed-in staff from other organisations without signing them out. Console membership checks still protect tenant routes; Giving private receipts/reminders retain their separate capability, verified-email and consent guards.
- Freeze original provider/account/reference/terms at checkout. `GivingPaymentConfirmer` and `LedgerService` settle once; original-account verification continues after flags/account settings change. Unknown charges/refunds/transfers are reconciled, never blindly repeated. A recovered OTP transfer must match its original reference, requested net and currency and remain parked until finalised.
- Cash updates progress and contribution records only. Corrections append; cash never enters either ledger or platform payout availability. Optional member attribution requires `manage members` as well as `manage giving` and `read finance`; it does not identify the payer. Raw names/shared phones never merge givers automatically.
- Giving refunds reverse the original commission cumulatively. Own-account rebates credit only cash still held as Giving payable; only consumed `CommissionCredit` is restored. Never grant reusable credit and payout cash for the same rebate. Negative payable freezes further payouts and raises operational review.
- Private history uses exact independently verified recorded email or existing member grants for explicit attribution; no phone search. Guest capabilities open one receipt/result, not `/my`, and are bound to proof context and a12-hour maximum. Financial responses are private/no-store and never added to the service worker cache.
- `GivingReceipts` captures an immutable encrypted typed-source snapshot inside confirmation/recording and dispatches after commit. Receipts are unmetered. Optional reminders require verified email plus fresh purpose consent and honour marketing opt-out, fund availability and Giving flags; use metered email only, no automatic charges/SMS. A scanner GET never unsubscribes; confirmation POST does. Uncertain marketing sends stay reserved rather than replaying.
- Giving screens/cash, online readiness and historical event cutover are separate operations. Keep commands dry by default and tenant-bounded. Never apply production association/cutover, enable online payments or alter settlement mode under a generic UI rollout approval.

# Shared commerce invariants

- New store money uses `LedgerCategoryEnum::Commerce`, event=null. Legacy `Marketplace` balances never fund store payouts or refunds. `LedgerService` remains the only ledger writer.
- `StoreCommercialTerms` requires a versioned global-superadmin agreement and original platform-account identity. Catalog and checkout switches are separate; neither bypasses supported settlement, currency or terms. Community membership and dues are not prerequisites for public products.
- Preserve original verified-buyer email ownership for `/my/store-orders` and receipts; phone proof, following, changed member contacts and representative access do not grant order history. Use explicit DTOs/no-store; never cache store records in ticket offline storage.
- Quote retries preserve the original ten-minute window. `StoreQuoteSubmission` retains request-key aliases, including changed-key retries of an identical basket. Canonical lock order is tenant, order, then sorted inventory variants; fulfilment uses it and refuses refund-pending/review states.
- Persist original-account payment intent before provider HTTP, append verified capture evidence before final posting, and atomically consume stock with the ledger. Provider calls stay outside business locks. Missing fees, expired holds, extra captures and changed financial evidence require reconciliation, never an invented paid flag.
- Commerce refunds and payouts use stable operation/reference identity and protect refund liabilities/approved reserves. Unknown provider outcomes and parked OTP transfers must never be blindly resent. Retain payout destinations and immutable financial history even after an account edit or a failed transfer.
- `StoreReceipts` captures one immutable payment record and durable outbox per confirmed order, including free orders. Dispatch failure and abandoned worker claims are recoverable; SMTP ambiguity may duplicate delivery, never a receipt/order. Transactional receipt delivery follows Giving's unmetered policy and ignores marketing opt-out. Keep provider responses, OTPs and private checkout URLs out of diagnostics.
- `StoreFulfilmentOptions` records append-only shop offers and immutable buyer-selected collection/delivery arrangements. Delivery is included in the displayed price for S1c; no separate fee/COD. Destination is encrypted and available only to the original verified buyer or authorised staff on a selected order, never list DTOs, receipts or readiness mail. A quoted choice change clones the quote without extending its original stock deadline; accepted methods/addresses never silently change.
- `StoreFulfilment`, `StoreDeliveries` and `StoreCollections` use canonical tenant/order locks and exact `commerce_collection` plus `manage store collection`; a missing flag is off. Collection staff gain no finance/order-management access. Completed-operation retries still require current role/ownership and the original consumed proof/key, even while paused.
- Ready orders have no automatic expiry. A private handover pass starts its own 15-minute window only on explicit buyer request. Collection needs ready; delivery needs dispatched. Passes use separate non-URL QR purposes, bounded manual guesses/rotation, hashed credentials and encrypted short-lived replay material. Never put them in URLs, telemetry, email, initial Inertia props or offline caches. Reversal/refund/failed delivery/physical return revokes proof; daily purge clears secret material while retaining hashes and audit.
- Whole-order handover is recorded once, atomically with proof consumption. It never changes stock or posts a ledger. Dispatch is not delivery. Primary refunds/free cancellation cannot restock goods in transit or already handed over; a late provider result still records money but preserves custody and flags review. A failed attempt stays dispatched; only confirmed physical return moves back to ready without restocking. Extra-capture recovery remains independent.
- `StoreFulfilmentNotices` retains one encrypted readiness notice per immutable ready event, independently of payment receipts. SMTP/queue failure never rolls back readiness. Recheck current generation before a send; stale unclaimed notices become superseded. Five actual attempts maximum, ten-minute abandoned-claim recovery and a five-minute sweep; stable Message-ID, transactional email only, no SMS or marketing-credit path. Already claimed SMTP cannot be recalled; the native order link always shows current status.
- Commercial agreements carry an immutable `offering_scope` (`purchase` or `reservation`) sharing one per-shop version sequence. `StoreCommercialTerms::resolve/approve` default to purchase; merchandise payout holdback and the admin agreement list read purchase only, so a venue agreement can never change Purpledot merchandise terms. A reservation order must rest on a reservation agreement (composite key).
- Venue contract records (`VenueFinancialQuote`, `VenueFinancialDecision`, `VenueBookingCharge`, `VenuePaymentResolution`) are append-only by trigger; the settlement reserve releases once, only by a completion decision. Claim every provider reference through `CommercePaymentReferences`; money the older `MKT-` venue path took or posted is never claimed as a new sale.
- Shared money paths lock orders through `CommerceOrderLocks`, which picks the order by persisted type: purchase is tenant then order (then variants); reservation is tenant, listing, booking, charge, order. Calendar-only writers take listing then booking and never a tenant lock after it. `CommerceInventoryLocks::order` refuses reservation orders, so purchase-only code fails closed. What differs per kind lives in a `CommerceOffering` (`PurchaseOffering`, `VenueOffering`) resolved by `CommerceOfferingRegistry` from the stored type; never add `if venue` branches to the shared services. `marketplace_venue_payments` gates only new venue checkouts; money already taken is always recorded.
- S1b does not enable collection handover or production trading. Provider sandbox actions, named pilot, numerical commercial terms and production switches require separate approval; the Community Purpledot rollout instruction does not enable Commerce.

# Current work: Community

Before starting work on members, dues, giving, following, `/my` or community messaging, read `docs/superpowers/community-roadmap.md`. It holds the milestone status table, links to the spec and plans, the rules for continuing, and the briefs for milestones without a plan. Tick plan steps (`- [ ]` to `- [x]`) in the same commit as the work, and update the roadmap's status table when a milestone changes state.

**Standing pilot policy (owner, 2026-10-16):** Always enable newly approved and deployed Community milestones for `purpledot` as part of the release process. Do not ask again for Purpledot pilot activation. Keep rollout switches for other organisations unchanged, preserve plan entitlements, and verify the enabled organisation after activation.

**Community content (milestone7):** Library/service notes and organisation questionnaires use their own tables; never widen EventMaterial/EventPoll identity or registration gates. Current active-member/group access is independent of dues; representatives read only and cannot answer. Files have immutable private disk/path ownership, authorised no-store streams and stable per-save write keys. Community/app plus distinct library/polls rollout and plan entitlements gate new starts; authorised retained history survives slice downgrade. Keep private content out of the ticket-only offline cache. Questionnaire definitions freeze after the first uniquely member-scoped response; identifiable results need their separate staff permission, and member results are explicitly published aggregates only.

# Shared Paystack account relay

The existing platform webhook remains the account entry point. After signature verification, `metadata.application=udworkspace` plus `charge.success` relays the exact original body/signature to the fixed HTTPS UDWorkspace invoice receiver. Never accept a destination from payload metadata or follow redirects. Forwarding is bounded (3s connect/15s total); only destination HTTP 200 acknowledges receipt. Non-200/connection failure returns 502, or 503 when `PAYSTACK_UDWORKSPACE_RELAY_ENABLED=false`, so Paystack retries. UDWorkspace independently verifies provider amount/currency/invoice/tenant and deduplicates payment/receipt creation. No MiConvener fulfillment should run for relayed charges. Logs contain attempt UUID, SHA-256 and result/target UUID only; no payload, signature, contact details or exception message. Exclude these inbound paths and the outgoing relay from Telescope body recording. A controlled replay proves routing and deduplication, not provider-origin delivery.

# Unified commerce catalog

Before catalog/commerce work, read `docs/superpowers/marketplace-roadmap.md` and the unified-commerce design. `Shop` and `StoreListing` remain the canonical seller/listing identity; merchandise details and variants extend them without entering venue calendars, legacy RFQs or venue booking code. `commerce_catalog` requires an explicit enabled tenant flag. It grants browsing/catalog management only, never checkout or Community access.

- Stock changes go through `MerchandiseInventory` with the shared `commerce.inventory:<variant UUID>` advisory lock, reason, actor snapshot and idempotency key. Future reservations must use that same lock. Preserve immutable movement history; deactivate organisations with catalog records rather than hard-delete them.
- Merchandise media stays private and is streamed through current listing/member authorisation. Never expose storage keys or reuse public venue-media paths.
- Member-only merchandise requires current native verified-email self membership; phone proof, followers and representative access do not qualify.
- Before rolling back to source predating merchandise scopes, make merchandise parent listings draft. A disabled catalog flag alone is not honoured by that older source. Retain catalog and audit tables.
