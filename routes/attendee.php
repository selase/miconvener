<?php

declare(strict_types=1);

use App\Http\Controllers\Public\PlatformAttendeeAccessController;
use App\Http\Controllers\Public\PlatformAttendeeWorkspaceController;
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

    // Event workspace endpoints (authorized via platform email proof or checkout grant)
    Route::prefix('events/{registration}')->group(function (): void {
        Route::get('/', [PlatformAttendeeWorkspaceController::class, 'show'])->name('attendee.my.events.workspace');
        Route::get('/status', [PlatformAttendeeWorkspaceController::class, 'status'])->name('attendee.my.events.status');
        Route::get('/ticket', [PlatformAttendeeWorkspaceController::class, 'ticket'])->name('attendee.my.events.ticket');
        Route::post('/transfer', [PlatformAttendeeWorkspaceController::class, 'transfer'])->middleware('throttle:public-registration')->name('attendee.my.events.transfer');
        Route::post('/transfer/confirm', [PlatformAttendeeWorkspaceController::class, 'confirmTransfer'])->middleware('throttle:public-registration')->name('attendee.my.events.transfer.confirm');
        Route::post('/agenda/{session}', [PlatformAttendeeWorkspaceController::class, 'addToAgenda'])->name('attendee.my.events.agenda.add');
        Route::delete('/agenda/{session}', [PlatformAttendeeWorkspaceController::class, 'removeFromAgenda'])->name('attendee.my.events.agenda.remove');
        Route::post('/service-requests', [PlatformAttendeeWorkspaceController::class, 'storeServiceRequest'])->name('attendee.my.events.service-requests.store');
        Route::get('/materials/{material}/download', [PlatformAttendeeWorkspaceController::class, 'downloadMaterial'])->name('attendee.my.events.materials.download');
    });
});
