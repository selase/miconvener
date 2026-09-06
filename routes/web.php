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

Route::get('/', fn (): Factory|View => view('product.landing'))->name('home');
Route::get('/sample-product', fn (): Factory|View => view('sample-product-page'));
Route::get('/product-template', fn (): Factory|View => view('product.landing'))->name('product.template');
Route::get('/product-enterprise', fn (): Factory|View => view('product.enterprise'))->name('product.enterprise');
Route::post('/product-enterprise/leads', [App\Http\Controllers\Marketing\LeadController::class, 'store'])->name('product.enterprise.lead');
Route::get('/product-docs/{section?}', function (?string $section = 'start-guide') {
    return view('product.docs', ['section' => $section]);
})->name('product.docs');

Route::group(['middleware' => ['auth', '2fa_challenge', 'onboarding']], function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    // user management routes
    Route::group(['prefix' => 'user-management'], function (): void {
        Route::resource('roles', App\Http\Controllers\Admin\RoleController::class);
        Route::resource('features', App\Http\Controllers\Admin\FeatureController::class);
        Route::resource('packages', App\Http\Controllers\Admin\PackageController::class);
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

    Route::resource('/billing/invoices', App\Http\Controllers\Billing\InvoiceController::class)
        ->only(['index', 'show'])
        ->names('billing.invoices');

    // Developer Settings (API Tokens)
    Route::group(['prefix' => 'settings/developer', 'as' => 'settings.developer.', 'middleware' => 'throttle:api-key-management'], function (): void {
        Route::get('tokens', [App\Http\Controllers\Settings\ApiTokenController::class, 'index'])->name('tokens.index');
        Route::post('tokens', [App\Http\Controllers\Settings\ApiTokenController::class, 'store'])->name('tokens.store');
        Route::delete('tokens/{id}', [App\Http\Controllers\Settings\ApiTokenController::class, 'destroy'])->name('tokens.destroy');
    });

    // Onboarding Wizard
    Route::group(['prefix' => 'onboarding', 'as' => 'onboarding.'], function (): void {
        Route::get('wizard', [App\Http\Controllers\Admin\OnboardingController::class, 'index'])->name('wizard');
        Route::post('branding', [App\Http\Controllers\Admin\OnboardingController::class, 'updateBranding'])->name('branding.update');
        Route::post('finish', [App\Http\Controllers\Admin\OnboardingController::class, 'finish'])->name('finish');
    });

    // Enterprise Leads
    Route::resource('admin/leads', App\Http\Controllers\Admin\LeadController::class)
        ->names('admin.leads');

    // Superadmin Global Billing & Analytics
    Route::group(['prefix' => 'admin/billing', 'as' => 'admin.billing.'], function (): void {
        Route::get('transactions', [App\Http\Controllers\Admin\BillingController::class, 'transactions'])
            ->name('transactions.index');
        Route::get('subscriptions', [App\Http\Controllers\Admin\BillingController::class, 'subscriptions'])
            ->name('subscriptions.index');
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

require __DIR__.'/auth.php';
