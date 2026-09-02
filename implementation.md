# Paystack Recurring Billing & Dunning Implementation Plan

## Goal Description
Implement an automated, recurring billing pipeline using Paystack Subscriptions. The system must automatically handle successful monthly/annual charges to extend subscriptions and provision features, as well as handle failed payments with automated dunning (retry) emails and feature demotion upon final cancellation.

## Architecture & Data Flow

### 1. Subscription Creation
- **Trigger**: The tenant selects a package on the Billing Dashboard and checks out using Paystack.
- **Paystack Side**: A subscription is automatically created using the `plan_code` associated with the package.
- **App Side**: The checkout callback stores the `subscription_code` and `customer_code` on the `Tenant` or `Subscription` model, setting the `ends_at` date to the current date + 1 interval (month/year).

### 2. Webhook Ingestion (The Engine)
- **Endpoint**: A dedicated unauthenticated endpoint `POST /api/webhooks/paystack` will be configured in `routes/api.php` and added to the `$except` array in `VerifyCsrfToken` (if applicable) or assigned the `api` middleware.
- **Security**: The payload signature (`x-paystack-signature`) must be verified against the `PAYSTACK_SECRET_KEY` using HMAC SHA512.
- **Queueing**: The verified webhook payload is dispatched to a specific Job based on the `event` key. This ensures the endpoint always responds with `200 OK` within Paystack's timeout limits.

### 3. Webhook Job Handlers

#### A. `charge.success` (Payment Successful)
**Job**: `ProcessSuccessfulPaystackCharge`
- Finds the `Subscription` using `customer.customer_code` or `subscription.subscription_code`.
- Extends the `ends_at` timestamp by 1 billing cycle.
- Updates the `status` to `active`.
- Provisions metered features (e.g., resets LLM token limits, clears any active locks).
- Dispatches a `SubscriptionRenewed` notification to send a receipt email to the tenant owner.

#### B. `invoice.payment_failed` (Payment Declined / Dunning)
**Job**: `ProcessFailedPaystackCharge`
- Finds the `Subscription`.
- **Does NOT cancel the subscription immediately.** Paystack will automatically retry according to its internal schedule.
- Dispatches a `PaymentFailedDunning` email notification. The email prompts the user to verify their payment method and provides a link to their billing dashboard.
- Optionally tags the `Subscription` status as `past_due`.

#### C. `subscription.disable` or `subscription.not_renew` (Final Cancellation)
**Job**: `ProcessDisabledPaystackSubscription`
- Finds the `Subscription`.
- Updates `ends_at` to the current moment (or allows it to lazily expire if just `not_renew`).
- Updates `status` to `canceled`.
- Dispatches a `SubscriptionCancelled` event.
- An internal listener catches the event, revokes premium `TenantFeature` flags, and sends a final "Account Suspended" email.

### 4. Application Enforcement (Middleware)
- **Middleware**: `RequireActiveSubscription`
- Applied to restricted/premium routes.
- Checks if `$tenant->latestSubscription()->isActive()` (where `isActive` returns `true` if `status === 'active'` and `ends_at > now()`).
- If false, redirects the user to the `tenant.billing.dashboard` with an error flash message: *"Your subscription is past due. Please update your payment method to restore access."*

### 5. Robustness & Testing
- **Idempotency**: `ProcessSuccessfulPaystackCharge` uses `Cache::add` with the transaction reference as a key (30-day TTL) to prevent duplicate webhook delivery from double-extending the subscription length.
- **Grace Periods**: `ProcessDisabledPaystackSubscription` distinguishes between `not_renew` (auto-renew off, keep access) and `disable` (final termination, revoke access).
- **Package Fallback**: Upon final cancellation, the system reverts the tenant to the `free` slug package and re-syncs allowed features, instead of just a generic feature lockout.
- **Paystack Simulator**: `App\Services\Billing\PaystackSimulatorService` allows firing real signed HTTP requests to the webhook endpoint via Artisan (`paystack:simulate`) for end-to-end integration testing in Dev.


## Tasks Breakdown
1. **Webhook Security & Routing**: Setup the webhook route and signature verification middleware/logic.
2. **Job Architecture**: Scaffold the main handler and dispatch to specific Queue Jobs (`ProcessSuccessfulPaystackCharge`, `ProcessFailedPaystackCharge`, `ProcessDisabledPaystackSubscription`).
3. **Database Definitions**: Ensure `Subscription` model can store/update `subscription_code`, `customer_code`, `status`, and `ends_at`.
4. **Mailable Creation**: Create markdown Mailables for `PaymentSuccessReceipt`, `PaymentFailedDunning`, and `SubscriptionCancelled`.
5. **Enforcement Middleware**: Create and apply `RequireActiveSubscription` middleware to protected application routes.
6. **Feature Demotion Strategy**: Hook into the `SubscriptionCancelled` internal event to toggle off `tenant_features`.
7. **Pest Feature Tests**: Write tests mimicking the Paystack webhook payloads to guarantee robust state transitions.
