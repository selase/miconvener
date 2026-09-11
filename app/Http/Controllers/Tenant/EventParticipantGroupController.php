<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipantGroup;
use App\Models\EventRegistration;
use App\Services\Stratification\ParticipantStratificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventParticipantGroupController extends Controller
{
    public function index(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('manage participant-groups');

        $groups = $event->participantGroups()
            ->withCount('members')
            ->get();

        $ticketTypes = $event->ticketTypes()->get(['id', 'name']);
        $sessions = $event->sessions()->get(['id', 'title', 'room_name']);
        $forms = $event->dynamicForms()->get(['id', 'title', 'schema']);

        return response()->json([
            'groups' => $groups,
            'meta' => [
                'ticket_types' => $ticketTypes,
                'sessions' => $sessions,
                'forms' => $forms,
                'total_attendees' => $event->registrations()->count(),
            ],
        ]);
    }

    public function store(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('manage participant-groups');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:50'],
            'type' => ['required', 'string', 'in:dynamic,manual'],
            'criteria' => ['nullable', 'array'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;

        while ($event->participantGroups()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $group = $event->participantGroups()->create([
            'tenant_id' => $event->tenant_id,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#3B82F6',
            'icon' => $validated['icon'] ?? 'users',
            'type' => $validated['type'],
            'criteria' => $validated['criteria'] ?? [],
            'member_count' => 0,
        ]);

        // Auto-run sync for dynamic groups
        if ($group->type === EventParticipantGroup::TYPE_DYNAMIC) {
            app(ParticipantStratificationService::class)->syncGroupMembers($group);
        }

        return response()->json([
            'message' => 'Participant group created successfully.',
            'group' => $group->fresh()->loadCount('members'),
        ], 201);
    }

    public function show(Request $request, string $subdomain, Event $event, EventParticipantGroup $group): JsonResponse
    {
        Gate::authorize('manage participant-groups');

        $members = $group->members()
            ->with(['registration.ticketType'])
            ->latest('matched_at')
            ->paginate(50);

        return response()->json([
            'group' => $group,
            'members' => $members,
        ]);
    }

    public function update(Request $request, string $subdomain, Event $event, EventParticipantGroup $group): JsonResponse
    {
        Gate::authorize('manage participant-groups');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:50'],
            'type' => ['required', 'string', 'in:dynamic,manual'],
            'criteria' => ['nullable', 'array'],
        ]);

        $group->update($validated);

        if ($group->type === EventParticipantGroup::TYPE_DYNAMIC) {
            app(ParticipantStratificationService::class)->syncGroupMembers($group);
        }

        return response()->json([
            'message' => 'Participant group updated successfully.',
            'group' => $group->fresh()->loadCount('members'),
        ]);
    }

    public function destroy(Request $request, string $subdomain, Event $event, EventParticipantGroup $group): JsonResponse
    {
        Gate::authorize('manage participant-groups');

        $group->delete();

        return response()->json([
            'message' => 'Participant group deleted successfully.',
        ]);
    }

    public function sync(Request $request, string $subdomain, Event $event, EventParticipantGroup $group): JsonResponse
    {
        Gate::authorize('manage participant-groups');

        $result = app(ParticipantStratificationService::class)->syncGroupMembers($group);

        return response()->json([
            'message' => "Group synced successfully: {$result['total_count']} members active ({$result['matched_count']} dynamic, {$result['manual_count']} manual).",
            'stats' => $result,
            'group' => $group->fresh()->loadCount('members'),
        ]);
    }

    public function addMemberManual(Request $request, string $subdomain, Event $event, EventParticipantGroup $group): JsonResponse
    {
        Gate::authorize('manage participant-groups');

        $validated = $request->validate([
            'registration_id' => ['required', 'uuid', 'exists:landlord.event_registrations,id'],
        ]);

        $reg = $event->registrations()->findOrFail($validated['registration_id']);

        $group->members()->updateOrCreate(
            ['registration_id' => $reg->id],
            [
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'is_manual' => true,
                'matched_at' => now(),
            ]
        );

        $group->update(['member_count' => $group->members()->count()]);

        return response()->json([
            'message' => "{$reg->full_name} added to {$group->name}.",
            'group' => $group->fresh()->loadCount('members'),
        ]);
    }

    public function removeMember(Request $request, string $subdomain, Event $event, EventParticipantGroup $group, EventRegistration $registration): JsonResponse
    {
        Gate::authorize('manage participant-groups');

        $group->members()->where('registration_id', $registration->id)->delete();
        $group->update(['member_count' => $group->members()->count()]);

        return response()->json([
            'message' => 'Member removed from group.',
            'group' => $group->fresh()->loadCount('members'),
        ]);
    }

    public function export(Request $request, string $subdomain, Event $event, EventParticipantGroup $group): StreamedResponse
    {
        Gate::authorize('manage participant-groups');

        return app(ParticipantStratificationService::class)->exportGroupCsv($group);
    }
}
