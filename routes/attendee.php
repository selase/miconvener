<?php

declare(strict_types=1);

use App\Http\Controllers\Public\PlatformAttendeeAccessController;
use Illuminate\Support\Facades\Route;

Route::prefix('my')->group(function (): void {
    Route::get('/', [PlatformAttendeeAccessController::class, 'page'])->name('attendee.my');
});
