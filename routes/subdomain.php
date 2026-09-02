<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\OrgSettingsController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/tenant-test', function () {
    $tenant = app(App\Services\Tenancy\TenantContext::class)->getTenant();

    return 'Tenant: '.($tenant?->name ?? 'None');
});

Route::group(['middleware' => ['auth', '2fa_challenge', 'onboarding']], function () {
    Route::get('/dashboard', fn (string $subdomain) => view('tenant.dashboard'))
        ->name('tenant.dashboard');

    Route::get('/settings/hub', fn (string $subdomain) => view('tenant.settings-hub'))
        ->name('tenant.settings.hub');

    Route::get('/settings/usage', fn (string $subdomain) => view('tenant.usage'))
        ->name('tenant.settings.usage');

    Route::get('/settings/notifications', fn (string $subdomain) => view('tenant.notifications'))
        ->name('tenant.settings.notifications');

    Route::get('/billing', [App\Http\Controllers\Billing\BillingController::class, 'index'])
        ->name('billing.index');
    Route::get('/pricing', [App\Http\Controllers\Billing\BillingController::class, 'pricing'])
        ->name('tenant.pricing');
    Route::post('/billing/checkout', [App\Http\Controllers\Billing\CheckoutController::class, 'store'])
        ->name('billing.checkout');

    Route::get('/settings', [OrgSettingsController::class, 'index'])
        ->name('tenant.settings.index');
    Route::post('/settings', [OrgSettingsController::class, 'update'])
        ->name('tenant.settings.update');
    Route::post('/settings/verify-domain', [OrgSettingsController::class, 'verifyDomain'])
        ->name('tenant.settings.verify-domain');

    Route::get('/settings/billing', [App\Http\Controllers\Tenant\BillingSettingsController::class, 'index'])
        ->name('tenant.settings.billing');
    Route::post('/settings/billing', [App\Http\Controllers\Tenant\BillingSettingsController::class, 'update'])
        ->name('tenant.settings.billing.update');

    Route::get('/settings/payments', [App\Http\Controllers\Tenant\PaymentSettingsController::class, 'index'])
        ->name('tenant.settings.payments.index');
    Route::post('/settings/payments', [App\Http\Controllers\Tenant\PaymentSettingsController::class, 'update'])
        ->name('tenant.settings.payments.update');

    Route::post('users/all', [UserController::class, 'getAllUsers'])->name('tenant.users.all');
    Route::resource('users', UserController::class)->names('tenant.users');
    Route::get('roles/{role}/duplicate', [RoleController::class, 'duplicateForm'])->name('tenant.roles.duplicate.form');
    Route::post('roles/{role}/duplicate', [RoleController::class, 'duplicate'])->name('tenant.roles.duplicate');
    Route::resource('roles', RoleController::class)->names('tenant.roles');
    Route::resource('api-keys', App\Http\Controllers\Tenant\ApiKeyController::class)
        ->names('tenant.api-keys')
        ->only(['index', 'store', 'destroy']);
    Route::resource('llm-usage', App\Http\Controllers\Tenant\LlmUsageController::class)
        ->names('tenant.llm-usage')
        ->only(['index']);

    // Merchant Finance & Sales
    Route::get('/finance', [App\Http\Controllers\Tenant\FinanceController::class, 'index'])->name('tenant.finance.index');
    Route::post('/finance/refund/{transaction}', [App\Http\Controllers\Tenant\FinanceController::class, 'refund'])->name('tenant.finance.refund');

    Route::prefix('llm-config')->name('tenant.llm-config.')->group(function () {
        Route::get('/', [App\Http\Controllers\Tenant\LlmConfigController::class, 'index'])->name('index');
        Route::put('/', [App\Http\Controllers\Tenant\LlmConfigController::class, 'update'])->name('update');
        Route::delete('/{provider}', [App\Http\Controllers\Tenant\LlmConfigController::class, 'destroy'])->name('destroy');
    });

    // Onboarding Wizard (duplicated for subdomains)
    Route::group(['prefix' => 'onboarding', 'as' => 'tenant.onboarding.'], function () {
        Route::get('wizard', [App\Http\Controllers\Admin\OnboardingController::class, 'index'])->name('wizard');
        Route::post('branding', [App\Http\Controllers\Admin\OnboardingController::class, 'updateBranding'])->name('branding.update');
        Route::post('finish', [App\Http\Controllers\Admin\OnboardingController::class, 'finish'])->name('finish');
    });

    // LLM Billing
    Route::post('/billing/llm-checkout', [App\Http\Controllers\Tenant\LlmCheckoutController::class, 'store'])
        ->name('billing.llm-checkout');

});
