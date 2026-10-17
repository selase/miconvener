<?php

declare(strict_types=1);

use App\Http\Controllers\Public\PlatformAttendeeAccessController;
use App\Http\Controllers\Public\PlatformAttendeeWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('my')->group(function (): void {
    Route::get('/join/{event}', [PlatformAttendeeWorkspaceController::class, 'join'])->whereUuid('event')->name('attendee.my.join');
    Route::get('/', [PlatformAttendeeAccessController::class, 'page'])->name('attendee.my');
    Route::get('/manifest.json', [PlatformAttendeeAccessController::class, 'manifest'])->name('attendee.my.manifest');
    Route::post('/verify/phone/send', [App\Http\Controllers\Public\CommunityPhoneAccessController::class, 'send'])->name('attendee.my.phone.send');
    Route::post('/verify/phone/confirm', [App\Http\Controllers\Public\CommunityPhoneAccessController::class, 'confirm'])->name('attendee.my.phone.confirm');
    Route::post('/verify/phone/forget', [App\Http\Controllers\Public\CommunityPhoneAccessController::class, 'forget'])->middleware('throttle:public-registration')->name('attendee.my.phone.forget');
    Route::post('/verify/send', [PlatformAttendeeAccessController::class, 'send'])->name('attendee.my.verify.send');
    Route::post('/verify/confirm', [PlatformAttendeeAccessController::class, 'confirm'])->name('attendee.my.verify.confirm');
    Route::post('/verify/forget', [PlatformAttendeeAccessController::class, 'forget'])->name('attendee.my.verify.forget');
    Route::get('/session', [PlatformAttendeeAccessController::class, 'session'])->middleware('platform_attendee_verified')->name('attendee.my.session');

    Route::middleware(['community.identity', 'throttle:community-access'])->group(function (): void {
        Route::get('/identity', [App\Http\Controllers\Public\MyCommunitiesController::class, 'identity'])->name('attendee.my.identity');
        Route::get('/communities', [App\Http\Controllers\Public\MyCommunitiesController::class, 'index'])->name('attendee.my.communities.index');
        Route::get('/communities/{tenantSlug}/options', [App\Http\Controllers\Public\MyCommunitiesController::class, 'options'])->name('attendee.my.communities.options');
        Route::get('/communities/{tenantSlug}', [App\Http\Controllers\Public\MyCommunitiesController::class, 'show'])->name('attendee.my.communities.show');
        Route::get('/communities/{tenantSlug}/members/{member}', [App\Http\Controllers\Public\MyCommunitiesController::class, 'member'])->name('attendee.my.communities.member');
        Route::get('/communities/{tenantSlug}/members/{member}/charges', [App\Http\Controllers\Public\MyCommunitiesController::class, 'charges'])->name('attendee.my.communities.charges');
        Route::get('/communities/{tenantSlug}/members/{member}/receipts/{charge}', [App\Http\Controllers\Public\MyCommunitiesController::class, 'receipt'])->name('attendee.my.communities.receipt');
        Route::post('/communities/{tenantSlug}/join', [App\Http\Controllers\Public\CommunityJoinController::class, 'store'])->name('attendee.my.joins.store');
        Route::get('/communities/{tenantSlug}/joins/{join}', [App\Http\Controllers\Public\CommunityJoinController::class, 'show'])->name('attendee.my.joins.show');
        Route::post('/communities/{tenantSlug}/joins/{join}/credential', [App\Http\Controllers\Public\CommunityJoinController::class, 'credential'])->name('attendee.my.joins.credential');
        Route::post('/communities/{tenantSlug}/follow', [App\Http\Controllers\Public\CommunityFollowController::class, 'store'])->name('attendee.my.follow.store');
        Route::delete('/communities/{tenantSlug}/follow', [App\Http\Controllers\Public\CommunityFollowController::class, 'destroy'])->name('attendee.my.follow.destroy');
        Route::post('/communities/{tenantSlug}/wrong-number', [App\Http\Controllers\Public\CommunityWrongNumberController::class, 'store'])->name('attendee.my.wrong-number');
        Route::post('/communities/{tenantSlug}/access', [App\Http\Controllers\Public\CommunityClaimController::class, 'recover'])->name('attendee.my.access.recover');
        Route::post('/communities/{tenantSlug}/members/{member}/phone-access', [App\Http\Controllers\Public\CommunityClaimController::class, 'enrolPhone'])->name('attendee.my.access.enrol');
        Route::post('/communities/{tenantSlug}/claims', [App\Http\Controllers\Public\CommunityClaimController::class, 'store'])->name('attendee.my.claims.store');
        Route::get('/communities/{tenantSlug}/claims/{claim}', [App\Http\Controllers\Public\CommunityClaimController::class, 'show'])->name('attendee.my.claims.show');
        Route::post('/communities/{tenantSlug}/claims/{claim}/complete', [App\Http\Controllers\Public\CommunityClaimController::class, 'complete'])->name('attendee.my.claims.complete');
    });

    // Authenticated history endpoints
    Route::middleware('platform_attendee_verified')->group(function (): void {
        Route::get('/app/events', [PlatformAttendeeAccessController::class, 'appEvents'])->name('attendee.my.app.events');
        Route::get('/app/tickets/{registration}', [PlatformAttendeeWorkspaceController::class, 'ticketPass'])->whereUuid('registration')->name('attendee.my.app.ticket');
        Route::get('/events', [PlatformAttendeeAccessController::class, 'events'])->name('attendee.my.events');
        Route::get('/certificates', [PlatformAttendeeAccessController::class, 'certificates'])->name('attendee.my.certificates');
        Route::get('/abstracts', [PlatformAttendeeAccessController::class, 'abstracts'])->name('attendee.my.abstracts');
        Route::get('/attendance', [PlatformAttendeeAccessController::class, 'attendance'])->name('attendee.my.attendance');
        Route::get('/contributions', [PlatformAttendeeAccessController::class, 'contributions'])->name('attendee.my.contributions');
        Route::get('/contributions/{contribution}/receipt', [PlatformAttendeeAccessController::class, 'downloadReceipt'])->middleware('throttle:60,1')->name('attendee.my.contributions.receipt');
        Route::patch('/contributions/{contribution}/tribute', [PlatformAttendeeAccessController::class, 'updateTribute'])->name('attendee.my.contributions.tribute');
    });

    // Event workspace endpoints (authorized via platform email proof or checkout grant)
    Route::prefix('events/{registration}')->group(function (): void {
        Route::get('/', [PlatformAttendeeWorkspaceController::class, 'show'])->name('attendee.my.events.workspace');
        Route::get('/status', [PlatformAttendeeWorkspaceController::class, 'status'])->name('attendee.my.events.status');
        Route::get('/ticket', [PlatformAttendeeWorkspaceController::class, 'ticket'])->name('attendee.my.events.ticket');
        Route::post('/check-in', [PlatformAttendeeWorkspaceController::class, 'checkIn'])->name('attendee.my.events.check-in');
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
