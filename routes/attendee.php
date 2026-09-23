<?php

declare(strict_types=1);

use App\Http\Controllers\Public\PlatformAttendeeAccessController;
use Illuminate\Support\Facades\Route;

Route::prefix('my')->group(function (): void {
    Route::get('/', [PlatformAttendeeAccessController::class, 'page'])->name('attendee.my');
    Route::post('/verify/send', [PlatformAttendeeAccessController::class, 'send'])->name('attendee.my.verify.send');
    Route::post('/verify/confirm', [PlatformAttendeeAccessController::class, 'confirm'])->name('attendee.my.verify.confirm');
    Route::post('/verify/forget', [PlatformAttendeeAccessController::class, 'forget'])->name('attendee.my.verify.forget');
    Route::get('/session', [PlatformAttendeeAccessController::class, 'session'])->middleware('platform_attendee_verified')->name('attendee.my.session');

    // Authenticated history endpoints
    Route::middleware('platform_attendee_verified')->group(function (): void {
        Route::get('/events', [PlatformAttendeeAccessController::class, 'events'])->name('attendee.my.events');
        Route::get('/certificates', [PlatformAttendeeAccessController::class, 'certificates'])->name('attendee.my.certificates');
        Route::get('/abstracts', [PlatformAttendeeAccessController::class, 'abstracts'])->name('attendee.my.abstracts');
        Route::get('/attendance', [PlatformAttendeeAccessController::class, 'attendance'])->name('attendee.my.attendance');
    });
});
