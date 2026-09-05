<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSpeaker;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EventSpeakerController extends Controller
{
    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $validated = $request->validate([
            'speaker_id' => ['required', Rule::exists('speakers', 'id')->where('tenant_id', $tenant->id)],
            'role' => ['nullable', 'string', 'max:50'],
        ]);

        $eventModel->speakers()->syncWithoutDetaching([
            $validated['speaker_id'] => [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'role' => $validated['role'] ?? 'speaker',
                'portal_token' => Str::random(40),
            ],
        ]);

        return response()->json(['message' => 'Speaker added to event.']);
    }

    public function destroy(string $subdomain, string $event, string $speaker): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $eventModel->speakers()->detach($speaker);

        return response()->json(['message' => 'Speaker removed from event.']);
    }

    public function portalLink(string $subdomain, string $event, string $speaker): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $pivot = EventSpeaker::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('speaker_id', $speaker)
            ->firstOrFail();

        if (! $pivot->portal_token) {
            $pivot->update(['portal_token' => Str::random(40)]);
        }

        return response()->json([
            'portal_url' => route('public.events.speaker-portal', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
                'token' => $pivot->portal_token,
            ]),
        ]);
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
