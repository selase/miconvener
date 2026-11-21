<?php

declare(strict_types=1);

use App\Http\Controllers\Workspaces\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::domain((string) parse_url((string) config('app.url'), PHP_URL_HOST))->middleware(['auth', 'verified', '2fa_challenge'])->prefix('workspaces')->name('workspaces.')->group(function (): void {
    Route::get('/', [WorkspaceController::class, 'index'])->name('index');
    Route::get('/create', [WorkspaceController::class, 'create'])->name('create');
    Route::get('/account', [App\Http\Controllers\Tenant\AccountController::class, 'index'])->name('account');
    Route::post('/account/two-factor/setup', [App\Http\Controllers\Tenant\AccountController::class, 'setup'])->name('account.two-factor.setup');
    Route::post('/account/two-factor/confirm', [App\Http\Controllers\Tenant\AccountController::class, 'confirm'])->middleware('throttle:6,1')->name('account.two-factor.confirm');
    Route::post('/account/two-factor/disable', [App\Http\Controllers\Tenant\AccountController::class, 'disable'])->middleware('throttle:6,1')->name('account.two-factor.disable');
    Route::post('/account/two-factor/recovery-codes', [App\Http\Controllers\Tenant\AccountController::class, 'regenerateRecoveryCodes'])->middleware('throttle:6,1')->name('account.two-factor.recovery-codes');
    Route::post('/', [WorkspaceController::class, 'store'])->middleware('throttle:10,1')->name('store');
});
