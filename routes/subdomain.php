<?php

declare(strict_types=1);

use App\Http\Controllers\Public\AttendeePortalController;
use App\Http\Controllers\Public\BlastOpenController;
use App\Http\Controllers\Public\EventCheckoutController;
use App\Http\Controllers\Public\MaterialDownloadController;
use App\Http\Controllers\Public\PublicEventController;
use App\Http\Controllers\Public\PublicForumController;
use App\Http\Controllers\Public\PublicPollController;
use App\Http\Controllers\Public\ScheduleIcsController;
use App\Http\Controllers\Public\ServiceRequestController as PublicServiceRequestController;
use App\Http\Controllers\Public\SpeakerPortalController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\DesignSystemController;
use App\Http\Controllers\Tenant\EventBadgeController;
use App\Http\Controllers\Tenant\EventBlastController;
use App\Http\Controllers\Tenant\EventCheckInController;
use App\Http\Controllers\Tenant\EventController;
use App\Http\Controllers\Tenant\EventFinanceController;
use App\Http\Controllers\Tenant\EventFormFieldController;
use App\Http\Controllers\Tenant\EventForumController;
use App\Http\Controllers\Tenant\EventMaterialController;
use App\Http\Controllers\Tenant\EventPollController;
use App\Http\Controllers\Tenant\EventRegistrationController;
use App\Http\Controllers\Tenant\EventReportController;
use App\Http\Controllers\Tenant\EventServiceRequestController;
use App\Http\Controllers\Tenant\EventSessionController;
use App\Http\Controllers\Tenant\EventSpeakerController;
use App\Http\Controllers\Tenant\EventSponsorController;
use App\Http\Controllers\Tenant\EventTicketTypeController;
use App\Http\Controllers\Tenant\EventVenueController;
use App\Http\Controllers\Tenant\OnboardingController;
use App\Http\Controllers\Tenant\OrgSettingsController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\SpeakerController;
use App\Http\Controllers\Tenant\TenantPayoutAccountController;
use App\Http\Controllers\Tenant\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/tenant-test', function () {
    $tenant = app(App\Services\Tenancy\TenantContext::class)->getTenant();

    return 'Tenant: '.($tenant?->name ?? 'None');
});

Route::group(['middleware' => ['auth', '2fa_challenge', 'onboarding']], function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('tenant.dashboard');

    Route::get('/design-system', [DesignSystemController::class, 'index'])
        ->name('tenant.design-system');

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
    Route::post('/settings/payments/settlement-mode', [App\Http\Controllers\Tenant\PaymentSettingsController::class, 'updateSettlementMode'])
        ->name('tenant.settings.payments.settlement-mode');
    Route::post('/settings/payments/platform-fee', [App\Http\Controllers\Tenant\PaymentSettingsController::class, 'updatePlatformFee'])
        ->name('tenant.settings.payments.platform-fee');

    Route::resource('users', UserController::class)->names('tenant.users')->except(['show', 'create', 'edit']);
    Route::get('roles/{role}/duplicate', [RoleController::class, 'duplicateForm'])->name('tenant.roles.duplicate.form');
    Route::post('roles/{role}/duplicate', [RoleController::class, 'duplicate'])->name('tenant.roles.duplicate');
    Route::resource('roles', RoleController::class)->names('tenant.roles')->except(['show', 'create', 'edit']);
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

    // Onboarding Wizard
    Route::group(['prefix' => 'onboarding', 'as' => 'tenant.onboarding.'], function () {
        Route::get('wizard', [OnboardingController::class, 'index'])->name('wizard');
        Route::post('branding', [OnboardingController::class, 'updateBranding'])->name('branding.update');
        Route::post('finish', [OnboardingController::class, 'finish'])->name('finish');
    });

    // LLM Billing
    Route::post('/billing/llm-checkout', [App\Http\Controllers\Tenant\LlmCheckoutController::class, 'store'])
        ->name('billing.llm-checkout');

    // Events (host console)
    Route::resource('events', EventController::class)->names('tenant.events')->except(['create', 'edit']);
    Route::get('events/{event}/guests/export', [EventController::class, 'exportGuests'])->name('tenant.events.guests.export');
    Route::post('events/{event}/registrations/{registration}/approve', [EventRegistrationController::class, 'approve'])->name('tenant.events.registrations.approve');
    Route::post('events/{event}/registrations/{registration}/reject', [EventRegistrationController::class, 'reject'])->name('tenant.events.registrations.reject');
    Route::post('events/{event}/registrations/{registration}/cancel', [EventRegistrationController::class, 'cancel'])->name('tenant.events.registrations.cancel');
    Route::get('events/{event}/checkin/search', [EventCheckInController::class, 'search'])->name('tenant.events.checkin.search');
    Route::post('events/{event}/checkin/scan', [EventCheckInController::class, 'scan'])->name('tenant.events.checkin.scan');
    Route::post('events/{event}/checkin/{registration}', [EventCheckInController::class, 'checkIn'])->name('tenant.events.checkin');

    Route::post('events/{event}/ticket-types', [EventTicketTypeController::class, 'store'])->name('tenant.events.ticket-types.store');
    Route::put('events/{event}/ticket-types/{ticketType}', [EventTicketTypeController::class, 'update'])->name('tenant.events.ticket-types.update');
    Route::patch('events/{event}/ticket-types/{ticketType}/badge-tier', [EventTicketTypeController::class, 'updateBadgeTier'])->name('tenant.events.ticket-types.badge-tier');
    Route::delete('events/{event}/ticket-types/{ticketType}', [EventTicketTypeController::class, 'destroy'])->name('tenant.events.ticket-types.destroy');

    Route::post('events/{event}/sessions', [EventSessionController::class, 'store'])->name('tenant.events.sessions.store');
    Route::patch('events/{event}/sessions/reorder', [EventSessionController::class, 'reorder'])->name('tenant.events.sessions.reorder');
    Route::put('events/{event}/sessions/{session}', [EventSessionController::class, 'update'])->name('tenant.events.sessions.update');
    Route::delete('events/{event}/sessions/{session}', [EventSessionController::class, 'destroy'])->name('tenant.events.sessions.destroy');
    Route::get('events/{event}/sessions/occupancy', [App\Http\Controllers\Tenant\EventSessionCheckInController::class, 'occupancy'])->name('tenant.events.sessions.occupancy');
    Route::post('events/{event}/sessions/{session}/scan', [App\Http\Controllers\Tenant\EventSessionCheckInController::class, 'scan'])->name('tenant.events.sessions.scan');
    Route::get('events/{event}/sessions/{session}/attendees', [App\Http\Controllers\Tenant\EventSessionCheckInController::class, 'attendees'])->name('tenant.events.sessions.attendees');

    Route::post('events/{event}/speakers', [EventSpeakerController::class, 'store'])->name('tenant.events.speakers.store');
    Route::delete('events/{event}/speakers/{speaker}', [EventSpeakerController::class, 'destroy'])->name('tenant.events.speakers.destroy');

    Route::resource('speakers', SpeakerController::class)->names('tenant.speakers')->only(['index', 'store', 'update', 'destroy']);

    Route::get('events/{event}/blasts', [EventBlastController::class, 'index'])->name('tenant.events.blasts.index');
    Route::post('events/{event}/blasts', [EventBlastController::class, 'store'])->name('tenant.events.blasts.store');
    Route::patch('events/{event}/blasts/{blast}/cancel', [EventBlastController::class, 'cancel'])->name('tenant.events.blasts.cancel');

    Route::post('events/{event}/materials', [EventMaterialController::class, 'store'])->name('tenant.events.materials.store');
    Route::delete('events/{event}/materials/{material}', [EventMaterialController::class, 'destroy'])->name('tenant.events.materials.destroy');

    Route::post('events/{event}/venue/rooms', [EventVenueController::class, 'storeRoom'])->name('tenant.events.venue.rooms.store');
    Route::delete('events/{event}/venue/rooms/{room}', [EventVenueController::class, 'destroyRoom'])->name('tenant.events.venue.rooms.destroy');
    Route::post('events/{event}/venue/rooms/{room}/seats', [EventVenueController::class, 'assignSeat'])->name('tenant.events.venue.seats.assign');
    Route::delete('events/{event}/venue/rooms/{room}/seats/{assignment}', [EventVenueController::class, 'unassignSeat'])->name('tenant.events.venue.seats.unassign');
    Route::get('events/{event}/venue/unseated', [EventVenueController::class, 'searchUnseated'])->name('tenant.events.venue.unseated');

    Route::get('events/{event}/form-fields', [EventFormFieldController::class, 'index'])->name('tenant.events.form-fields.index');
    Route::post('events/{event}/form-fields', [EventFormFieldController::class, 'store'])->name('tenant.events.form-fields.store');
    Route::put('events/{event}/form-fields/{field}', [EventFormFieldController::class, 'update'])->name('tenant.events.form-fields.update');
    Route::delete('events/{event}/form-fields/{field}', [EventFormFieldController::class, 'destroy'])->name('tenant.events.form-fields.destroy');
    Route::post('events/{event}/form-fields/reorder', [EventFormFieldController::class, 'reorder'])->name('tenant.events.form-fields.reorder');
    Route::match(['post', 'put'], 'events/{event}/registration-settings', [EventFormFieldController::class, 'updateSettings'])->name('tenant.events.registration-settings.update');
    Route::get('events/{event}/promo-codes', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'index'])->name('tenant.events.promo-codes.index');
    Route::post('events/{event}/promo-codes', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'store'])->name('tenant.events.promo-codes.store');
    Route::put('events/{event}/promo-codes/{promoCode}', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'update'])->name('tenant.events.promo-codes.update');
    Route::delete('events/{event}/promo-codes/{promoCode}', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'destroy'])->name('tenant.events.promo-codes.destroy');
    Route::patch('events/{event}/promo-codes/{promoCode}/toggle', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'toggle'])->name('tenant.events.promo-codes.toggle');

    Route::get('events/{event}/forum', [EventForumController::class, 'index'])->name('tenant.events.forum.index');
    Route::post('events/{event}/forum/{thread}/replies', [EventForumController::class, 'reply'])->name('tenant.events.forum.reply');
    Route::patch('events/{event}/forum/{thread}', [EventForumController::class, 'moderate'])->name('tenant.events.forum.moderate');
    Route::delete('events/{event}/forum/{thread}', [EventForumController::class, 'destroy'])->name('tenant.events.forum.destroy');
    Route::patch('events/{event}/forum/{thread}/ban', [EventForumController::class, 'ban'])->name('tenant.events.forum.ban');
    Route::delete('events/{event}/forum/bans/{ban}', [EventForumController::class, 'unban'])->name('tenant.events.forum.bans.destroy');

    Route::get('events/{event}/service-requests', [EventServiceRequestController::class, 'index'])->name('tenant.events.service-requests.index');
    Route::patch('events/{event}/service-requests/{serviceRequest}/claim', [EventServiceRequestController::class, 'claim'])->name('tenant.events.service-requests.claim');
    Route::patch('events/{event}/service-requests/{serviceRequest}', [EventServiceRequestController::class, 'updateStatus'])->name('tenant.events.service-requests.status');

    Route::get('events/{event}/polls', [EventPollController::class, 'index'])->name('tenant.events.polls.index');
    Route::post('events/{event}/polls', [EventPollController::class, 'store'])->name('tenant.events.polls.store');
    Route::patch('events/{event}/polls/{poll}', [EventPollController::class, 'updateStatus'])->name('tenant.events.polls.status');
    Route::delete('events/{event}/polls/{poll}', [EventPollController::class, 'destroy'])->name('tenant.events.polls.destroy');
    Route::patch('events/{event}/polls/{poll}/responses/{response}', [EventPollController::class, 'moderateResponse'])->name('tenant.events.polls.responses.moderate');
    Route::get('events/{event}/quiz/leaderboard', [EventPollController::class, 'leaderboard'])->name('tenant.events.quiz.leaderboard');

    Route::get('events/{event}/badges', [EventBadgeController::class, 'index'])->name('tenant.events.badges.index');
    Route::post('events/{event}/badges/print-log', [EventBadgeController::class, 'logPrint'])->name('tenant.events.badges.print-log');

    Route::get('events/{event}/finance', [EventFinanceController::class, 'index'])->name('tenant.events.finance.index');
    Route::post('events/{event}/finance/payout-schedule', [EventFinanceController::class, 'updatePayoutSchedule'])->name('tenant.events.finance.payout-schedule.update');
    Route::post('events/{event}/finance/payouts', [EventFinanceController::class, 'storePayout'])->name('tenant.events.finance.payouts.store');
    Route::patch('events/{event}/finance/payouts/{payout}', [EventFinanceController::class, 'updatePayoutStatus'])->name('tenant.events.finance.payouts.status');
    Route::post('events/{event}/finance/payouts/{payout}/send', [EventFinanceController::class, 'sendPayout'])->name('tenant.events.finance.payouts.send');
    Route::get('events/{event}/finance/settlement-statement', [EventFinanceController::class, 'exportSettlementStatement'])->name('tenant.events.finance.settlement-statement');

    Route::get('payout-accounts', [TenantPayoutAccountController::class, 'index'])->name('tenant.payout-accounts.index');
    Route::get('payout-accounts/banks', [TenantPayoutAccountController::class, 'banks'])->name('tenant.payout-accounts.banks');
    Route::post('payout-accounts', [TenantPayoutAccountController::class, 'store'])->name('tenant.payout-accounts.store');
    Route::delete('payout-accounts/{account}', [TenantPayoutAccountController::class, 'destroy'])->name('tenant.payout-accounts.destroy');

    Route::get('events/{event}/sponsors', [EventSponsorController::class, 'index'])->name('tenant.events.sponsors.index');
    Route::post('events/{event}/sponsors', [EventSponsorController::class, 'store'])->name('tenant.events.sponsors.store');
    Route::delete('events/{event}/sponsors/{sponsor}', [EventSponsorController::class, 'destroy'])->name('tenant.events.sponsors.destroy');
    Route::post('events/{event}/sponsors/{sponsor}/deliverables', [EventSponsorController::class, 'storeDeliverable'])->name('tenant.events.sponsors.deliverables.store');
    Route::patch('events/{event}/sponsors/{sponsor}/deliverables/{deliverable}', [EventSponsorController::class, 'updateDeliverable'])->name('tenant.events.sponsors.deliverables.update');
    Route::delete('events/{event}/sponsors/{sponsor}/deliverables/{deliverable}', [EventSponsorController::class, 'destroyDeliverable'])->name('tenant.events.sponsors.deliverables.destroy');
    Route::get('events/{event}/sponsors/export', [EventSponsorController::class, 'exportDeliverables'])->name('tenant.events.sponsors.export');

    Route::get('events/{event}/reports', [EventReportController::class, 'index'])->name('tenant.events.reports.index');
    Route::get('events/{event}/reports/registrations', [EventReportController::class, 'exportRegistrations'])->name('tenant.events.reports.registrations');
    Route::get('events/{event}/reports/checkins', [EventReportController::class, 'exportCheckins'])->name('tenant.events.reports.checkins');
    Route::get('events/{event}/reports/forum', [EventReportController::class, 'exportForum'])->name('tenant.events.reports.forum');
    Route::get('events/{event}/reports/polls', [EventReportController::class, 'exportPolls'])->name('tenant.events.reports.polls');
    Route::get('events/{event}/reports/attendee-directory', [EventReportController::class, 'exportAttendeeDirectory'])->name('tenant.events.reports.attendee-directory');
    Route::get('events/{event}/reports/session-attendance', [EventReportController::class, 'exportSessionAttendance'])->name('tenant.events.reports.session-attendance');
    Route::get('events/{event}/reports/dietary-accessibility', [EventReportController::class, 'exportDietaryAccessibility'])->name('tenant.events.reports.dietary-accessibility');
    Route::get('events/{event}/reports/audit-log', [EventReportController::class, 'exportAuditLog'])->name('tenant.events.reports.audit-log');
    Route::get('events/{event}/reports/certificates', [EventReportController::class, 'exportCertificates'])->name('tenant.events.reports.certificates');

    Route::patch('events/{event}/visibility', [EventController::class, 'updateVisibility'])->name('tenant.events.visibility');
    Route::get('events/{event}/speakers/{speaker}/portal-link', [EventSpeakerController::class, 'portalLink'])->name('tenant.events.speakers.portal-link');

});

// Public event pages (no auth — attendees register here)
Route::get('/e/{event}', [PublicEventController::class, 'show'])->name('public.events.show');
Route::post('/e/{event}/validate-promo', [PublicEventController::class, 'validatePromo'])->name('public.events.validate-promo');
Route::post('/e/{event}/unlock-tickets', [PublicEventController::class, 'unlockTicketTypes'])->name('public.events.unlock-tickets');
Route::post('/e/{event}/register', [PublicEventController::class, 'register'])->middleware('throttle:public-registration')->name('public.events.register');
Route::get('/e/{event}/checkout/{registration}', [EventCheckoutController::class, 'checkout'])->name('public.events.checkout');
Route::post('/e/{event}/find-ticket', [App\Http\Controllers\Public\TicketRecoveryController::class, 'store'])->middleware('throttle:public-registration')->name('public.events.find-ticket');
Route::get('/e/{event}/registrations/{registration}/verify', [PublicEventController::class, 'verify'])->middleware('signed')->name('public.events.registrations.verify');
Route::get('/e/{event}/registrations/{registration}', [PublicEventController::class, 'confirmation'])->name('public.events.confirmation');
Route::get('/e/{event}/registrations/{registration}/materials/{material}/download', [MaterialDownloadController::class, 'download'])->name('public.events.materials.download');
Route::post('/e/{event}/registrations/{registration}/agenda/{session}', [AttendeePortalController::class, 'addToAgenda'])->name('public.events.agenda.add');
Route::delete('/e/{event}/registrations/{registration}/agenda/{session}', [AttendeePortalController::class, 'removeFromAgenda'])->name('public.events.agenda.remove');
Route::post('/e/{event}/registrations/{registration}/transfer', [AttendeePortalController::class, 'transfer'])->middleware('throttle:public-registration')->name('public.events.registrations.transfer');
Route::post('/e/{event}/registrations/{registration}/transfer/confirm', [AttendeePortalController::class, 'confirmTransfer'])->middleware('throttle:public-registration')->name('public.events.registrations.transfer.confirm');
Route::post('/e/{event}/registrations/{registration}/service-requests', [PublicServiceRequestController::class, 'store'])->name('public.events.service-requests.store');
Route::get('/e/{event}/schedule.ics', [ScheduleIcsController::class, 'programme'])->name('public.events.schedule.ics');
Route::get('/e/{event}/registrations/{registration}/agenda.ics', [ScheduleIcsController::class, 'agenda'])->name('public.events.agenda.ics');
Route::get('/e/{event}/preview/attendee-portal', [PublicEventController::class, 'attendeePortalPreview'])->name('public.events.preview.attendee');
Route::get('/e/{event}/preview/speaker-portal', [PublicEventController::class, 'speakerPortalPreview'])->name('public.events.preview.speaker');

Route::get('/e/{event}/forum', [PublicForumController::class, 'index'])->name('public.events.forum.index');
Route::post('/e/{event}/forum', [PublicForumController::class, 'store'])->name('public.events.forum.store');
Route::post('/e/{event}/forum/{thread}/vote', [PublicForumController::class, 'vote'])->name('public.events.forum.vote');
Route::delete('/e/{event}/forum/{thread}/vote', [PublicForumController::class, 'unvote'])->name('public.events.forum.unvote');
Route::post('/e/{event}/forum/{thread}/report', [PublicForumController::class, 'report'])->name('public.events.forum.report');

Route::get('/e/{event}/poll', [PublicPollController::class, 'show'])->name('public.events.poll.show');
Route::post('/e/{event}/poll/{poll}/respond', [PublicPollController::class, 'respond'])->name('public.events.poll.respond');
Route::get('/e/{event}/quiz/leaderboard', [PublicPollController::class, 'leaderboard'])->name('public.events.quiz.leaderboard');

Route::get('/e/{event}/speaker-portal/{token}', [SpeakerPortalController::class, 'show'])->name('public.events.speaker-portal');
Route::post('/e/{event}/speaker-portal/{token}/confirm', [SpeakerPortalController::class, 'confirm'])->name('public.events.speaker-portal.confirm');
Route::post('/e/{event}/speaker-portal/{token}/slides', [SpeakerPortalController::class, 'uploadSlides'])->name('public.events.speaker-portal.slides');

Route::get('/blasts/{recipient}/open.gif', BlastOpenController::class)->name('public.blasts.open');

Route::get('/deck', App\Http\Controllers\Marketing\PitchDeckController::class)->name('subdomain.marketing.deck');
Route::get('/pitch', App\Http\Controllers\Marketing\PitchDeckController::class)->name('subdomain.marketing.pitch');
Route::get('/slides', App\Http\Controllers\Marketing\PitchDeckController::class)->name('subdomain.marketing.slides');

Route::get('/media/{path}', [App\Http\Controllers\MediaController::class, 'show'])->where('path', '.*')->name('tenant.media.show');
Route::get('/storage/{path}', [App\Http\Controllers\MediaController::class, 'show'])->where('path', '.*');
