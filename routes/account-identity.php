<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AccountEmailChangeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', '2fa_challenge'])
    ->prefix('account-identity/email-changes')->name('account-identity.email-changes.')
    ->group(function (): void {
        Route::post('/', [AccountEmailChangeController::class, 'store'])->middleware('throttle:5,1')->name('store');
        Route::get('/{change}', [AccountEmailChangeController::class, 'show'])->whereUuid('change')->name('show');
        Route::post('/{change}/confirm', [AccountEmailChangeController::class, 'confirm'])->whereUuid('change')->middleware('throttle:5,1')->name('confirm');
    });
