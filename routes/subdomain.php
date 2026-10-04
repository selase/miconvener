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
use App\Http\Controllers\Tenant\AccountController;
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
use App\Http\Controllers\Tenant\EventPollDeckController;
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
    Route::get('/account', [AccountController::class, 'index'])->name('tenant.account');
    Route::post('/account/two-factor/setup', [AccountController::class, 'setup'])->name('tenant.account.two-factor.setup');
    Route::post('/account/two-factor/confirm', [AccountController::class, 'confirm'])
        ->middleware('throttle:two-factor')
        ->name('tenant.account.two-factor.confirm');
    Route::post('/account/two-factor/disable', [AccountController::class, 'disable'])
        ->middleware('throttle:two-factor')
        ->name('tenant.account.two-factor.disable');
    Route::post('/account/two-factor/recovery-codes', [AccountController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:two-factor')
        ->name('tenant.account.two-factor.recovery-codes');

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('tenant.dashboard');

    Route::get('/design-system', [DesignSystemController::class, 'index'])
        ->name('tenant.design-system');

    Route::get('/settings/hub', fn (string $subdomain) => redirect()->route('tenant.settings.index', ['subdomain' => $subdomain]))
        ->name('tenant.settings.hub');

    Route::get('/settings/usage', [App\Http\Controllers\Tenant\UsageController::class, 'index'])
        ->name('tenant.settings.usage');

    Route::get('/settings/notifications', fn (string $subdomain) => redirect()->route('tenant.account', ['subdomain' => $subdomain]))
        ->name('tenant.settings.notifications');

    Route::get('/billing', [App\Http\Controllers\Billing\BillingController::class, 'index'])
        ->name('billing.index');
    Route::get('/pricing', [App\Http\Controllers\Billing\BillingController::class, 'pricing'])
        ->name('tenant.pricing');
    Route::post('/billing/checkout', [App\Http\Controllers\Billing\CheckoutController::class, 'store'])
        ->name('billing.checkout');
    Route::get('/billing/renew', [App\Http\Controllers\Billing\CheckoutController::class, 'renew'])
        ->name('billing.renew');
    Route::get('/billing/addons', [App\Http\Controllers\Tenant\Billing\TenantAddonController::class, 'index'])
        ->name('billing.addons.index');
    Route::post('/billing/addons/checkout', [App\Http\Controllers\Tenant\Billing\TenantAddonController::class, 'checkout'])
        ->name('billing.addons.checkout');
    Route::post('/billing/addons/{addon}/cancel', [App\Http\Controllers\Tenant\Billing\TenantAddonController::class, 'cancel'])
        ->name('billing.addons.cancel');

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

    Route::prefix('settings/webhooks')->name('tenant.settings.webhooks.')->group(function (): void {
        Route::get('/', [App\Http\Controllers\Tenant\WebhookEndpointController::class, 'index'])->name('index');
        Route::post('/', [App\Http\Controllers\Tenant\WebhookEndpointController::class, 'store'])->name('store');
        Route::put('/{endpoint}', [App\Http\Controllers\Tenant\WebhookEndpointController::class, 'update'])->name('update');
        Route::delete('/{endpoint}', [App\Http\Controllers\Tenant\WebhookEndpointController::class, 'destroy'])->name('destroy');
        Route::post('/{endpoint}/rotate-secret', [App\Http\Controllers\Tenant\WebhookEndpointController::class, 'rotateSecret'])->name('rotate-secret');
        Route::post('/{endpoint}/test', [App\Http\Controllers\Tenant\WebhookEndpointController::class, 'test'])->name('test');
        Route::get('/{endpoint}/calls', [App\Http\Controllers\Tenant\WebhookEndpointController::class, 'calls'])->name('calls');
        Route::post('/calls/{call}/retry', [App\Http\Controllers\Tenant\WebhookEndpointController::class, 'retryCall'])->name('calls.retry');
    });

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
    Route::post('events/{event}/restore', [EventController::class, 'restore'])->name('tenant.events.restore');
    Route::delete('events/{event}/force-purge', [EventController::class, 'forcePurge'])->name('tenant.events.force-purge');
    // Each section of an event's workspace has its own address, so refresh, the back
    // button and bookmarks land where you were. Constrained to the known sections so
    // it can never swallow an address meant for something else; the JSON feeds that
    // shared a name with a section live under events/{event}/data/.
    // Two segments, so it is never mistaken for a section.
    Route::get('events/{event}/check-in/door', [EventController::class, 'door'])->name('tenant.events.checkin.door');
    Route::get('events/{event}/{section}', [EventController::class, 'section'])
        ->where('section', implode('|', array_diff(App\Services\Events\EventSections::slugs(), [App\Services\Events\EventSections::OVERVIEW])))
        ->name('tenant.events.section');
    Route::get('events/{event}/guests/export', [EventController::class, 'exportGuests'])->name('tenant.events.guests.export');
    Route::get('events/{event}/contributions/export', [EventController::class, 'exportContributions'])->name('tenant.events.contributions.export');
    Route::patch('events/{event}/contributions/settings', [EventController::class, 'updateContributionSettings'])->name('tenant.events.contributions.settings');
    Route::patch('events/{event}/contributions/{contribution}/toggle-approval', [EventController::class, 'toggleContributionApproval'])->name('tenant.events.contributions.toggle-approval');
    Route::post('events/{event}/registrations/{registration}/approve', [EventRegistrationController::class, 'approve'])->name('tenant.events.registrations.approve');
    Route::post('events/{event}/registrations/{registration}/reject', [EventRegistrationController::class, 'reject'])->name('tenant.events.registrations.reject');
    Route::post('events/{event}/registrations/{registration}/approve-offline', [EventRegistrationController::class, 'approveOfflinePayment'])->name('tenant.events.registrations.approve-offline');
    Route::post('events/{event}/registrations/{registration}/reject-offline', [EventRegistrationController::class, 'rejectOfflinePayment'])->name('tenant.events.registrations.reject-offline');
    Route::get('events/{event}/registrations/{registration}/offline-proof', [EventRegistrationController::class, 'downloadProof'])->name('tenant.events.registrations.offline-proof');
    Route::post('events/{event}/registrations/{registration}/cancel', [EventRegistrationController::class, 'cancel'])->name('tenant.events.registrations.cancel');
    Route::patch('events/{event}/registrations/{registration}', [EventRegistrationController::class, 'update'])->name('tenant.events.registrations.update');
    Route::get('events/{event}/checkin/search', [EventCheckInController::class, 'search'])->name('tenant.events.checkin.search');
    Route::post('events/{event}/checkin/scan', [EventCheckInController::class, 'scan'])->name('tenant.events.checkin.scan');
    Route::post('events/{event}/checkin/{registration}', [EventCheckInController::class, 'checkIn'])->name('tenant.events.checkin');
    Route::get('events/{event}/data/staff-links', [App\Http\Controllers\Tenant\EventStaffLinkController::class, 'index'])->name('tenant.events.staff-links.index');
    Route::post('events/{event}/staff-links', [App\Http\Controllers\Tenant\EventStaffLinkController::class, 'store'])->name('tenant.events.staff-links.store');
    Route::patch('events/{event}/staff-links/{staffLink}', [App\Http\Controllers\Tenant\EventStaffLinkController::class, 'update'])->name('tenant.events.staff-links.update');
    Route::delete('events/{event}/staff-links/{staffLink}', [App\Http\Controllers\Tenant\EventStaffLinkController::class, 'destroy'])->name('tenant.events.staff-links.destroy');

    Route::post('events/{event}/ticket-types', [EventTicketTypeController::class, 'store'])->name('tenant.events.ticket-types.store');
    Route::put('events/{event}/ticket-types/{ticketType}', [EventTicketTypeController::class, 'update'])->name('tenant.events.ticket-types.update');
    Route::patch('events/{event}/ticket-types/{ticketType}/badge-tier', [EventTicketTypeController::class, 'updateBadgeTier'])->name('tenant.events.ticket-types.badge-tier');
    Route::delete('events/{event}/ticket-types/{ticketType}', [EventTicketTypeController::class, 'destroy'])->name('tenant.events.ticket-types.destroy');

    Route::post('events/{event}/sessions', [EventSessionController::class, 'store'])->name('tenant.events.sessions.store');
    Route::patch('events/{event}/sessions/reorder', [EventSessionController::class, 'reorder'])->name('tenant.events.sessions.reorder');
    Route::put('events/{event}/sessions/{session}', [EventSessionController::class, 'update'])->name('tenant.events.sessions.update');
    Route::patch('events/{event}/sessions/{session}/occurrence', [EventSessionController::class, 'updateOccurrence'])->name('tenant.events.sessions.occurrence');
    Route::delete('events/{event}/sessions/{session}', [EventSessionController::class, 'destroy'])->name('tenant.events.sessions.destroy');
    Route::post('events/{event}/recurrence/generate', [EventSessionController::class, 'generateRecurrence'])->name('tenant.events.recurrence.generate');
    Route::post('events/{event}/occurrences/batch-cancel', [EventSessionController::class, 'batchCancel'])->name('tenant.events.occurrences.batch-cancel');
    Route::post('events/{event}/occurrences/batch-reschedule', [EventSessionController::class, 'batchReschedule'])->name('tenant.events.occurrences.batch-reschedule');
    Route::get('events/{event}/occurrences/analytics', [EventSessionController::class, 'analytics'])->name('tenant.events.occurrences.analytics');
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
    Route::patch('events/{event}/materials/speaker-policy', [EventMaterialController::class, 'updateSpeakerPolicy'])->name('tenant.events.materials.speaker-policy');
    Route::delete('events/{event}/materials/{material}', [EventMaterialController::class, 'destroy'])->name('tenant.events.materials.destroy');

    Route::post('events/{event}/venue/rooms', [EventVenueController::class, 'storeRoom'])->name('tenant.events.venue.rooms.store');
    Route::delete('events/{event}/venue/rooms/{room}', [EventVenueController::class, 'destroyRoom'])->name('tenant.events.venue.rooms.destroy');
    Route::post('events/{event}/venue/rooms/{room}/seats', [EventVenueController::class, 'assignSeat'])->name('tenant.events.venue.seats.assign');
    Route::delete('events/{event}/venue/rooms/{room}/seats/{assignment}', [EventVenueController::class, 'unassignSeat'])->name('tenant.events.venue.seats.unassign');
    Route::get('events/{event}/venue/unseated', [EventVenueController::class, 'searchUnseated'])->name('tenant.events.venue.unseated');
    Route::get('events/{event}/venue-collaboration', [App\Http\Controllers\Tenant\EventVenueCollaborationController::class, 'show'])->name('tenant.events.venue-collaboration.show');
    Route::post('events/{event}/venue-collaboration/messages', [App\Http\Controllers\Tenant\EventVenueCollaborationController::class, 'storeMessage'])->name('tenant.events.venue-collaboration.messages.store');
    Route::post('events/{event}/venue-collaboration/inspections', [App\Http\Controllers\Tenant\EventVenueCollaborationController::class, 'storeInspection'])->name('tenant.events.venue-collaboration.inspections.store');

    Route::get('events/{event}/form-fields', [EventFormFieldController::class, 'index'])->name('tenant.events.form-fields.index');
    Route::post('events/{event}/form-fields', [EventFormFieldController::class, 'store'])->name('tenant.events.form-fields.store');
    Route::put('events/{event}/form-fields/{field}', [EventFormFieldController::class, 'update'])->name('tenant.events.form-fields.update');
    Route::delete('events/{event}/form-fields/{field}', [EventFormFieldController::class, 'destroy'])->name('tenant.events.form-fields.destroy');
    Route::post('events/{event}/form-fields/reorder', [EventFormFieldController::class, 'reorder'])->name('tenant.events.form-fields.reorder');
    Route::match(['post', 'put'], 'events/{event}/registration-settings', [EventFormFieldController::class, 'updateSettings'])->name('tenant.events.registration-settings.update');
    Route::get('events/{event}/data/promo-codes', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'index'])->name('tenant.events.promo-codes.index');
    Route::post('events/{event}/promo-codes', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'store'])->name('tenant.events.promo-codes.store');
    Route::put('events/{event}/promo-codes/{promoCode}', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'update'])->name('tenant.events.promo-codes.update');
    Route::delete('events/{event}/promo-codes/{promoCode}', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'destroy'])->name('tenant.events.promo-codes.destroy');
    Route::patch('events/{event}/promo-codes/{promoCode}/toggle', [App\Http\Controllers\Tenant\EventPromoCodeController::class, 'toggle'])->name('tenant.events.promo-codes.toggle');

    Route::get('events/{event}/data/forum', [EventForumController::class, 'index'])->name('tenant.events.forum.index');
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
    Route::get('events/{event}/polls/present-link', [EventPollController::class, 'presentLink'])->name('tenant.events.polls.present-link');
    Route::post('events/{event}/polls/present-link/rotate', [EventPollController::class, 'rotatePresentLink'])->name('tenant.events.polls.present-link.rotate');
    Route::delete('events/{event}/polls/{poll}', [EventPollController::class, 'destroy'])->name('tenant.events.polls.destroy');
    Route::patch('events/{event}/polls/{poll}/responses/{response}', [EventPollController::class, 'moderateResponse'])->name('tenant.events.polls.responses.moderate');
    Route::get('events/{event}/quiz/leaderboard', [EventPollController::class, 'leaderboard'])->name('tenant.events.quiz.leaderboard');

    Route::get('events/{event}/decks', [EventPollDeckController::class, 'index'])->name('tenant.events.decks.index');
    Route::post('events/{event}/decks', [EventPollDeckController::class, 'store'])->name('tenant.events.decks.store');
    Route::put('events/{event}/decks/{deck}/polls', [EventPollDeckController::class, 'setPolls'])->whereUuid('deck')->name('tenant.events.decks.polls');
    Route::delete('events/{event}/decks/{deck}', [EventPollDeckController::class, 'destroy'])->whereUuid('deck')->name('tenant.events.decks.destroy');
    Route::get('events/{event}/decks/{deck}/presenter-link', [EventPollDeckController::class, 'presenterLink'])->whereUuid('deck')->name('tenant.events.decks.presenter-link');
    Route::post('events/{event}/decks/{deck}/presenter-link/rotate', [EventPollDeckController::class, 'rotatePresenterLink'])->whereUuid('deck')->name('tenant.events.decks.presenter-link.rotate');
    Route::post('events/{event}/decks/{deck}/start', [EventPollDeckController::class, 'start'])->whereUuid('deck')->name('tenant.events.decks.start');
    Route::post('events/{event}/decks/{deck}/advance', [EventPollDeckController::class, 'advance'])->whereUuid('deck')->name('tenant.events.decks.advance');
    Route::post('events/{event}/decks/{deck}/previous', [EventPollDeckController::class, 'previous'])->whereUuid('deck')->name('tenant.events.decks.previous');
    Route::post('events/{event}/decks/{deck}/close', [EventPollDeckController::class, 'close'])->whereUuid('deck')->name('tenant.events.decks.close');
    Route::post('events/{event}/decks/{deck}/end', [EventPollDeckController::class, 'end'])->whereUuid('deck')->name('tenant.events.decks.end');

    Route::get('events/{event}/data/badges', [EventBadgeController::class, 'index'])->name('tenant.events.badges.index');
    Route::post('events/{event}/badges/template', [EventBadgeController::class, 'updateTemplate'])->name('tenant.events.badges.template.update');
    Route::get('events/{event}/badges/template/artwork', [EventBadgeController::class, 'artwork'])->name('tenant.events.badges.template.artwork');
    Route::post('events/{event}/badges/print-log', [EventBadgeController::class, 'logPrint'])->name('tenant.events.badges.print-log');
    Route::post('events/{event}/badges/sheet', [EventBadgeController::class, 'sheet'])->name('tenant.events.badges.sheet');

    Route::get('events/{event}/data/finance', [EventFinanceController::class, 'index'])->name('tenant.events.finance.index');
    Route::post('events/{event}/finance/payout-schedule', [EventFinanceController::class, 'updatePayoutSchedule'])->name('tenant.events.finance.payout-schedule.update');
    Route::post('events/{event}/finance/payouts', [EventFinanceController::class, 'storePayout'])->name('tenant.events.finance.payouts.store');
    Route::patch('events/{event}/finance/payouts/{payout}', [EventFinanceController::class, 'updatePayoutStatus'])->name('tenant.events.finance.payouts.status');
    Route::post('events/{event}/finance/payouts/{payout}/send', [EventFinanceController::class, 'sendPayout'])->name('tenant.events.finance.payouts.send');
    Route::post('events/{event}/finance/payouts/{payout}/finalize', [EventFinanceController::class, 'finalizePayout'])->name('tenant.events.finance.payouts.finalize');
    Route::get('events/{event}/finance/settlement-statement', [EventFinanceController::class, 'exportSettlementStatement'])->name('tenant.events.finance.settlement-statement');

    Route::get('payout-accounts', [TenantPayoutAccountController::class, 'index'])->name('tenant.payout-accounts.index');
    Route::get('payout-accounts/banks', [TenantPayoutAccountController::class, 'banks'])->name('tenant.payout-accounts.banks');
    Route::post('payout-accounts', [TenantPayoutAccountController::class, 'store'])->name('tenant.payout-accounts.store');
    Route::delete('payout-accounts/{account}', [TenantPayoutAccountController::class, 'destroy'])->name('tenant.payout-accounts.destroy');

    Route::prefix('venue')->name('tenant.venue.')->group(function (): void {
        Route::get('profile', [App\Http\Controllers\Tenant\Venue\VenueProfileController::class, 'show'])->name('profile');
        Route::match(['post', 'put'], 'profile', [App\Http\Controllers\Tenant\Venue\VenueProfileController::class, 'update'])->name('profile.update');
        Route::post('profile/verify', [App\Http\Controllers\Tenant\Venue\VenueProfileController::class, 'submitVerification'])->name('profile.verify');

        Route::get('spaces', [App\Http\Controllers\Tenant\Venue\VenueListingController::class, 'index'])->name('spaces.index');
        Route::get('spaces/create', [App\Http\Controllers\Tenant\Venue\VenueListingController::class, 'create'])->name('spaces.create');
        Route::post('spaces', [App\Http\Controllers\Tenant\Venue\VenueListingController::class, 'store'])->name('spaces.store');
        Route::get('spaces/{listing}/edit', [App\Http\Controllers\Tenant\Venue\VenueListingController::class, 'edit'])->name('spaces.edit');
        Route::put('spaces/{listing}', [App\Http\Controllers\Tenant\Venue\VenueListingController::class, 'update'])->name('spaces.update');
        Route::delete('spaces/{listing}', [App\Http\Controllers\Tenant\Venue\VenueListingController::class, 'destroy'])->name('spaces.destroy');

        Route::get('inquiries', [App\Http\Controllers\Tenant\Venue\VenueInquiryController::class, 'index'])->name('inquiries.index');
        Route::get('inquiries/{inquiry}', [App\Http\Controllers\Tenant\Venue\VenueInquiryController::class, 'show'])->name('inquiries.show');
        Route::post('inquiries/{inquiry}/hold', [App\Http\Controllers\Tenant\Venue\VenueInquiryController::class, 'acceptAndHold'])->name('inquiries.hold');
        Route::post('inquiries/{inquiry}/quote', [App\Http\Controllers\Tenant\Venue\VenueInquiryController::class, 'sendQuote'])->name('inquiries.quote');
        Route::post('inquiries/{inquiry}/reject', [App\Http\Controllers\Tenant\Venue\VenueInquiryController::class, 'reject'])->name('inquiries.reject');

        Route::get('calendar', [App\Http\Controllers\Tenant\Venue\VenueCalendarController::class, 'index'])->name('calendar.index');
        Route::post('calendar/blocks', [App\Http\Controllers\Tenant\Venue\VenueCalendarController::class, 'storeBlock'])->name('calendar.blocks.store');
        Route::delete('calendar/blocks/{block}', [App\Http\Controllers\Tenant\Venue\VenueCalendarController::class, 'destroyBlock'])
            ->whereUuid('block')
            ->name('calendar.blocks.destroy');

        Route::get('operations', [App\Http\Controllers\Tenant\Venue\VenueOperationsController::class, 'index'])->name('operations.index');
        Route::get('operations/{booking}', [App\Http\Controllers\Tenant\Venue\VenueOperationsController::class, 'show'])->name('operations.show');
        Route::post('operations/{booking}/tasks', [App\Http\Controllers\Tenant\Venue\VenueOperationsController::class, 'createTask'])->name('operations.tasks.store');
        Route::patch('operations/{booking}/tasks/{task}', [App\Http\Controllers\Tenant\Venue\VenueOperationsController::class, 'updateTaskStatus'])->name('operations.tasks.update');
        Route::post('operations/{booking}/messages', [App\Http\Controllers\Tenant\Venue\VenueOperationsController::class, 'storeMessage'])->name('operations.messages.store');
        Route::post('operations/{booking}/inspections', [App\Http\Controllers\Tenant\Venue\VenueOperationsController::class, 'storeInspection'])->name('operations.inspections.store');

        Route::get('quotes', [App\Http\Controllers\Tenant\Venue\VenueQuoteController::class, 'index'])->name('quotes.index');
        Route::get('quotes/{quote}', [App\Http\Controllers\Tenant\Venue\VenueQuoteController::class, 'show'])->name('quotes.show');
        Route::post('quotes/{quote}/proposal', [App\Http\Controllers\Tenant\Venue\VenueQuoteController::class, 'storeProposal'])->name('quotes.proposal');
        Route::post('quotes/{quote}/reject', [App\Http\Controllers\Tenant\Venue\VenueQuoteController::class, 'reject'])->name('quotes.reject');
    });

    Route::get('events/{event}/data/sponsors', [EventSponsorController::class, 'index'])->name('tenant.events.sponsors.index');
    Route::post('events/{event}/sponsors', [EventSponsorController::class, 'store'])->name('tenant.events.sponsors.store');
    Route::delete('events/{event}/sponsors/{sponsor}', [EventSponsorController::class, 'destroy'])->name('tenant.events.sponsors.destroy');
    Route::post('events/{event}/sponsors/{sponsor}/deliverables', [EventSponsorController::class, 'storeDeliverable'])->name('tenant.events.sponsors.deliverables.store');
    Route::patch('events/{event}/sponsors/{sponsor}/deliverables/{deliverable}', [EventSponsorController::class, 'updateDeliverable'])->name('tenant.events.sponsors.deliverables.update');
    Route::delete('events/{event}/sponsors/{sponsor}/deliverables/{deliverable}', [EventSponsorController::class, 'destroyDeliverable'])->name('tenant.events.sponsors.deliverables.destroy');
    Route::get('events/{event}/sponsors/export', [EventSponsorController::class, 'exportDeliverables'])->name('tenant.events.sponsors.export');

    Route::get('events/{event}/data/reports', [EventReportController::class, 'index'])->name('tenant.events.reports.index');
    Route::get('events/{event}/reports/registrations', [EventReportController::class, 'exportRegistrations'])->name('tenant.events.reports.registrations');
    Route::get('events/{event}/reports/checkins', [EventReportController::class, 'exportCheckins'])->name('tenant.events.reports.checkins');
    Route::get('events/{event}/reports/forum', [EventReportController::class, 'exportForum'])->name('tenant.events.reports.forum');
    Route::get('events/{event}/reports/polls', [EventReportController::class, 'exportPolls'])->name('tenant.events.reports.polls');
    Route::get('events/{event}/reports/attendee-directory', [EventReportController::class, 'exportAttendeeDirectory'])->name('tenant.events.reports.attendee-directory');
    Route::get('events/{event}/reports/session-attendance', [EventReportController::class, 'exportSessionAttendance'])->name('tenant.events.reports.session-attendance');
    Route::get('events/{event}/reports/dietary-accessibility', [EventReportController::class, 'exportDietaryAccessibility'])->name('tenant.events.reports.dietary-accessibility');
    Route::get('events/{event}/reports/audit-log', [EventReportController::class, 'exportAuditLog'])->name('tenant.events.reports.audit-log');
    Route::get('events/{event}/reports/certificates', [EventReportController::class, 'exportCertificates'])->name('tenant.events.reports.certificates');
    Route::get('events/{event}/reports/abstract-book', [EventReportController::class, 'exportAbstractBook'])->name('tenant.events.reports.abstract-book');

    // Abstracts & Peer Review
    Route::get('events/{event}/data/abstracts', [App\Http\Controllers\Tenant\EventAbstractController::class, 'index'])->name('tenant.events.abstracts.index');
    Route::get('events/{event}/abstracts/{abstract}', [App\Http\Controllers\Tenant\EventAbstractController::class, 'show'])->name('tenant.events.abstracts.show');
    Route::post('events/{event}/abstracts/{abstract}/assign-reviewer', [App\Http\Controllers\Tenant\EventAbstractController::class, 'assignReviewer'])->name('tenant.events.abstracts.assign-reviewer');
    Route::delete('events/{event}/abstracts/{abstract}/reviews/{review}', [App\Http\Controllers\Tenant\EventAbstractController::class, 'removeReviewer'])->name('tenant.events.abstracts.remove-reviewer');
    Route::post('events/{event}/abstracts/{abstract}/decision', [App\Http\Controllers\Tenant\EventAbstractController::class, 'recordDecision'])->name('tenant.events.abstracts.decision');
    Route::post('events/{event}/abstracts/bulk-decision', [App\Http\Controllers\Tenant\EventAbstractController::class, 'bulkDecision'])->name('tenant.events.abstracts.bulk-decision');

    Route::get('events/{event}/reviews', [App\Http\Controllers\Tenant\AbstractReviewController::class, 'index'])->name('tenant.events.reviews.index');
    Route::post('events/{event}/abstracts/{abstract}/reviews', [App\Http\Controllers\Tenant\AbstractReviewController::class, 'submit'])->name('tenant.events.reviews.submit');

    Route::patch('events/{event}/visibility', [EventController::class, 'updateVisibility'])->name('tenant.events.visibility');
    Route::get('events/{event}/speakers/{speaker}/portal-link', [EventSpeakerController::class, 'portalLink'])->name('tenant.events.speakers.portal-link');
    Route::post('events/{event}/speakers/{speaker}/invite', [EventSpeakerController::class, 'invite'])->name('tenant.events.speakers.invite');

    // Operations & 8-Pillars Project Management
    Route::get('events/{event}/operations', [App\Http\Controllers\Tenant\EventOperationController::class, 'index'])->name('tenant.events.operations.index');
    Route::post('events/{event}/operations/pillars', [App\Http\Controllers\Tenant\EventOperationController::class, 'storePillar'])->name('tenant.events.operations.pillars.store');
    Route::put('events/{event}/operations/pillars/{pillar}', [App\Http\Controllers\Tenant\EventOperationController::class, 'updatePillar'])->name('tenant.events.operations.pillars.update');
    Route::delete('events/{event}/operations/pillars/{pillar}', [App\Http\Controllers\Tenant\EventOperationController::class, 'destroyPillar'])->name('tenant.events.operations.pillars.destroy');
    Route::patch('events/{event}/operations/pillars/reorder', [App\Http\Controllers\Tenant\EventOperationController::class, 'reorderPillars'])->name('tenant.events.operations.pillars.reorder');
    Route::post('events/{event}/operations/tasks', [App\Http\Controllers\Tenant\EventOperationController::class, 'storeTask'])->name('tenant.events.operations.tasks.store');
    Route::put('events/{event}/operations/tasks/{task}', [App\Http\Controllers\Tenant\EventOperationController::class, 'updateTask'])->name('tenant.events.operations.tasks.update');
    Route::patch('events/{event}/operations/tasks/{task}/status', [App\Http\Controllers\Tenant\EventOperationController::class, 'updateTaskStatus'])->name('tenant.events.operations.tasks.status');
    Route::delete('events/{event}/operations/tasks/{task}', [App\Http\Controllers\Tenant\EventOperationController::class, 'destroyTask'])->name('tenant.events.operations.tasks.destroy');

    // Multi-Role Electronic Certificates
    Route::get('events/{event}/data/certificates', [App\Http\Controllers\Tenant\EventCertificateController::class, 'index'])->name('tenant.events.certificates.index');
    Route::post('events/{event}/certificates/templates', [App\Http\Controllers\Tenant\EventCertificateController::class, 'storeTemplate'])->name('tenant.events.certificates.templates.store');
    Route::put('events/{event}/certificates/templates/{template}', [App\Http\Controllers\Tenant\EventCertificateController::class, 'updateTemplate'])->name('tenant.events.certificates.templates.update');
    Route::get('events/{event}/certificates/templates/{template}/preview', [App\Http\Controllers\Tenant\EventCertificateController::class, 'preview'])->name('tenant.events.certificates.templates.preview');
    Route::get('events/{event}/certificates/templates/{template}/artwork/{type}', [App\Http\Controllers\Tenant\EventCertificateController::class, 'artwork'])->name('tenant.events.certificates.templates.artwork');
    Route::post('events/{event}/certificates/issue', [App\Http\Controllers\Tenant\EventCertificateController::class, 'issue'])->name('tenant.events.certificates.issue');
    Route::get('events/{event}/certificates/{certificate}/download', [App\Http\Controllers\Tenant\EventCertificateController::class, 'download'])->name('tenant.events.certificates.download');
    Route::delete('events/{event}/certificates/{certificate}', [App\Http\Controllers\Tenant\EventCertificateController::class, 'destroy'])->name('tenant.events.certificates.destroy');

    // Dynamic Forms Engine
    Route::get('events/{event}/dynamic-forms', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'index'])->name('tenant.events.dynamic-forms.index');
    Route::post('events/{event}/dynamic-forms', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'store'])->name('tenant.events.dynamic-forms.store');
    Route::get('events/{event}/dynamic-forms/{form}', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'show'])->name('tenant.events.dynamic-forms.show');
    Route::put('events/{event}/dynamic-forms/{form}', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'update'])->name('tenant.events.dynamic-forms.update');
    Route::delete('events/{event}/dynamic-forms/{form}', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'destroy'])->name('tenant.events.dynamic-forms.destroy');
    Route::post('events/{event}/dynamic-forms/{form}/duplicate', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'duplicate'])->name('tenant.events.dynamic-forms.duplicate');
    Route::get('events/{event}/dynamic-forms/{form}/submissions', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'submissions'])->name('tenant.events.dynamic-forms.submissions');
    Route::get('events/{event}/dynamic-forms/{form}/submissions/export', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'exportSubmissionsCsv'])->name('tenant.events.dynamic-forms.submissions.export');
    Route::get('events/{event}/dynamic-forms/{form}/export', [App\Http\Controllers\Tenant\EventDynamicFormController::class, 'exportSubmissionsCsv'])->name('tenant.events.dynamic-forms.export');

    // Participant Stratification Groups
    Route::get('events/{event}/participant-groups', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'index'])->name('tenant.events.participant-groups.index');
    Route::post('events/{event}/participant-groups', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'store'])->name('tenant.events.participant-groups.store');
    Route::get('events/{event}/participant-groups/{group}', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'show'])->name('tenant.events.participant-groups.show');
    Route::put('events/{event}/participant-groups/{group}', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'update'])->name('tenant.events.participant-groups.update');
    Route::delete('events/{event}/participant-groups/{group}', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'destroy'])->name('tenant.events.participant-groups.destroy');
    Route::post('events/{event}/participant-groups/{group}/sync', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'sync'])->name('tenant.events.participant-groups.sync');
    Route::post('events/{event}/participant-groups/{group}/members', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'addMemberManual'])->name('tenant.events.participant-groups.members.add');
    Route::delete('events/{event}/participant-groups/{group}/members/{registration}', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'removeMember'])->name('tenant.events.participant-groups.members.remove');
    Route::get('events/{event}/participant-groups/{group}/export', [App\Http\Controllers\Tenant\EventParticipantGroupController::class, 'export'])->name('tenant.events.participant-groups.export');

    // Automated Notification Rules & Multi-Channel Delivery
    Route::get('events/{event}/notification-rules', [App\Http\Controllers\Tenant\EventNotificationRuleController::class, 'index'])->name('tenant.events.notification-rules.index');
    Route::post('events/{event}/notification-rules', [App\Http\Controllers\Tenant\EventNotificationRuleController::class, 'store'])->name('tenant.events.notification-rules.store');
    Route::put('events/{event}/notification-rules/{rule}', [App\Http\Controllers\Tenant\EventNotificationRuleController::class, 'update'])->name('tenant.events.notification-rules.update');
    Route::delete('events/{event}/notification-rules/{rule}', [App\Http\Controllers\Tenant\EventNotificationRuleController::class, 'destroy'])->name('tenant.events.notification-rules.destroy');
    Route::patch('events/{event}/notification-rules/{rule}/toggle', [App\Http\Controllers\Tenant\EventNotificationRuleController::class, 'toggle'])->name('tenant.events.notification-rules.toggle');
    Route::post('events/{event}/notification-rules/{rule}/dispatch', [App\Http\Controllers\Tenant\EventNotificationRuleController::class, 'dispatchNow'])->name('tenant.events.notification-rules.dispatch');
    Route::post('events/{event}/notification-rules/test-send', [App\Http\Controllers\Tenant\EventNotificationRuleController::class, 'testSend'])->name('tenant.events.notification-rules.test-send');
    Route::put('events/{event}/notification-settings', [App\Http\Controllers\Tenant\EventNotificationRuleController::class, 'updateSettings'])->name('tenant.events.notification-settings.update');

});

// Public certificate verification
Route::get('/verify/cert/{uuid}', [App\Http\Controllers\Public\PublicCertificateVerificationController::class, 'verify'])->name('public.certificates.verify');
Route::get('/verify/cert/{uuid}/download', [App\Http\Controllers\Public\PublicCertificateVerificationController::class, 'download'])->name('public.certificates.download');

// Public event pages (no auth — attendees register here)
Route::get('/e/{event}', [PublicEventController::class, 'show'])->name('public.events.show');
Route::get('/e/{event}/calendar.ics', [PublicEventController::class, 'calendarFeed'])->name('public.events.calendar.ics');
Route::post('/e/{event}/validate-promo', [PublicEventController::class, 'validatePromo'])->name('public.events.validate-promo');
Route::post('/e/{event}/unlock-tickets', [PublicEventController::class, 'unlockTicketTypes'])->name('public.events.unlock-tickets');
Route::post('/e/{event}/register', [PublicEventController::class, 'register'])->middleware('throttle:public-registration')->name('public.events.register');
Route::post('/e/{event}/contribute', [App\Http\Controllers\Public\EventContributionController::class, 'store'])->middleware('throttle:public-registration')->name('public.events.contribute');
Route::get('/e/{event}/contributions/{contribution}/callback', [App\Http\Controllers\Public\EventContributionController::class, 'callback'])->name('public.events.contributions.callback');
Route::get('/e/{event}/checkout/{registration}', [EventCheckoutController::class, 'checkout'])->name('public.events.checkout');
Route::post('/e/{event}/checkout/{registration}/paystack', [EventCheckoutController::class, 'payWithPaystack'])->name('public.events.checkout.paystack');
Route::post('/e/{event}/checkout/{registration}/offline-proof', [EventCheckoutController::class, 'submitOfflineProof'])->middleware('throttle:public-registration')->name('public.events.checkout.offline-proof');
Route::get('/e/{event}/checkout/{registration}/proof', [EventCheckoutController::class, 'downloadProof'])->name('public.events.checkout.proof');
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
Route::get('/e/{event}/abstracts/submit', [App\Http\Controllers\Public\AbstractSubmissionController::class, 'create'])->name('public.events.abstracts.create');
Route::post('/e/{event}/abstracts/submit', [App\Http\Controllers\Public\AbstractSubmissionController::class, 'store'])->middleware('throttle:public-registration')->name('public.events.abstracts.store');
Route::get('/e/{event}/abstracts/{code}', [App\Http\Controllers\Public\AbstractSubmissionController::class, 'show'])->name('public.events.abstracts.show');

// Public Dynamic Forms
Route::get('/e/{event}/forms/{form}', [App\Http\Controllers\Public\PublicDynamicFormController::class, 'show'])->name('public.events.forms.show');
Route::post('/e/{event}/forms/{form}/submit', [App\Http\Controllers\Public\PublicDynamicFormController::class, 'submit'])->middleware('throttle:public-registration')->name('public.events.forms.submit');

Route::get('/e/{event}/preview/attendee-portal', [PublicEventController::class, 'attendeePortalPreview'])->name('public.events.preview.attendee');
Route::get('/e/{event}/preview/speaker-portal', [PublicEventController::class, 'speakerPortalPreview'])->name('public.events.preview.speaker');

Route::get('/e/{event}/forum', [PublicForumController::class, 'index'])->name('public.events.forum.index');
Route::post('/e/{event}/forum', [PublicForumController::class, 'store'])->name('public.events.forum.store');
Route::post('/e/{event}/forum/{thread}/vote', [PublicForumController::class, 'vote'])->name('public.events.forum.vote');
Route::delete('/e/{event}/forum/{thread}/vote', [PublicForumController::class, 'unvote'])->name('public.events.forum.unvote');
Route::post('/e/{event}/forum/{thread}/report', [PublicForumController::class, 'report'])->name('public.events.forum.report');

// Staff links: ushers and floor crew scan tickets and answer attendee requests
// from their own phones, reached by the link's token rather than an account.
// The limit is generous because a whole crew usually shares one venue Wi-Fi
// address, and every phone polls for requests and the headcount.
Route::prefix('staff/{token}')->name('public.staff.')->middleware('throttle:900,1')->group(function (): void {
    Route::get('/', [App\Http\Controllers\Public\StaffLinkController::class, 'show'])->name('show');
    Route::post('/unlock', [App\Http\Controllers\Public\StaffLinkController::class, 'unlock'])->middleware('throttle:10,1')->name('unlock');
    Route::get('/counts', [App\Http\Controllers\Public\StaffLinkController::class, 'counts'])->name('counts');
    Route::get('/checkin/search', [App\Http\Controllers\Public\StaffLinkController::class, 'search'])->name('checkin.search');
    Route::post('/checkin/scan', [App\Http\Controllers\Public\StaffLinkController::class, 'scan'])->name('checkin.scan');
    // Offline support: before the {registration} route, so "sync" is never read as a registration id.
    Route::get('/offline-pack', [App\Http\Controllers\Public\StaffLinkController::class, 'pack'])->name('pack');
    Route::post('/checkin/sync', [App\Http\Controllers\Public\StaffLinkController::class, 'sync'])->name('sync');
    Route::post('/checkin/{registration}', [App\Http\Controllers\Public\StaffLinkController::class, 'checkIn'])->name('checkin');
    Route::get('/requests', [App\Http\Controllers\Public\StaffLinkController::class, 'requests'])->name('requests');
    Route::patch('/requests/{serviceRequest}/claim', [App\Http\Controllers\Public\StaffLinkController::class, 'claim'])->name('requests.claim');
    Route::patch('/requests/{serviceRequest}', [App\Http\Controllers\Public\StaffLinkController::class, 'updateStatus'])->name('requests.status');
});

// The results screen behind the speaker. Reached by a token, because the
// machine driving a projector is rarely the organiser's own.
Route::get('/e/{event}/present/{token}', [App\Http\Controllers\Public\PollPresentationController::class, 'show'])->name('public.events.present');
Route::get('/e/{event}/present/{token}/results', [App\Http\Controllers\Public\PollPresentationController::class, 'results'])->name('public.events.present.results');

// The presenter's own screen for one deck. The token is the deck's, so it
// drives that deck and nothing else; rotating it from the console revokes it.
Route::get('/e/{event}/decks/{deck}/present/{token}', [App\Http\Controllers\Public\DeckPresenterController::class, 'show'])
    ->whereUuid('deck')
    ->name('public.events.decks.presenter');
Route::get('/e/{event}/decks/{deck}/present/{token}/state', [App\Http\Controllers\Public\DeckPresenterController::class, 'state'])
    ->whereUuid('deck')
    ->name('public.events.decks.presenter.state');
Route::post('/e/{event}/decks/{deck}/present/{token}/{action}', [App\Http\Controllers\Public\DeckPresenterController::class, 'act'])
    ->whereUuid('deck')
    ->whereIn('action', ['start', 'advance', 'previous', 'close', 'end'])
    ->middleware('throttle:120,1')
    ->name('public.events.decks.presenter.act');

Route::get('/e/{event}/poll', [PublicPollController::class, 'show'])->name('public.events.poll.show');
Route::post('/e/{event}/poll/{poll}/respond', [PublicPollController::class, 'respond'])->name('public.events.poll.respond');
Route::get('/e/{event}/quiz/leaderboard', [PublicPollController::class, 'leaderboard'])->name('public.events.quiz.leaderboard');

Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/e/{event}/speaker-portal/{token}', [SpeakerPortalController::class, 'show'])->name('public.events.speaker-portal');
    Route::post('/e/{event}/speaker-portal/{token}/confirm', [SpeakerPortalController::class, 'confirm'])->name('public.events.speaker-portal.confirm');
    Route::post('/e/{event}/speaker-portal/{token}/profile', [SpeakerPortalController::class, 'updateProfile'])->name('public.events.speaker-portal.profile');
    Route::post('/e/{event}/speaker-portal/{token}/photo', [SpeakerPortalController::class, 'uploadPhoto'])->name('public.events.speaker-portal.photo');
    Route::post('/e/{event}/speaker-portal/{token}/slides', [SpeakerPortalController::class, 'uploadSlides'])->name('public.events.speaker-portal.slides');
});

Route::get('/blasts/{recipient}/open.gif', BlastOpenController::class)->name('public.blasts.open');

Route::get('/deck', App\Http\Controllers\Marketing\PitchDeckController::class)->name('subdomain.marketing.deck');
Route::get('/pitch', App\Http\Controllers\Marketing\PitchDeckController::class)->name('subdomain.marketing.pitch');
Route::get('/slides', App\Http\Controllers\Marketing\PitchDeckController::class)->name('subdomain.marketing.slides');

Route::get('/media/{path}', [App\Http\Controllers\MediaController::class, 'show'])->where('path', '.*')->name('tenant.media.show');
Route::get('/storage/{path}', [App\Http\Controllers\MediaController::class, 'show'])->where('path', '.*');
