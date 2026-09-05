<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventPollController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $polls = $eventModel->polls()->with(['options.responses', 'responses'])->get();

        return response()->json($polls->map(fn (EventPoll $p): array => $this->pollPayload($p)));
    }

    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'type' => ['required', Rule::in([EventPoll::TYPE_MULTIPLE_CHOICE, EventPoll::TYPE_OPEN, EventPoll::TYPE_QUIZ])],
            'options' => ['required_if:type,multiple_choice,quiz', 'array', 'min:2'],
            'options.*' => ['string', 'max:255'],
            'correct_option_index' => ['required_if:type,quiz', 'nullable', 'integer', 'min:0'],
            'timer_seconds' => ['nullable', 'integer', 'min:5', 'max:300'],
            'points' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'requires_moderation' => ['sometimes', 'boolean'],
        ]);

        $poll = $eventModel->polls()->create([
            'tenant_id' => $tenant->id,
            'question' => $validated['question'],
            'type' => $validated['type'],
            'timer_seconds' => $validated['type'] === EventPoll::TYPE_QUIZ ? ($validated['timer_seconds'] ?? null) : null,
            'points' => $validated['points'] ?? 10,
            'requires_moderation' => $validated['type'] === EventPoll::TYPE_OPEN ? (bool) ($validated['requires_moderation'] ?? false) : false,
        ]);

        if (in_array($validated['type'], [EventPoll::TYPE_MULTIPLE_CHOICE, EventPoll::TYPE_QUIZ], true)) {
            foreach ($validated['options'] as $i => $label) {
                $poll->options()->create([
                    'tenant_id' => $tenant->id,
                    'label' => $label,
                    'sort_order' => $i,
                    'is_correct' => $validated['type'] === EventPoll::TYPE_QUIZ && (int) $validated['correct_option_index'] === $i,
                ]);
            }
        }

        return response()->json($this->pollPayload($poll->fresh(['options.responses', 'responses'])));
    }

    public function updateStatus(Request $request, string $subdomain, string $event, string $poll): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $pollModel = $eventModel->polls()->where('id', $poll)->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in([EventPoll::STATUS_DRAFT, EventPoll::STATUS_LIVE, EventPoll::STATUS_CLOSED])],
        ]);

        $pollModel->update([
            'status' => $validated['status'],
            'went_live_at' => $validated['status'] === EventPoll::STATUS_LIVE ? now() : $pollModel->went_live_at,
        ]);

        return response()->json($this->pollPayload($pollModel->fresh(['options.responses', 'responses'])));
    }

    public function moderateResponse(Request $request, string $subdomain, string $event, string $poll, string $response): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $pollModel = $eventModel->polls()->where('id', $poll)->firstOrFail();
        $responseModel = $pollModel->responses()->where('id', $response)->firstOrFail();

        $validated = $request->validate([
            'is_approved' => ['required', 'boolean'],
        ]);

        $responseModel->update($validated);

        return response()->json($this->pollPayload($pollModel->fresh(['options.responses', 'responses'])));
    }

    public function destroy(string $subdomain, string $event, string $poll): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $eventModel->polls()->where('id', $poll)->firstOrFail()->delete();

        return response()->json(['message' => 'Poll deleted.']);
    }

    public function leaderboard(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        return response()->json($this->leaderboardPayload($eventModel));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function leaderboardPayload(Event $eventModel): array
    {
        $rows = EventPollResponse::query()
            ->whereHas('poll', fn ($q) => $q->where('event_id', $eventModel->id)->where('type', EventPoll::TYPE_QUIZ))
            ->selectRaw('respondent_token, SUM(points_awarded) as total_points, MAX(COALESCE(respondent_name, \'\')) as respondent_name')
            ->groupBy('respondent_token')
            ->orderByDesc('total_points')
            ->limit(20)
            ->get();

        return $rows->map(fn ($r): array => [
            'name' => $r->respondent_name !== '' ? $r->respondent_name : 'Anonymous',
            'points' => (int) $r->total_points,
        ])->values()->all();
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function pollPayload(EventPoll $poll): array
    {
        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'type' => $poll->type,
            'status' => $poll->status,
            'timer_seconds' => $poll->timer_seconds,
            'points' => $poll->points,
            'requires_moderation' => $poll->requires_moderation,
            'responses_count' => $poll->responses->where('is_approved', true)->count(),
            'options' => $poll->options->map(fn (EventPollOption $o): array => [
                'id' => $o->id,
                'label' => $o->label,
                'is_correct' => $o->is_correct,
                'responses_count' => $o->responses->count(),
            ])->values(),
            'open_responses' => $poll->type === EventPoll::TYPE_OPEN
                ? $poll->responses->where('is_approved', true)->pluck('response_text')->filter()->values()
                : [],
            'pending_responses' => $poll->type === EventPoll::TYPE_OPEN
                ? $poll->responses->whereNull('is_approved')->map(fn (EventPollResponse $r): array => [
                    'id' => $r->id,
                    'text' => $r->response_text,
                ])->values()
                : [],
        ];
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
