<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditTrailController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonateUserController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ResendAccountPasswordController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantHealthController;
use App\Http\Controllers\Admin\UsersController;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/health', function (): Illuminate\Http\JsonResponse {
    try {
        Illuminate\Support\Facades\DB::connection('landlord')->getPdo();
        $db = 'ok';
    } catch (Throwable) {
        $db = 'error';
    }

    return response()->json([
        'status' => $db === 'ok' ? 'ok' : 'degraded',
        'database' => $db,
        'timestamp' => now()->toIso8601String(),
    ], $db === 'ok' ? 200 : 503);
})->name('health');

Route::get('/health/scheduler', App\Http\Controllers\SchedulerHealthController::class)
    ->middleware('throttle:60,1')
    ->name('health.scheduler');

Route::get('/', fn (): Factory|View => view('product.landing'))->name('home');
Route::get('/sample-product', fn (): Factory|View => view('sample-product-page'));
// A leftover from the template the homepage was built on. Kept as a permanent
// redirect so old links and search results land on the real homepage.
Route::permanentRedirect('/product-template', '/')->name('product.template');
Route::get('/product-enterprise', fn (): Factory|View => view('product.enterprise'))->name('product.enterprise');
Route::get('/terms', fn (): Factory|View => view('product.terms'))->name('terms');

// The staff links' offline worker. Served by the app rather than as a static
// file so it is never cached at the edge: a fixed worker must reach ushers'
// phones on the next page load, not hours later.
Route::get('/staff-worker.js', fn () => response()->file(resource_path('js/workers/staff-sw.js'), [
    'Content-Type' => 'application/javascript; charset=utf-8',
    'Cache-Control' => 'no-cache, no-store, must-revalidate',
    'Service-Worker-Allowed' => '/staff/',
]))->name('staff.worker');

// The attendee portal's offline worker, served uncached for the same reason.
Route::get('/attendee-worker.js', fn () => response()->file(resource_path('js/workers/attendee-sw.js'), [
    'Content-Type' => 'application/javascript; charset=utf-8',
    'Cache-Control' => 'no-cache, no-store, must-revalidate',
    'Service-Worker-Allowed' => '/my/',
]))->name('attendee.worker');
Route::get('/privacy', fn (): Factory|View => view('product.privacy'))->name('privacy');
Route::post('/product-enterprise/leads', [App\Http\Controllers\Marketing\LeadController::class, 'store'])->name('product.enterprise.lead');
Route::get('/product-docs/{section?}', function (?string $section = 'start-guide') {
    return view('product.docs', ['section' => $section]);
})->name('product.docs');
Route::get('/deck', App\Http\Controllers\Marketing\PitchDeckController::class)->name('marketing.deck');
Route::get('/pitch', App\Http\Controllers\Marketing\PitchDeckController::class)->name('marketing.pitch');
Route::get('/slides', App\Http\Controllers\Marketing\PitchDeckController::class)->name('marketing.slides');

Route::group(['middleware' => ['auth', '2fa_challenge', 'onboarding']], function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    // user management routes
    Route::group(['prefix' => 'user-management'], function (): void {
        Route::resource('roles', App\Http\Controllers\Admin\RoleController::class);
        Route::resource('features', App\Http\Controllers\Admin\FeatureController::class);
        Route::resource('packages', App\Http\Controllers\Admin\PackageController::class)->except(['show']);
        Route::post('users/all', [UsersController::class, 'getAllUsers'])->name('users.all');
        Route::put('users/{user}/resend-password', ResendAccountPasswordController::class);
        Route::resource('users', UsersController::class);
    });

    // audit trail routes
    Route::group(['prefix' => 'audit-trail', 'as' => 'audit-trail.'], function (): void {
        Route::get('activity-logs', [AuditTrailController::class, 'activityLogIndex'])
            ->name('activity-logs.index');
        Route::post('activity-logs/all', [AuditTrailController::class, 'getAllActivityLogs'])
            ->name('activity-logs.all');
        Route::get('login-history', [AuditTrailController::class, 'loginHistoryIndex'])
            ->name('login-history.index');
        Route::post('login-history/all', [AuditTrailController::class, 'getAllLoginHistories'])
            ->name('login-history.all');
        Route::get('activity-logs/export', [AuditTrailController::class, 'exportActivityLogs'])
            ->name('activity-logs.export');
    });

    Route::get('health/tenants', [TenantHealthController::class, 'index'])
        ->name('health.tenants');

    // profile routes
    Route::get('profile/{user}', [ProfileController::class, 'index'])->name('profile.index');

    // Global LLM usage routes
    Route::group(['prefix' => 'llm-usage', 'as' => 'llm-usage.'], function (): void {
        Route::get('/', [App\Http\Controllers\Admin\GlobalLlmUsageController::class, 'index'])
            ->name('index');
    });

    // Marketplace Merchant Verification Queue (accessible at /admin/marketplace-verifications and /marketplace-verifications)
    Route::group(['prefix' => 'admin/marketplace-verifications', 'as' => 'admin.marketplace-verifications.'], function (): void {
        Route::get('/', [App\Http\Controllers\Admin\MarketplaceVerificationController::class, 'index'])->name('index');
        Route::get('/{shop}', [App\Http\Controllers\Admin\MarketplaceVerificationController::class, 'show'])->name('show');
        Route::post('/{shop}/approve', [App\Http\Controllers\Admin\MarketplaceVerificationController::class, 'approve'])->name('approve');
        Route::post('/{shop}/reject', [App\Http\Controllers\Admin\MarketplaceVerificationController::class, 'reject'])->name('reject');
    });
    Route::get('marketplace-verifications', fn () => redirect()->route('admin.marketplace-verifications.index'));
    Route::get('marketplace-verifications/{shop}', fn ($shop) => redirect()->route('admin.marketplace-verifications.show', $shop));

    // Tenant routes
    Route::group(['prefix' => 'tenants', 'as' => 'tenants.'], function (): void {
        Route::get('reset', [TenantController::class, 'resetTenant'])
            ->name('reset');
        Route::post('all', [TenantController::class, 'getAllTenants'])
            ->name('all');
        Route::post('{tenants}/team/all', [TeamController::class, 'getAllTeams'])
            ->name('team.all');
        Route::resource('{tenants}/team', TeamController::class);
        Route::get('change/{tenant}', [TenantController::class, 'changeTenant'])
            ->name('change');
    });
    Route::resource('tenants', TenantController::class);

    // Tenant switching routes
    Route::group(['prefix' => 'tenant', 'as' => 'tenant.'], function (): void {
        Route::get('my-tenants', [App\Http\Controllers\Tenancy\TenantSwitchController::class, 'index'])->name('my-tenants');
        Route::post('switch', [App\Http\Controllers\Tenancy\TenantSwitchController::class, 'switch'])->name('switch');
    });

    // User impersonation routes
    Route::group(['prefix' => 'impersonation'], function (): void {
        Route::get('stop-impersonation', [ImpersonateUserController::class, 'stopImpersonation'])
            ->name('impersonation.stop');
        Route::get('{user}', [ImpersonateUserController::class, 'impersonate'])
            ->middleware('permission:impersonate user')
            ->name('impersonation.impersonate');
    });

    // Billing routes
    Route::get('/billing', [App\Http\Controllers\Billing\BillingController::class, 'index'])
        ->name('billing.index');

    Route::get('/billing/confirm', [App\Http\Controllers\Billing\ConfirmSubscriptionController::class, 'show'])
        ->name('billing.confirm');

    Route::post('/billing/checkout', [App\Http\Controllers\Billing\CheckoutController::class, 'store'])
        ->middleware('throttle:billing')
        ->name('billing.checkout');

    Route::post('/billing/transactions/{transaction}/refund', [App\Http\Controllers\Billing\RefundController::class, 'store'])
        ->middleware('throttle:billing')
        ->name('billing.refund');

    Route::get('/billing/invoices/{invoice}/download', [App\Http\Controllers\Billing\InvoiceController::class, 'download'])
        ->name('billing.invoices.download');

    // ->only(['show']): InvoiceController has no index() method (nothing has
    // ever linked to billing.invoices.index; it would 500 if requested).
    Route::resource('/billing/invoices', App\Http\Controllers\Billing\InvoiceController::class)
        ->only(['show'])
        ->names('billing.invoices');

    // Developer Settings (API Tokens)
    Route::group(['prefix' => 'settings/developer', 'as' => 'settings.developer.', 'middleware' => 'throttle:api-key-management'], function (): void {
        Route::get('tokens', [App\Http\Controllers\Settings\ApiTokenController::class, 'index'])->name('tokens.index');
        Route::post('tokens', [App\Http\Controllers\Settings\ApiTokenController::class, 'store'])->name('tokens.store');
        Route::delete('tokens/{id}', [App\Http\Controllers\Settings\ApiTokenController::class, 'destroy'])->name('tokens.destroy');
    });

    // Enterprise Leads
    Route::resource('admin/leads', App\Http\Controllers\Admin\LeadController::class)
        ->only(['index', 'show', 'destroy'])
        ->names('admin.leads');

    // Superadmin Operations: run the allowlisted maintenance commands
    Route::group(['prefix' => 'admin/operations', 'as' => 'admin.operations.'], function (): void {
        Route::get('/', [App\Http\Controllers\Admin\OperationsController::class, 'index'])
            ->name('index');
        Route::post('/', [App\Http\Controllers\Admin\OperationsController::class, 'store'])
            ->name('store');
        Route::get('/{run}', [App\Http\Controllers\Admin\OperationsController::class, 'show'])
            ->name('show');
    });

    // Superadmin Global Billing & Analytics
    Route::group(['prefix' => 'admin/billing', 'as' => 'admin.billing.'], function (): void {
        Route::get('transactions', [App\Http\Controllers\Admin\BillingController::class, 'transactions'])
            ->name('transactions.index');
        Route::get('subscriptions', [App\Http\Controllers\Admin\BillingController::class, 'subscriptions'])
            ->name('subscriptions.index');
        Route::post('subscriptions/{tenant:uuid}/toggle-complimentary', [App\Http\Controllers\Admin\BillingController::class, 'toggleComplimentary'])
            ->name('subscriptions.toggle-complimentary');
        Route::get('analytics', [App\Http\Controllers\Admin\AnalyticsController::class, 'index'])
            ->name('analytics.usage');

        Route::get('rate-cards', [App\Http\Controllers\Admin\RateCardController::class, 'index'])
            ->name('rate-cards.index');
        Route::post('rate-cards/prices', [App\Http\Controllers\Admin\RateCardController::class, 'updatePrices'])
            ->name('rate-cards.prices.update');
        Route::post('rate-cards/taxes', [App\Http\Controllers\Admin\RateCardController::class, 'storeTax'])
            ->name('rate-cards.taxes.store');
        Route::put('rate-cards/taxes/{tax}', [App\Http\Controllers\Admin\RateCardController::class, 'updateTax'])
            ->name('rate-cards.taxes.update');
        Route::delete('rate-cards/taxes/{tax}', [App\Http\Controllers\Admin\RateCardController::class, 'destroyTax'])
            ->name('rate-cards.taxes.destroy');

        Route::post('invoices/{invoice}/resend', [App\Http\Controllers\Admin\InvoiceController::class, 'resend'])
            ->name('invoices.resend');
        Route::get('invoices/{invoice}/download', [App\Http\Controllers\Admin\InvoiceController::class, 'download'])
            ->name('invoices.download');
        Route::post('invoices/bulk-issue', [App\Http\Controllers\Admin\InvoiceController::class, 'bulkIssue'])
            ->name('invoices.bulk-issue');
        Route::post('invoices/{invoice}/issue', [App\Http\Controllers\Admin\InvoiceController::class, 'issue'])
            ->name('invoices.issue');
        Route::post('invoices/{invoice}/adjust', [App\Http\Controllers\Admin\InvoiceController::class, 'addAdjustment'])
            ->name('invoices.adjust');
        Route::delete('invoices/items/{item}', [App\Http\Controllers\Admin\InvoiceController::class, 'removeAdjustment'])
            ->name('invoices.items.destroy');

        Route::resource('invoices', App\Http\Controllers\Admin\InvoiceController::class)
            ->only(['index', 'show', 'create', 'store']);
    });
});

Route::post('/webhooks/stripe', [App\Http\Controllers\Billing\WebhookController::class, 'handleStripe'])->middleware('throttle:webhooks')->name('webhooks.stripe');
// Paystack allows one webhook URL per business account, so both platform-level
// Paystack URLs reach the same handler, which routes by payload. Either may be
// used as the account's single webhook URL, and both work whether settlement and
// subscription billing share one Paystack account or use two.
Route::post('/webhooks/paystack', [App\Http\Controllers\Public\SettlementWebhookController::class, 'handle'])->middleware('throttle:webhooks')->name('webhooks.paystack');

// Merchant Webhooks (Public)
Route::post('/webhooks/merchant/stripe/{tenant}', [App\Http\Controllers\Tenant\Commerce\WebhookController::class, 'handleStripe'])->middleware('throttle:webhooks')->name('webhooks.merchant.stripe');
Route::post('/webhooks/merchant/paystack/{tenant}', [App\Http\Controllers\Tenant\Commerce\WebhookController::class, 'handlePaystack'])->middleware('throttle:webhooks')->name('webhooks.merchant.paystack');
Route::post('/webhooks/settlement/paystack', [App\Http\Controllers\Public\SettlementWebhookController::class, 'handle'])->middleware('throttle:settlement-webhooks')->name('webhooks.settlement.paystack');

Route::get('/billing/callback', App\Http\Controllers\Billing\CallbackController::class)
    ->middleware(['auth']) // Maybe? Or guest if flow allows? Usually auth if we redirect to dashboard.
    ->name('billing.callback');

Route::get('/media/{path}', [App\Http\Controllers\MediaController::class, 'show'])->where('path', '.*')->name('media.show');
Route::get('/storage/{path}', [App\Http\Controllers\MediaController::class, 'show'])->where('path', '.*');

Route::get('/verify/cert/{uuid}', [App\Http\Controllers\Public\PublicCertificateVerificationController::class, 'verify'])->name('web.certificates.verify');
Route::get('/verify/cert/{uuid}/download', [App\Http\Controllers\Public\PublicCertificateVerificationController::class, 'download'])->name('web.certificates.download');

// MiConvener Marketplace (Public)
Route::prefix('marketplace')->name('marketplace.')->group(function (): void {
    Route::get('/', [App\Http\Controllers\Marketplace\MarketplaceController::class, 'index'])->name('index');
    Route::get('/venues', [App\Http\Controllers\Marketplace\MarketplaceListingController::class, 'index'])->name('venues.index');
    Route::get('/venues/{slug}', [App\Http\Controllers\Marketplace\MarketplaceListingController::class, 'show'])->name('venues.show');
    Route::post('/venues/{slug}/check-availability', [App\Http\Controllers\Marketplace\VenueBookingController::class, 'checkAvailability'])->name('venues.check-availability');
    Route::get('/venues/{slug}/booked-slots', [App\Http\Controllers\Marketplace\VenueBookingController::class, 'bookedSlots'])->name('venues.booked-slots');
    Route::post('/venues/{slug}/book', [App\Http\Controllers\Marketplace\VenueBookingController::class, 'book'])->name('venues.book');
    Route::get('/bookings/{reference}', [App\Http\Controllers\Marketplace\VenueBookingController::class, 'show'])->name('bookings.show');
    Route::post('/bookings/{reference}/checkout', [App\Http\Controllers\Marketplace\VenueBookingController::class, 'checkout'])->name('bookings.checkout');
    Route::get('/bookings/{reference}/callback', [App\Http\Controllers\Marketplace\VenueBookingController::class, 'callback'])->name('bookings.callback');
    Route::get('/{merchant_slug}', [App\Http\Controllers\Marketplace\MarketplaceStorefrontController::class, 'show'])->name('storefront.show');

    // RFQs, Proposals & Reviews
    Route::post('/listings/{slug}/rfq', [App\Http\Controllers\Marketplace\MarketplaceQuotePublicController::class, 'storeRfq'])->middleware('throttle:60,1')->name('quotes.rfq');
    Route::get('/quotes/{reference}', [App\Http\Controllers\Marketplace\MarketplaceQuotePublicController::class, 'showProposal'])->name('quotes.show');
    Route::post('/quotes/{reference}/checkout', [App\Http\Controllers\Marketplace\MarketplaceQuotePublicController::class, 'checkout'])->middleware('throttle:30,1')->name('quotes.checkout');
    Route::get('/quotes/{reference}/callback', [App\Http\Controllers\Marketplace\MarketplaceQuotePublicController::class, 'callback'])->name('quotes.callback');
    Route::post('/quotes/{reference}/review', [App\Http\Controllers\Marketplace\MarketplaceQuotePublicController::class, 'storeReview'])->middleware('throttle:30,1')->name('quotes.review');
});

// Model Context Protocol (MCP) Server for Marketplace AI Agents
Laravel\Mcp\Facades\Mcp::web('mcp/marketplace', App\Mcp\MarketplaceMcpServer::class)
    ->middleware(['mcp_tier', 'throttle:mcp-marketplace']);
Route::get('mcp/marketplace', [App\Http\Controllers\Marketplace\MarketplaceMcpController::class, 'show'])
    ->middleware(['mcp_tier', 'throttle:mcp-marketplace'])
    ->name('mcp.marketplace.show');

require __DIR__.'/auth.php';
