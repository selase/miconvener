<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\PollDeck;
use App\Services\Events\PollAnswer;
use App\Services\Events\PollBroadcastCoalescer;
use App\Services\Events\PollResults;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class GuestPollController extends Controller
{
    public function show(Request $request, string $subdomain, string $event): Response
    {
        $model = $this->event($event);

        $response = Inertia::render('Public/Events/GuestPoll', [
            'event' => ['name' => $model->name],
            'joined' => $this->token($request, $model) !== null,
            'joinUrl' => route('public.events.guest-polls.join', ['subdomain' => $subdomain, 'event' => $event]),
            'pollUrl' => route('public.events.guest-polls.state', ['subdomain' => $subdomain, 'event' => $event]),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function join(Request $request, string $subdomain, string $event): JsonResponse
    {
        $model = $this->event($event);
        if ($this->token($request, $model) === null) {
            $request->session()->put('guest_poll_tokens.'.$model->id, Str::random(64));
        }

        return $this->json(['joined' => true]);
    }

    public function state(Request $request, string $subdomain, string $event): JsonResponse
    {
        $model = $this->event($event);
        $token = $this->token($request, $model);
        abort_if($token === null, 403, 'Choose Join live polls first.');
        $poll = $this->inWindow($model) ? $this->current($model) : null;
        if ($poll === null) {
            return $this->json(['poll' => null]);
        }
        $poll->loadMissing('options');
        $mine = $poll->responses()->where('respondent_token', $token)->first();
        $results = app(PollResults::class)->forDisplay($poll);
        $resultOptions = $results['options'] ?? [];
        $counts = collect(is_array($resultOptions) ? $resultOptions : [])->keyBy('id');

        return $this->json(['poll' => [
            'id' => $poll->id, 'question' => $poll->question, 'type' => $poll->type, 'status' => $poll->status,
            'timer_seconds' => $poll->timer_seconds, 'points' => $poll->points,
            'went_live_at' => $poll->went_live_at?->toIso8601String(),
            'is_time_up' => $poll->type === EventPoll::TYPE_QUIZ && $poll->timeUp(),
            'settings' => $poll->settings ?? (object) [], 'results' => $results,
            'total_votes' => $results['total_responses'],
            'options' => $poll->options->map(fn ($option): array => [
                'id' => $option->id, 'label' => $option->label,
                'votes_count' => $counts->get($option->id)['count'] ?? null,
                'percentage' => $counts->get($option->id)['percentage'] ?? null,
            ])->values()->all(),
            'user_response' => $mine === null ? null : [
                'option_id' => $mine->option_id, 'option_ids' => $mine->option_id ? [$mine->option_id] : ($mine->response_payload ?? []),
                'response_number' => $mine->response_number, 'words' => $mine->response_payload ?? [],
                'response_text' => $mine->response_text, 'text_response' => $mine->response_text,
                'is_correct' => $mine->is_correct, 'score' => $mine->points_awarded,
            ],
        ]]);
    }

    public function respond(Request $request, string $subdomain, string $event, string $poll): JsonResponse
    {
        $model = $this->event($event);
        $token = $this->token($request, $model);
        abort_if($token === null, 403, 'Choose Join live polls first.');

        return $model->getConnection()->transaction(function () use ($request, $model, $event, $poll, $token): JsonResponse {
            $model = $this->event($event, true);
            abort_unless($this->inWindow($model), 403, 'Live polls are available during the event.');
            $question = $model->polls()->whereKey($poll)->sharedLock()->with('options')->firstOrFail();
            abort_unless($question->status === EventPoll::STATUS_LIVE && $this->current($model)?->id === $question->id, 422, 'That question has closed.');
            abort_if($question->type === EventPoll::TYPE_QUIZ && $question->timeUp(), 422, 'Time is up for this question.');
            $answer = app(PollAnswer::class)->read($request, $question);
            $selected = $question->type === EventPoll::TYPE_QUIZ ? $question->options->firstWhere('id', $answer['option_id']) : null;
            $moderated = in_array($question->type, EventPoll::MODERATED_TYPES, true) && $question->requires_moderation;
            $response = $question->responses()->createOrFirst(['poll_id' => $question->id, 'respondent_token' => $token], [
                'tenant_id' => $model->tenant_id, ...$answer, 'respondent_name' => null,
                'is_correct' => $selected?->is_correct,
                'points_awarded' => $selected?->is_correct ? $question->points : 0,
                'is_approved' => $moderated ? null : true,
            ]);
            abort_unless($response->wasRecentlyCreated, 422, 'You already responded to this poll.');
            $model->getConnection()->afterCommit(fn () => app(PollBroadcastCoalescer::class)->schedule($question->fresh('options')));

            return $this->json(['message' => 'Thanks for responding!', 'is_correct' => $response->is_correct, 'score' => $response->points_awarded]);
        });
    }

    private function event(string $slug, bool $lock = false): Event
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 404);
        $query = Event::query()->where('tenant_id', $tenant->id)->where('slug', $slug)->published()->where('visibility', Event::VISIBILITY_PUBLIC)->where('guest_polls_enabled', true);
        $model = ($lock ? $query->sharedLock() : $query)->firstOrFail();
        abort_unless($tenant->canUseLivePolling($model), 404);

        return $model;
    }

    private function inWindow(Event $event): bool
    {
        return now()->greaterThanOrEqualTo($event->starts_at) && now()->lessThan($event->ends_at);
    }

    private function token(Request $request, Event $event): ?string
    {
        $token = $request->session()->get('guest_poll_tokens.'.$event->id);

        return is_string($token) && mb_strlen($token) === 64 ? hash('sha256', 'guest-poll|'.$event->id.'|'.$token) : null;
    }

    private function current(Event $event): ?EventPoll
    {
        $deck = PollDeck::query()->where('event_id', $event->id)->where('status', PollDeck::STATUS_LIVE)->first();
        $query = $event->polls()->whereIn('status', [EventPoll::STATUS_LIVE, EventPoll::STATUS_CLOSED]);

        return $deck !== null ? $query->whereKey($deck->current_poll_id)->first() : $query->reorder()->orderByRaw('case when status = ? then 0 else 1 end', [EventPoll::STATUS_LIVE])->orderByDesc('went_live_at')->first();
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
