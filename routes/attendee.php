<?php

declare(strict_types=1);

use App\Http\Controllers\Public\PlatformAttendeeAccessController;
use App\Http\Controllers\Public\PlatformAttendeeWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('my')->group(function (): void {
    Route::get('/', [PlatformAttendeeAccessController::class, 'page'])->name('attendee.my');
    Route::get('/manifest.json', [PlatformAttendeeAccessController::class, 'manifest'])->name('attendee.my.manifest');
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
        Route::get('/service-requests', [PlatformAttendeeWorkspaceController::class, 'serviceRequests'])->name('attendee.my.events.service-requests.index');
        Route::post('/service-requests', [PlatformAttendeeWorkspaceController::class, 'storeServiceRequest'])->middleware('throttle:public-registration')->name('attendee.my.events.service-requests.store');
        Route::get('/materials/{material}/download', [PlatformAttendeeWorkspaceController::class, 'downloadMaterial'])->name('attendee.my.events.materials.download');

        // Contextual Actions: Polls, Forum, Dynamic Forms
        Route::get('/poll', [PlatformAttendeeWorkspaceController::class, 'poll'])->name('attendee.my.events.poll.show');
        Route::post('/poll/{poll}/respond', [PlatformAttendeeWorkspaceController::class, 'respondPoll'])->middleware('throttle:public-registration')->name('attendee.my.events.poll.respond');

        Route::get('/forum', [PlatformAttendeeWorkspaceController::class, 'forum'])->name('attendee.my.events.forum.index');
        Route::post('/forum', [PlatformAttendeeWorkspaceController::class, 'storeForumThread'])->middleware('throttle:public-registration')->name('attendee.my.events.forum.store');
        Route::post('/forum/{thread}/vote', [PlatformAttendeeWorkspaceController::class, 'voteForumThread'])->name('attendee.my.events.forum.vote');
        Route::post('/forum/{thread}/unvote', [PlatformAttendeeWorkspaceController::class, 'unvoteForumThread'])->name('attendee.my.events.forum.unvote');

        Route::get('/forms', [PlatformAttendeeWorkspaceController::class, 'forms'])->name('attendee.my.events.forms.index');
        Route::get('/forms/{form}', [PlatformAttendeeWorkspaceController::class, 'showForm'])->name('attendee.my.events.forms.show');
        Route::post('/forms/{form}', [PlatformAttendeeWorkspaceController::class, 'submitForm'])->middleware('throttle:public-registration')->name('attendee.my.events.forms.submit');
    });
});
