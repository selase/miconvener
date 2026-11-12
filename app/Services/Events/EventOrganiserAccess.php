<?php

declare(strict_types=1);

namespace App\Services\Events;

use Illuminate\Routing\Route;

/** Explicit event operations; new organisation or money endpoints fail closed. */
final class EventOrganiserAccess
{
    /** @var array<string, list<string>> */
    private const array ACTIONS = [
        'EventController' => ['index', 'show', 'section', 'door', 'exportGuests', 'update', 'updateVisibility', 'updateCommunityPrompts', 'updateRegistrationVisibility', 'updatePublicBranding'],
        'EventRegistrationController' => ['approve', 'reject', 'update', 'cancel'],
        'EventReportController' => ['index', 'exportRegistrations', 'exportCheckins', 'exportForum', 'exportPolls', 'exportAttendeeDirectory', 'exportSessionAttendance', 'exportDietaryAccessibility', 'exportCertificates', 'exportAbstractBook'],
        'EventTicketTypeController' => ['updateBadgeTier'],
        'EventAbstractController' => ['assignReviewer', 'bulkDecision', 'index', 'recordDecision', 'removeReviewer', 'show'],
        'AbstractReviewController' => ['index', 'submit'],
        'ArtifactFontController' => ['face', 'index', 'store'],
        'EventAttendeeImportController' => ['confirm', 'export', 'index', 'invite', 'preview', 'resume', 'retryInvitations', 'show', 'template'],
        'BadgeTemplatePurchaseController' => ['store', 'checkout'],
        'BadgeLibraryController' => ['index', 'thumbnail', 'store', 'preview', 'apply', 'restore', 'withdraw'],
        'EventBadgeController' => ['confirmCleanPreview', 'artwork', 'index', 'sheet', 'updateTemplate'],
        'EventBlastController' => ['cancel', 'index', 'store'],
        'EventCertificateController' => ['artwork', 'destroy', 'download', 'draftPreview', 'index', 'issue', 'preview', 'storeTemplate', 'updateTemplate'],
        'EventCheckInController' => ['checkIn', 'scan', 'search'],
        'EventDoorScanController' => ['index'],
        'EventForumController' => ['ban', 'destroy', 'index', 'moderate', 'reply', 'unban'],
        'EventSponsorController' => ['destroy', 'destroyDeliverable', 'exportDeliverables', 'index', 'store', 'storeDeliverable', 'updateDeliverable'],
        'EventStaffLinkController' => ['destroy', 'index', 'store', 'update'],
        'EventPollDeckController' => ['advance', 'close', 'destroy', 'end', 'index', 'presenterLink', 'previous', 'rotatePresenterLink', 'setPolls', 'start', 'store'],
        'EventDynamicFormController' => ['destroy', 'duplicate', 'exportSubmissionsCsv', 'index', 'show', 'store', 'submissions', 'update'],
        'EventFormFieldController' => ['destroy', 'index', 'reorder', 'store', 'update', 'updateSettings'],
        'EventHostController' => ['update'],
        'EventMaterialController' => ['destroy', 'store', 'updateSpeakerPolicy'],
        'EventNotificationRuleController' => ['destroy', 'dispatchNow', 'index', 'store', 'testSend', 'toggle', 'update'],
        'EventSessionController' => ['analytics', 'batchCancel', 'batchReschedule', 'destroy', 'generateRecurrence', 'reorder', 'store', 'update', 'updateOccurrence'],
        'EventOperationController' => ['destroyPillar', 'destroyTask', 'index', 'reorderPillars', 'storePillar', 'storeTask', 'updatePillar', 'updateTask', 'updateTaskStatus'],
        'EventParticipantGroupController' => ['addMemberManual', 'destroy', 'export', 'index', 'removeMember', 'show', 'store', 'sync', 'update'],
        'EventPollController' => ['destroy', 'index', 'leaderboard', 'moderateResponse', 'presentLink', 'rotatePresentLink', 'store', 'updateStatus'],
        'EventAttendeeProfileController' => ['hide', 'index', 'toggle'],
        'EventServiceRequestController' => ['claim', 'index', 'updateStatus'],
        'EventSessionCheckInController' => ['attendees', 'occupancy', 'scan'],
        'EventSpeakerController' => ['indexProfiles', 'storeProfile', 'updateProfile', 'destroy', 'invite', 'portalLink'],
        'EventVenueController' => ['assignSeat', 'destroyRoom', 'searchUnseated', 'storeRoom', 'unassignSeat'],
    ];

    public static function allows(Route $route): bool
    {
        $action = $route->getActionName();
        $prefix = 'App\\Http\\Controllers\\Tenant\\';
        if (! str_starts_with($action, $prefix)) {
            return false;
        }

        [$controller, $method] = array_pad(explode('@', mb_substr($action, mb_strlen($prefix)), 2), 2, '');

        return in_array($method, self::ACTIONS[$controller] ?? [], true);
    }
}
