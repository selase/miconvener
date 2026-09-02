# SaaS Starter Kit - Pre-built Infrastructure

## LLM Precursor Prompt

**IMPORTANT: Read this before making any implementation suggestions.**

This Laravel application is built on a **comprehensive SaaS starter kit** with extensive pre-built infrastructure. Before suggesting implementations or building new features, you MUST understand what already exists to avoid duplication.

### What's Already Built - DO NOT REBUILD

The following systems are **fully implemented and production-ready**. Do not suggest rebuilding or reimplementing these features:

#### ✅ Multi-Tenancy System (Complete)
- Tenant resolution (custom domains, subdomains, session, headers, route params)
- Multiple isolation modes: shared database, database-per-tenant, BYO
- Automatic tenant provisioning with database creation and migrations
- Tenant-specific storage management
- Services: `TenantContext`, `TenantResolver`, `TenantProvisioner`, `TenantDatabaseManager`, `TenantStorageManager`

#### ✅ Billing & Payment System (Complete)
- Dual payment gateway integration: Stripe and Paystack
- Complete subscription lifecycle management
- Invoice generation with tax calculation (simple and compound taxes)
- Support for flat-rate and per-seat billing models
- Webhook handling for payment events
- Services: `InvoicingService`, `PricingService`, `SubscriptionProvisioningService`
- Models: `Subscription`, `Invoice`, `InvoiceItem`, `Transaction`, `Tax`, `Package`

#### ✅ Usage Metering & Analytics (Complete)
- Real-time event tracking for requests, jobs, storage, database, active users
- Automatic rollup aggregation (minute/hour/day)
- Per-feature usage limits and enforcement
- Usage-based pricing support
- Service: `UsageService`
- Models: `UsageEvent`, `UsageRollup`, `UsageLimit`, `UsagePrice`

#### ✅ LLM Token Management (Complete)
- Token quota tracking per tenant
- Top-up balance purchasing system
- BYOK (Bring Your Own Key) support
- Model whitelist enforcement
- Global spending limits with alerts
- Service: `LlmUsageService`
- Models: `LlmTokenUsage`, `LlmUsageSummary`, `TenantLlmConfig`

#### ✅ Feature Flags & Entitlements (Complete)
- Boolean and metered (limit-based) features
- Feature-to-permission mapping via `EntitlementService`
- Package-to-tenant feature synchronization
- Usage-based access control
- Models: `Feature`, `TenantFeature`, `PackageFeature`, `Permission`

#### ✅ API & Webhook System (Complete)
- Tenant-scoped API key management with scopes and IP restrictions
- Webhook endpoint management with encrypted secrets
- Asynchronous webhook delivery with retry logic
- Service: `ApiKeyService`
- Models: `TenantApiKey`, `WebhookEndpoint`, `WebhookCall`
- Jobs: `SendWebhookJob`

#### ✅ Authentication & Security (Complete)
- Two-factor authentication (2FA) with Google Authenticator
- Login logging and IP tracking
- Role and permission system (Spatie/Permission)
- Tenant-scoped permissions
- Session management
- Middleware: `TwoFactorChallenge`, authentication guards

#### ✅ Middleware Stack (Complete)
- `ResolveTenant` - Tenant detection and resolution
- `TenantDatabaseManager` - Database connection switching
- `TenantStorageManager` - Storage routing
- `EnsureFeatureEnabled` - Feature gate enforcement
- `EnforceUsageLimits` - Usage limit checking
- `EnsureTenantHasLlmTokens` - LLM quota enforcement
- `AuthenticateWithApiKey` - API key validation
- `MeterRequestUsage` - Request metric recording
- `ThrottleLlmRequests` - LLM rate limiting
- `TrackActiveUser` - Daily active user tracking

### How to Use This Starter Kit

When building new features:

1. **Check existing models** - 28 models already exist covering tenancy, billing, usage, LLM, auth
2. **Use existing services** - 26+ services handle business logic; extend them instead of creating new ones
3. **Leverage middleware** - Feature gates, usage limits, tenant context already available
4. **Follow patterns** - Check sibling files for naming conventions and architectural patterns
5. **Don't duplicate** - If it's listed above, it exists. Build on top of it, don't rebuild it.

### What You Should Build

Focus on:
- **Domain-specific features** unique to the new project
- **Custom business logic** not covered by the starter kit
- **Integration with external services** specific to the project needs
- **UI/UX components** tailored to the project requirements
- **Custom reports and dashboards** using existing usage data
- **Specialized workflows** that leverage existing infrastructure

### Key Integration Points

When building new features, integrate with:

- **TenantContext** - Always use `app(TenantContext::class)->current()` for tenant-aware operations
- **EntitlementService** - Check feature entitlements before granting access
- **UsageService** - Record usage events for metering and analytics
- **LlmUsageService** - Track LLM token consumption
- **InvoicingService** - Generate invoices for custom charges
- **ApiKeyService** - Validate API access in custom endpoints

---

## Detailed Infrastructure Documentation

### Core Architecture

**Application Type**: Multi-tenant SaaS platform
**Laravel Version**: v12 (using Laravel 10 structure)
**PHP Version**: 8.4.15
**Database**: PostgreSQL (landlord) + tenant-specific databases

**Frontend Stack**:
- Livewire v4 (reactive components)
- Alpine.js v3 (lightweight interactivity)
- Tailwind CSS v4 (utility-first styling)
- Vite (module bundler)

**Testing**: Pest v4, PHPUnit v12

---

## 1. Multi-Tenancy System

### Architecture
The application implements a **hybrid multi-tenancy model** supporting three isolation modes:

1. **Shared Database** - All tenants share the landlord database
2. **Database Per Tenant** - Each tenant gets their own database
3. **BYO (Bring Your Own)** - Tenants can provide their own database credentials

### Tenant Resolution Strategy

Tenants are resolved in the following priority order:
1. Custom domain (`tenant.example.com`)
2. Subdomain (`tenant.yoursaas.com`)
3. Session data
4. HTTP headers
5. Route parameters

### Key Components

**Models**:
- `Tenant` - Core tenant entity with isolation mode, domain, database config
- `TenantFeature` - Feature assignments per tenant
- `TenantLlmConfig` - LLM configuration per tenant
- `TenantApiKey` - API key management

**Services**:
- `TenantContext` - Manages active tenant in request lifecycle
- `TenantResolver` - Resolves tenant from various sources
- `TenantProvisioner` - Creates databases, runs migrations, syncs features
- `TenantDatabaseManager` - Switches database connections
- `TenantStorageManager` - Routes file storage per tenant
- `TenantHealthService` - Monitors tenant health status
- `TenantStatsService` - Aggregates tenant metrics

**Middleware**:
- `ResolveTenant` - Detects and sets active tenant
- Database and storage managers work automatically once tenant is resolved

**Traits**:
- `BelongsToTenant` - Auto-sets tenant_id on model creation
- `HasUuid` - UUID primary keys
- `SpatieActivityLogs` - Audit trail integration

### Database Structure

**Landlord Database** (`landlord` connection):
- Users, tenants, billing, subscriptions
- LLM usage tracking
- Global configurations
- Packages and features

**Tenant Databases** (dynamic connections):
- Tenant-specific application data
- Created and migrated automatically via `TenantProvisioner`

---

## 2. Billing & Payment System

### Payment Gateways

Two payment providers are fully integrated:

1. **Stripe** - Credit card processing, subscriptions
2. **Paystack** - African payment processing

Both implement the `PaymentGateway` contract:
```php
- createCustomer(email, name): string
- createCheckoutSession(customerId, planId, redirectUrl): string
- charge(customerId, amount, currency, options): string
- subscriptionDetails(subscriptionId): array
- refund(transactionId, amount?): string
- verifyTransaction(reference): array
```

### Subscription Management

**Models**:
- `Package` - Subscription plans with pricing, features, billing model
- `Subscription` - Active subscriptions with trial periods, status tracking
- `Transaction` - Payment transactions
- `MerchantTransaction` - Tenant-level merchant payments

**Billing Models**:
- `flat_rate` - Fixed price per billing interval
- `per_seat` - Price multiplied by number of users

**Subscription Lifecycle**:
1. Trial period (optional)
2. Active subscription with recurring billing
3. Status tracking (active, canceled, suspended, expired)
4. Provider status synchronization (Stripe/Paystack)

**Service**: `SubscriptionProvisioningService` - Manages subscription lifecycle

### Invoice System

**Models**:
- `Invoice` - Invoice headers with status, dates, totals
- `InvoiceItem` - Line items with quantity, unit price
- `Tax` - Tax rules with type (simple/compound), priority

**Invoice Lifecycle**:
1. **Draft** - Being prepared
2. **Issued** - Sent to customer
3. **Paid** - Payment received
4. **Overdue** - Past due date
5. **Void** - Cancelled

**Tax Calculation**:
- Simple taxes: Applied to subtotal
- Compound taxes: Applied to subtotal + simple taxes
- Priority ordering for multiple taxes

**Service**: `InvoicingService`
- Generates invoices from usage data
- Calculates subtotals and applies taxes
- Handles invoice status transitions
- Triggers `InvoiceIssued` event for webhooks

**Service**: `PricingService`
- Calculates unit prices with markup
- Handles cost computation for usage-based billing

---

## 3. Usage Metering & Analytics

### Tracked Metrics

**UsageMetric Enum**:
- `REQUEST_COUNT` - HTTP requests
- `REQUEST_DURATION_MS` - Request latency
- `JOB_COUNT` - Background jobs processed
- `JOB_FAILED_COUNT` - Failed jobs
- `JOB_RUNTIME_MS` - Job execution time
- `USER_ACTIVE_DAILY` - Daily active users
- `STORAGE_BYTES` - File storage consumption
- `DB_BYTES` - Database size

### Data Models

**UsageEvent** - Raw usage events
- Metric type, value, timestamp
- Tenant and user association
- Optional dimensions (JSON metadata)

**UsageRollup** - Aggregated usage data
- Rolled up by period: minute, hour, day
- Sum, count, min, max, average calculations
- Dimension-based grouping

**UsageLimit** - Feature consumption limits
- Polymorphic association (Feature, Package, Tenant)
- Hard and soft limits
- Reset periods (hourly, daily, monthly)

**UsagePrice** - Metered pricing rules
- Cost per unit of usage
- Polymorphic association with any billable metric
- Integration with invoicing

**TenantFeatureUsage** - Per-feature usage tracking
- Current usage vs limits
- Last reset timestamps

### Service: UsageService

**Key Methods**:
- `recordEvent(metric, value, tenant, dimensions)` - Log usage event
- `aggregateEvents()` - Create rollups from raw events
- `getTenantUsage(tenant, metric, startDate, endDate)` - Query usage data
- `checkLimit(tenant, feature)` - Verify usage limits
- `resetUsage(tenant, feature)` - Reset usage counters

### Middleware

- `MeterRequestUsage` - Automatically tracks HTTP requests
- `EnforceUsageLimits` - Blocks requests when limits exceeded
- `TrackActiveUser` - Records daily active users

---

## 4. LLM Token Management

### Features

1. **Token Quota System** - Per-tenant token allocation
2. **Top-up Purchases** - Buy additional tokens
3. **BYOK Support** - Bring Your Own Key option
4. **Model Whitelist** - Control which models tenants can use
5. **Spending Limits** - Global spend caps with alerts
6. **Cost Tracking** - Separate prompt and completion token costs

### Models

**LlmTokenUsage**:
- Model, prompt tokens, completion tokens
- Cost calculation (prompt_cost, completion_cost, total_cost)
- Tenant and user association
- Request metadata (purpose, status)

**LlmUsageSummary**:
- Aggregated usage per tenant
- Total tokens, total cost, request count
- Date-based summaries

**TenantLlmConfig**:
- Token quota allocation
- Top-up balance
- BYOK API key (encrypted)
- Model whitelist
- Global spending limits

### Service: LlmUsageService

**Key Methods**:
- `recordUsage(tenant, model, promptTokens, completionTokens, metadata)` - Log LLM usage
- `checkQuota(tenant, estimatedTokens)` - Verify token availability
- `purchaseTopup(tenant, amount)` - Add tokens to balance
- `getUsageSummary(tenant, startDate, endDate)` - Aggregate usage data
- `checkSpendingLimit(tenant)` - Verify spending caps
- `notifyLowBalance(tenant)` - Alert on low quota

**Cost Calculation**:
- Model-specific pricing per 1000 tokens
- Separate rates for prompt and completion tokens
- Automatic cost tracking per request

### Middleware

- `EnsureTenantHasLlmTokens` - Block requests when quota exhausted
- `ThrottleLlmRequests` - Rate limit LLM endpoints

---

## 5. Feature Flags & Entitlements

### Feature Types

1. **Boolean Features** - Simple on/off flags
2. **Limit Features** - Metered with usage tracking

### Models

**Feature**:
- Name, slug, type (boolean/limit)
- Description, default value
- Global feature catalog

**TenantFeature**:
- Feature assignment to tenant
- Enabled status
- Limit value (for metered features)
- Usage tracking

**PackageFeature**:
- Feature included in package
- Pivot with value (limit amount)

**Permission**:
- Spatie permission model
- Linked to features via `FeaturePermission` pivot

### Service: EntitlementService

**Key Methods**:
- `isEntitled(tenant, permission)` - Check if tenant has permission based on features
- `enableFeature(tenant, feature)` - Grant feature access
- `disableFeature(tenant, feature)` - Revoke feature access
- `syncPackageFeatures(tenant, package)` - Sync features from package
- `checkFeatureLimit(tenant, feature)` - Verify usage against limit

### Integration with Permissions

Features unlock permissions via the entitlement bridge:
1. Check if tenant has feature enabled
2. If enabled, grant associated permissions
3. Use standard Spatie permission checks in code

### Middleware

- `EnsureFeatureEnabled` - Gate routes based on feature access

---

## 6. API & Webhook System

### API Key Management

**Model: TenantApiKey**:
- Encrypted key with SHA256 hash for validation
- Scopes for granular access control
- IP address restrictions
- Expiration dates
- Last used tracking

**Service: ApiKeyService**

**Key Methods**:
- `generate(tenant, name, scopes, ipAddresses, expiresAt)` - Create API key
- `validate(key)` - Verify key validity
- `authenticate(request)` - Extract and validate from request
- `revoke(key)` - Disable key
- `checkScope(key, requiredScope)` - Verify scope access

**Middleware: AuthenticateWithApiKey**:
- Extracts Bearer token from Authorization header
- Validates key, expiration, IP restrictions
- Sets authenticated tenant in context

### Webhook System

**Model: WebhookEndpoint**:
- URL, events to listen for
- Encrypted secret for signature verification
- Enabled/disabled status
- Retry configuration

**Model: WebhookCall**:
- Outbound webhook attempt log
- Status (pending, success, failed)
- Response data
- Retry count

**Job: SendWebhookJob**:
- Asynchronous webhook delivery
- Automatic retry with exponential backoff
- Signature generation (HMAC-SHA256)
- Response logging

**Events**:
- `InvoiceIssued` - Triggers webhook when invoice is generated
- Extensible for custom events

**Usage**:
```php
// Webhook automatically sent when event fired
event(new InvoiceIssued($invoice));
```

---

## 7. Authentication & Security

### Two-Factor Authentication (2FA)

**Package**: `pragmarx/google2fa-laravel`

**Features**:
- Google Authenticator integration
- QR code generation for setup
- Recovery codes
- Challenge middleware

**Middleware: TwoFactorChallenge**:
- Intercepts requests when 2FA required
- Redirects to 2FA verification page
- Session-based challenge tracking

**Livewire Component: UserProfileSecurity**:
- Enable/disable 2FA
- Generate recovery codes
- Manage security settings

### Login Tracking

**Model: LoginLog**:
- User, IP address, user agent
- Login timestamp
- Success/failure status

**Listeners**:
- `LoginLogs` - Records login attempts
- `UpdateLastLoginIp` - Updates user's last login IP

### Permission System

**Package**: `spatie/laravel-permission`

**Roles**:
- Superadmin (global access)
- Org Superadmin (organization level)
- Org Admin (organization admin)
- Custom roles per tenant

**Tenant-Scoped Permissions**:
- Uses `setPermissionsTeamId(tenant->id)` for isolation
- Permissions checked within tenant context
- Integration with `EntitlementService` for feature-based access

### Activity Logging

**Package**: `spatie/laravel-activitylog`

**Trait: SpatieActivityLogs**:
- Automatically logs model changes
- Records causer (user), subject (model), description
- Stores old and new values
- Searchable activity feed

---

## 8. Middleware Stack

### Tenant Management
- **ResolveTenant** - Detects tenant from request (priority: custom domain > subdomain > session > header > route)
- **TenantDatabaseManager** - Switches database connection to tenant-specific database
- **TenantStorageManager** - Routes file storage to tenant-specific disk

### Feature & Usage Enforcement
- **EnsureFeatureEnabled** - Gates access based on tenant features
- **EnforceUsageLimits** - Blocks requests when usage limits exceeded
- **EnsureTenantHasLlmTokens** - Verifies LLM token quota before processing

### API & Authentication
- **AuthenticateWithApiKey** - Validates Bearer token for API requests
- **TwoFactorChallenge** - Enforces 2FA for protected routes

### Metering & Tracking
- **MeterRequestUsage** - Records HTTP request metrics (count, duration)
- **TrackActiveUser** - Logs daily active users
- **ThrottleLlmRequests** - Rate limits LLM API calls

### Onboarding
- **EnsureOnboardingComplete** - Redirects incomplete onboarding users

---

## 9. Data Models Reference

### Tenancy (7 models)
- **Tenant** - Core tenant entity
- **TenantFeature** - Feature assignments
- **TenantLlmConfig** - LLM settings
- **TenantApiKey** - API authentication
- **TenantFeatureUsage** - Usage tracking
- **LoginLog** - Login history
- **Package** - Subscription plans

### Billing (7 models)
- **Subscription** - Active subscriptions
- **Invoice** - Invoice headers
- **InvoiceItem** - Line items
- **Transaction** - Payment records
- **MerchantTransaction** - Merchant payments
- **Tax** - Tax rules
- **UsagePrice** - Metered pricing

### Usage & Metering (4 models)
- **UsageEvent** - Raw usage events
- **UsageRollup** - Aggregated metrics
- **UsageLimit** - Consumption limits
- **Feature** - Feature catalog

### LLM (3 models)
- **LlmTokenUsage** - Token consumption
- **LlmUsageSummary** - Aggregated usage
- **PackageFeature** - Package-feature association

### Webhooks (2 models)
- **WebhookEndpoint** - Webhook destinations
- **WebhookCall** - Webhook delivery log

### Auth & Permissions (3 models)
- **User** - User accounts
- **Permission** - Spatie permissions
- **Role** - Spatie roles (via package)

### System (2 models)
- **ActivityLog** - Audit trail (via Spatie)
- **PasswordResetToken** - Password resets

---

## 10. Services Reference

### Tenancy Services
- **TenantContext** - Active tenant management
- **TenantResolver** - Tenant detection
- **TenantProvisioner** - Database setup and migration
- **TenantDatabaseManager** - Connection switching
- **TenantStorageManager** - Storage routing
- **TenantHealthService** - Health monitoring
- **TenantStatsService** - Metrics aggregation

### Billing Services
- **InvoicingService** - Invoice generation and tax calculation
- **PricingService** - Price calculation with markup
- **SubscriptionProvisioningService** - Subscription lifecycle
- **PaymentGateway** (interface) - Payment provider abstraction
  - **StripeGateway** - Stripe implementation
  - **PaystackGateway** - Paystack implementation

### Usage Services
- **UsageService** - Event recording and rollup aggregation
- **LlmUsageService** - LLM token tracking and cost calculation

### Security Services
- **ApiKeyService** - API key generation and validation
- **EntitlementService** - Feature-based permission checks
- **SecretsProvider** - AWS KMS and local secret management

### Other Services
- **SearchService** - Search functionality
- **WebhookService** - Webhook delivery management

---

## 11. Jobs

### Asynchronous Processing
- **SendWebhookJob** - Delivers webhooks with retry logic and signature generation

---

## 12. Events & Listeners

### Events
- **InvoiceIssued** - Fired when invoice is generated

### Listeners
- **WebhookEventSubscriber** - Handles webhook delivery for events
- **UsageListener** - Records usage metrics from events
- **LoginLogs** - Logs login attempts
- **UpdateLastLoginIp** - Updates user IP on login

---

## 13. Frontend Components

### Livewire Components (3)
1. **PricingTable** - Displays subscription plans and pricing
2. **TenantHealthCheck** - Admin dashboard health monitoring
3. **UserProfileSecurity** - Security settings with 2FA management

### View Structure
```
/resources/views/
├── livewire/          # Livewire component views
├── admin/             # Admin dashboard
├── billing/           # Billing and pricing pages
├── tenant/            # Tenant-specific views
├── auth/              # Authentication pages
├── marketing/         # Marketing pages
├── settings/          # Settings pages
└── layouts/           # Layout templates
```

### Technology
- **Blade Templates** - Laravel templating engine
- **Tailwind CSS v4** - Utility-first styling
- **Alpine.js v3** - Lightweight JavaScript framework
- **Chart.js** - Data visualization
- **Vite** - Frontend build tool

---

## 14. Configuration Files

### Key Configurations
- **config/billing.php** - Global markup percentage
- **config/database.php** - Landlord and tenant connections
- **config/auth.php** - Authentication guards and providers
- **config/google2fa.php** - Two-factor authentication settings
- **config/tenancy.php** - Multi-tenancy configuration (likely)

---

## 15. Third-Party Integrations

### Payment Processing
- **stripe/stripe-php** - Stripe payment gateway
- **Custom Paystack Integration** - African payment processing

### Authentication & Security
- **pragmarx/google2fa-laravel** - Two-factor authentication
- **laravel/sanctum** - API token authentication
- **spatie/laravel-permission** - Role and permission management
- **spatie/laravel-activitylog** - Audit logging

### Data Processing
- **barryvdh/laravel-dompdf** - PDF generation for invoices
- **maatwebsite/excel** - Excel export functionality
- **propaganistas/laravel-phone** - Phone number validation

### Development & Quality
- **larastan/larastan** - Static analysis
- **laravel/pint** - Code formatting
- **pestphp/pest** - Testing framework
- **laravel/telescope** - Debugging and monitoring

---

## 16. Testing Infrastructure

### Framework
- **Pest v4** - Primary testing framework
- **PHPUnit v12** - Underlying test runner

### Test Types
- **Feature Tests** - API endpoints, workflows, integrations
- **Unit Tests** - Services, models, utilities

### Database Testing
- **Factories** - Model factories for test data generation
- **Seeders** - Database seeders for development data
- **SQLite** - In-memory database for fast tests

### Commands
```bash
# Run all tests
php artisan test --compact

# Run specific test file
php artisan test --compact tests/Feature/BillingTest.php

# Run with filter
php artisan test --compact --filter=testInvoiceGeneration
```

---

## 17. Development Workflow

### Code Quality Tools

**Laravel Pint** - Code formatting
```bash
vendor/bin/pint --dirty
```

**Larastan** - Static analysis
```bash
vendor/bin/phpstan analyse
```

### Artisan Commands

**Make Commands** (use `list-artisan-commands` tool to see all):
- `php artisan make:model {name}` - Create model with options
- `php artisan make:controller {name}` - Create controller
- `php artisan make:service {name}` - Create service class
- `php artisan make:test {name}` - Create test

**Tenant Commands**:
- Likely custom commands for tenant management, migrations, seeding

### Environment
- **Laravel Herd** - Local development server
- **PostgreSQL** - Database
- **Redis** - Caching and queues (likely)
- **NPM** - Frontend dependencies

---

## 18. Deployment Considerations

### Database Migrations

**Two Migration Paths**:
1. **Landlord Migrations** - Global schema changes
2. **Tenant Migrations** - Per-tenant schema changes

Both are managed automatically by `TenantProvisioner` when provisioning new tenants.

### Queue Workers

Required for:
- Webhook delivery (`SendWebhookJob`)
- Email sending
- Usage rollup aggregation
- LLM processing (potentially)

### Scheduled Tasks

Likely includes:
- Usage rollup generation (hourly/daily)
- Subscription renewal processing
- Invoice generation
- Usage limit resets
- LLM quota resets

### Storage

**Tenant Isolation**:
- Files stored per tenant via `TenantStorageManager`
- Supports local and cloud storage (S3, etc.)

### Monitoring

**Laravel Telescope** - Built-in for:
- Request monitoring
- Job tracking
- Query analysis
- Exception logging

**TenantHealthService** - Custom health checks per tenant

---

## 19. Security Features

### Encryption
- API keys stored encrypted with SHA256 hashing
- Webhook secrets encrypted at rest
- BYOK API keys encrypted

### Input Validation
- Form Request classes for validation
- Phone number validation
- Email validation

### Rate Limiting
- Per-route throttling
- LLM request throttling
- API key-based rate limits

### IP Restrictions
- API key IP whitelisting
- Geo-blocking support (via packages)

### Audit Trail
- All model changes logged via Spatie Activity Log
- Login attempt tracking
- API key usage tracking
- Webhook delivery logging

---

## 20. Extensibility Points

### Adding New Payment Gateways

Implement the `PaymentGateway` interface:
```php
interface PaymentGateway {
    public function createCustomer(string $email, string $name): string;
    public function createCheckoutSession(string $customerId, string $planId, string $redirectUrl): string;
    public function charge(string $customerId, float $amount, string $currency, array $options): string;
    public function subscriptionDetails(string $subscriptionId): array;
    public function refund(string $transactionId, ?float $amount): string;
    public function verifyTransaction(string $reference): array;
}
```

### Adding New Usage Metrics

1. Add to `UsageMetric` enum
2. Record via `UsageService::recordEvent()`
3. Create middleware if automatic tracking needed
4. Add to pricing rules if billable

### Adding New Features

1. Create feature in `features` table
2. Assign to packages via `PackageFeature`
3. Optionally link permissions via `FeaturePermission`
4. Use `EntitlementService::isEntitled()` to check access

### Adding Webhook Events

1. Create event class
2. Add to `WebhookEventSubscriber` if automatic delivery needed
3. Tenants subscribe via `WebhookEndpoint` model

---

## Summary

This starter kit provides a **production-ready foundation** for building multi-tenant SaaS applications with:

✅ **Complete multi-tenancy** with flexible isolation modes
✅ **Enterprise billing** with dual payment gateways
✅ **Usage metering** and analytics infrastructure
✅ **LLM token management** with quota and cost tracking
✅ **Feature flags** with entitlement-based permissions
✅ **API & webhook** systems for integrations
✅ **2FA and security** features
✅ **Comprehensive middleware** for gates and tracking
✅ **Testing infrastructure** with Pest
✅ **Code quality tools** (Pint, Larastan)

**Focus new development on domain-specific features** that leverage this infrastructure rather than rebuilding foundational systems.
