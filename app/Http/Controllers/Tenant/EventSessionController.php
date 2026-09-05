<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EventSessionController extends Controller
{
    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $validated = $this->validateSession($request, $tenant->id);

        $session = $eventModel->sessions()->create([
            ...collect($validated)->except('speaker_ids')->all(),
            'tenant_id' => $tenant->id,
        ]);

        if (isset($validated['speaker_ids'])) {
            $session->speakers()->sync($this->pivotData($validated['speaker_ids']));
        }

        return response()->json([
            ...$session->load('speakers')->toArray(),
            'clashes' => $session->clashes(),
        ]);
    }

    public function update(Request $request, string $subdomain, string $event, string $session): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();
        $sessionModel = $eventModel->sessions()->where('id', $session)->firstOrFail();

        $validated = $this->validateSession($request, $tenant->id);

        $sessionModel->update(collect($validated)->except('speaker_ids')->all());

        if (isset($validated['speaker_ids'])) {
            $sessionModel->speakers()->sync($this->pivotData($validated['speaker_ids']));
        }

        return response()->json([
            ...$sessionModel->load('speakers')->toArray(),
            'clashes' => $sessionModel->clashes(),
        ]);
    }

    public function destroy(string $subdomain, string $event, string $session): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $eventModel->sessions()->where('id', $session)->firstOrFail()->delete();

        return response()->json(['message' => 'Session deleted.']);
    }

    /**
     * Reorder a same-day group of sessions. Each session keeps its own
     * duration; the group is laid out back-to-back starting from the
     * earliest current start time among them, in the new order given.
     */
    public function reorder(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $validated = $request->validate([
            'session_ids' => ['required', 'array', 'min:2'],
            'session_ids.*' => [Rule::exists('event_sessions', 'id')->where('event_id', $eventModel->id)],
        ]);

        $sessions = $eventModel->sessions()->whereIn('id', $validated['session_ids'])->get()->keyBy('id');

        // Carbon instances here are immutable (app-wide config), so every
        // add/copy call must be reassigned — mutating in place is a no-op.
        $cursor = $sessions->min('starts_at');

        foreach ($validated['session_ids'] as $sessionId) {
            $session = $sessions->get($sessionId);
            $duration = $session->starts_at->diffInMinutes($session->ends_at);

            $session->update([
                'starts_at' => $cursor,
                'ends_at' => $cursor->addMinutes($duration),
            ]);

            $cursor = $cursor->addMinutes($duration);
        }

        return response()->json($eventModel->sessions()->with('speakers')->get());
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSession(Request $request, string $tenantId): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'track' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::in(['keynote', 'plenary', 'workshop', 'breakout', 'panel', 'break', 'networking', 'session'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['integer'],
            'speaker_ids' => ['nullable', 'array'],
            'speaker_ids.*' => [Rule::exists('speakers', 'id')->where('tenant_id', $tenantId)],
        ]);
    }

    /**
     * @param  array<int, string>  $speakerIds
     * @return array<string, array<string, string>>
     */
    private function pivotData(array $speakerIds): array
    {
        $pivot = [];
        foreach ($speakerIds as $speakerId) {
            $pivot[$speakerId] = ['id' => (string) Str::uuid()];
        }

        return $pivot;
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
