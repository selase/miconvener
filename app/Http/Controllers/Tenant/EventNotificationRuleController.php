<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventNotificationRule;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\AutomatedNotificationDispatcher;
use App\Services\Notifications\NotificationGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class EventNotificationRuleController extends Controller
{
    public function index(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('read notification-rule');

        $rules = $event->notificationRules()
            ->latest('created_at')
            ->get();

        $settings = TenantNotificationSetting::forTenant($event->tenant_id);

        // Build audience options (standard + ticket types + dynamic cohorts)
        $audiences = [
            ['key' => 'all', 'label' => 'All Confirmed & Checked-in Attendees', 'role' => 'attendee'],
            ['key' => 'confirmed', 'label' => 'Confirmed (Not Yet Checked-in)', 'role' => 'attendee'],
            ['key' => 'checked_in', 'label' => 'Checked-in Delegates Only', 'role' => 'attendee'],
            ['key' => 'speakers', 'label' => 'All Speakers & Presenters', 'role' => 'speaker'],
            ['key' => 'organizers', 'label' => 'Host Organizing Committee', 'role' => 'organizer'],
        ];

        foreach ($event->ticketTypes()->get(['id', 'name']) as $type) {
            $audiences[] = [
                'key' => "ticket_type:{$type->id}",
                'label' => "Ticket: {$type->name}",
                'role' => 'attendee',
            ];
        }

        foreach ($event->participantGroups()->get(['id', 'name']) as $group) {
            $audiences[] = [
                'key' => "group:{$group->id}",
                'label' => "Cohort: {$group->name}",
                'role' => 'attendee',
            ];
        }

        $recentLogs = $event->notificationLogs()
            ->latest('created_at')
            ->limit(25)
            ->get();

        return response()->json([
            'rules' => $rules,
            'audiences' => $audiences,
            'settings' => $settings,
            'recent_logs' => $recentLogs,
            'presets' => $this->getNotificationPresets($event),
        ]);
    }

    public function store(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('create notification-rule');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'target_role' => ['required', 'string', 'in:attendee,speaker,organizer'],
            'target_audience' => ['required', 'string', 'max:100'],
            'trigger_type' => ['required', 'string', 'in:scheduled_offset,on_registration,on_checkin,on_materials_uploaded'],
            'offset_direction' => ['required', 'string', 'in:before,after'],
            'offset_amount' => ['required', 'integer', 'min:0'],
            'offset_unit' => ['required', 'string', 'in:days,hours,minutes'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:email,sms,whatsapp'],
            'subject' => ['required', 'string', 'max:255'],
            'body_template' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $rule = $event->notificationRules()->create([
            'tenant_id' => $event->tenant_id,
            'name' => $validated['name'],
            'target_role' => $validated['target_role'],
            'target_audience' => $validated['target_audience'],
            'trigger_type' => $validated['trigger_type'],
            'offset_direction' => $validated['offset_direction'],
            'offset_amount' => $validated['offset_amount'],
            'offset_unit' => $validated['offset_unit'],
            'channels' => $validated['channels'],
            'subject' => $validated['subject'],
            'body_template' => $validated['body_template'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Notification rule created successfully.',
            'rule' => $rule,
        ], 201);
    }

    public function update(Request $request, string $subdomain, Event $event, EventNotificationRule $rule): JsonResponse
    {
        Gate::authorize('update notification-rule');

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'target_role' => ['sometimes', 'required', 'string', 'in:attendee,speaker,organizer'],
            'target_audience' => ['sometimes', 'required', 'string', 'max:100'],
            'trigger_type' => ['sometimes', 'required', 'string', 'in:scheduled_offset,on_registration,on_checkin,on_materials_uploaded'],
            'offset_direction' => ['sometimes', 'required', 'string', 'in:before,after'],
            'offset_amount' => ['sometimes', 'required', 'integer', 'min:0'],
            'offset_unit' => ['sometimes', 'required', 'string', 'in:days,hours,minutes'],
            'channels' => ['sometimes', 'required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:email,sms,whatsapp'],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body_template' => ['sometimes', 'required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $rule->update($validated);

        return response()->json([
            'message' => 'Notification rule updated successfully.',
            'rule' => $rule->fresh(),
        ]);
    }

    public function destroy(Request $request, string $subdomain, Event $event, EventNotificationRule $rule): JsonResponse
    {
        Gate::authorize('delete notification-rule');

        $rule->delete();

        return response()->json([
            'message' => 'Notification rule deleted successfully.',
        ]);
    }

    public function toggle(Request $request, string $subdomain, Event $event, EventNotificationRule $rule): JsonResponse
    {
        Gate::authorize('update notification-rule');

        $rule->update(['is_active' => ! $rule->is_active]);

        return response()->json([
            'message' => $rule->is_active ? 'Notification rule enabled.' : 'Notification rule disabled.',
            'rule' => $rule->fresh(),
        ]);
    }

    public function dispatchNow(Request $request, string $subdomain, Event $event, EventNotificationRule $rule, AutomatedNotificationDispatcher $dispatcher): JsonResponse
    {
        Gate::authorize('create notification-rule');

        $stats = $dispatcher->dispatchRule($rule);

        return response()->json([
            'message' => "Rule dispatched to {$stats['total_recipients']} recipients ({$stats['sent_count']} emails, {$stats['staged_count']} SMS/WhatsApp).",
            'stats' => $stats,
        ]);
    }

    public function testSend(Request $request, string $subdomain, Event $event, NotificationGatewayService $gateway): JsonResponse
    {
        Gate::authorize('create notification-rule');

        $validated = $request->validate([
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:email,sms,whatsapp'],
            'subject' => ['required', 'string'],
            'body_template' => ['required', 'string'],
        ]);

        $user = $request->user();
        $recipient = [
            'name' => mb_trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
            'phone' => $user->phone ?? null,
        ];

        $payload = [
            'subject' => "[TEST] {$validated['subject']}",
            'body' => str_replace(
                ['{name}', '{event_name}', '{venue}', '{date}'],
                [$recipient['name'], $event->name, $event->venue_name ?? 'Accra Conference Center', now()->format('F j, Y')],
                $validated['body_template']
            ),
            'action_url' => url("/e/{$event->slug}"),
            'action_label' => 'View Conference Portal',
        ];

        $results = $gateway->dispatch($event, null, $recipient, $validated['channels'], $payload);

        return response()->json([
            'message' => 'Test notification dispatched.',
            'results' => $results,
        ]);
    }

    public function updateSettings(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('manage notification-settings');

        $validated = $request->validate([
            'sms_enabled' => ['required', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'overage_billing_enabled' => ['required', 'boolean'],
            'anti_abuse_cooldown_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $settings = TenantNotificationSetting::forTenant($event->tenant_id);
        $settings->update($validated);

        return response()->json([
            'message' => 'Tenant notification and billing settings updated.',
            'settings' => $settings->fresh(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getNotificationPresets(Event $event): array
    {
        return [
            [
                'name' => '7-Day Pre-Conference Readiness Reminder',
                'target_role' => 'attendee',
                'target_audience' => 'all',
                'trigger_type' => 'scheduled_offset',
                'offset_direction' => 'before',
                'offset_amount' => 7,
                'offset_unit' => 'days',
                'channels' => ['email'],
                'subject' => "Important: 1 Week Until {$event->name}!",
                'body_template' => "Dear {name},\n\nWe are looking forward to welcoming you to {event_name} in one week on {date} at {venue}.\n\nPlease ensure your badge profile is complete and explore the digital programme before arrival.",
            ],
            [
                'name' => 'Day-Of Welcome & Digital Pass Passcode',
                'target_role' => 'attendee',
                'target_audience' => 'confirmed',
                'trigger_type' => 'scheduled_offset',
                'offset_direction' => 'before',
                'offset_amount' => 2,
                'offset_unit' => 'hours',
                'channels' => ['email', 'sms'],
                'subject' => "Welcome to {$event->name} — Your Check-in Pass",
                'body_template' => "Hello {name}!\n\nWelcome to {event_name}. Your ticket code is: {ticket_code}.\n\nPresent your ticket code or digital pass at the check-in desk for your badge.",
            ],
            [
                'name' => 'Post-Conference CME Assessment & Certificate Survey',
                'target_role' => 'attendee',
                'target_audience' => 'checked_in',
                'trigger_type' => 'scheduled_offset',
                'offset_direction' => 'after',
                'offset_amount' => 2,
                'offset_unit' => 'hours',
                'channels' => ['email'],
                'subject' => "Thank you for attending {$event->name} — Please complete your evaluation",
                'body_template' => "Dear {name},\n\nThank you for participating in {event_name}!\n\nPlease complete the conference assessment to claim your accredited CME certificate.",
            ],
            [
                'name' => 'Speaker Slide Deck Deadline Alert',
                'target_role' => 'speaker',
                'target_audience' => 'speakers',
                'trigger_type' => 'scheduled_offset',
                'offset_direction' => 'before',
                'offset_amount' => 3,
                'offset_unit' => 'days',
                'channels' => ['email', 'whatsapp'],
                'subject' => "Urgent: Speaker Slide Submission for {$event->name}",
                'body_template' => "Dear {name},\n\nThis is a reminder from the Scientific Committee of {event_name}. Please upload your final presentation slides to the Speaker Portal.",
            ],
        ];
    }
}
