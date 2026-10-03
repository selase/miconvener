<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;
use App\Services\Events\PollResults;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            'type' => ['required', Rule::in(EventPoll::TYPES)],
            'options' => [Rule::requiredIf(in_array($request->input('type'), EventPoll::AUTHORED_OPTION_TYPES, true)), 'array', 'min:2', 'max:12'],
            'options.*' => ['string', 'max:255'],
            'correct_option_index' => ['required_if:type,quiz', 'nullable', 'integer', 'min:0'],
            'timer_seconds' => ['nullable', 'integer', 'min:5', 'max:300'],
            'points' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'requires_moderation' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.min' => ['required_if:type,scale', 'integer', 'min:0', 'max:100'],
            'settings.max' => ['required_if:type,scale', 'integer', 'gt:settings.min', 'max:100'],
            'settings.label_min' => ['nullable', 'string', 'max:40'],
            'settings.label_max' => ['nullable', 'string', 'max:40'],
            'settings.unit' => ['nullable', 'string', 'max:20'],
        ]);

        $type = $validated['type'];

        $poll = $eventModel->polls()->create([
            'tenant_id' => $tenant->id,
            'question' => $validated['question'],
            'type' => $type,
            'timer_seconds' => $type === EventPoll::TYPE_QUIZ ? ($validated['timer_seconds'] ?? null) : null,
            'points' => $validated['points'] ?? 10,
            'requires_moderation' => in_array($type, EventPoll::MODERATED_TYPES, true) ? (bool) ($validated['requires_moderation'] ?? false) : false,
            'settings' => $this->settingsFor($type, $validated['settings'] ?? []),
        ]);

        foreach ($this->optionLabelsFor($type, $validated['options'] ?? []) as $i => $label) {
            $poll->options()->create([
                'tenant_id' => $tenant->id,
                'label' => $label,
                'sort_order' => $i,
                'is_correct' => $type === EventPoll::TYPE_QUIZ && (int) $validated['correct_option_index'] === $i,
            ]);
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
     * The link an AV desk opens, minted on first ask.
     */
    public function presentLink(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        if (blank($eventModel->present_token)) {
            $eventModel->update(['present_token' => Str::random(40)]);
        }

        return response()->json(['present_url' => $this->presentUrl($tenant->slug, $eventModel)]);
    }

    /**
     * Issues a new token, which is how an organiser revokes the old link --
     * the laptop it was opened on stops showing results at the next refresh.
     */
    public function rotatePresentLink(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $eventModel->update(['present_token' => Str::random(40)]);

        return response()->json(['present_url' => $this->presentUrl($tenant->slug, $eventModel)]);
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

    private function presentUrl(string $subdomain, Event $event): string
    {
        return route('public.events.present', [
            'subdomain' => $subdomain,
            'event' => $event->slug,
            'token' => $event->present_token,
        ]);
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
            'settings' => $poll->settings ?? (object) [],
            // What the wall is showing, so the organiser sees the same chart.
            'results' => app(PollResults::class)->forDisplay($poll),
            'pending_responses' => in_array($poll->type, EventPoll::MODERATED_TYPES, true)
                ? $poll->responses->whereNull('is_approved')->map(fn (EventPollResponse $r): array => [
                    'id' => $r->id,
                    'text' => $r->response_text,
                ])->values()
                : [],
        ];
    }

    /**
     * Yes/no and rating fix their own options, so every such question is
     * answered and counted the same way; the rest use what the organiser wrote.
     *
     * @param  array<int, string>  $authored  as submitted, which need not be numbered from zero
     * @return list<string>
     */
    private function optionLabelsFor(string $type, array $authored): array
    {
        return match ($type) {
            EventPoll::TYPE_YES_NO => ['Yes', 'No'],
            EventPoll::TYPE_RATING => ['1', '2', '3', '4', '5'],
            default => in_array($type, EventPoll::AUTHORED_OPTION_TYPES, true) ? array_values($authored) : [],
        };
    }

    /**
     * Only the settings the type uses are kept, so a scale's ends cannot leak
     * onto a number question from a form that was switched type halfway.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function settingsFor(string $type, array $settings): ?array
    {
        return match ($type) {
            EventPoll::TYPE_SCALE => [
                'min' => (int) $settings['min'],
                'max' => (int) $settings['max'],
                'label_min' => $settings['label_min'] ?? null,
                'label_max' => $settings['label_max'] ?? null,
            ],
            EventPoll::TYPE_NUMBER => ['unit' => $settings['unit'] ?? null],
            default => null,
        };
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
