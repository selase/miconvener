<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicPollController extends Controller
{
    public function show(string $subdomain, string $event): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        $poll = $eventModel->polls()->live()->with('options')->first();

        if (! $poll) {
            // Laravel's response()->json(null) actually serializes to "{}",
            // not the JSON literal null — wrapping in an object makes "no
            // live poll" unambiguous for the client either way.
            return response()->json(['poll' => null]);
        }

        return response()->json([
            'poll' => [
                'id' => $poll->id,
                'question' => $poll->question,
                'type' => $poll->type,
                'timer_seconds' => $poll->timer_seconds,
                'points' => $poll->points,
                'went_live_at' => $poll->went_live_at?->toIso8601String(),
                'options' => $poll->options->map(fn (EventPollOption $o): array => ['id' => $o->id, 'label' => $o->label])->values(),
            ],
        ]);
    }

    public function respond(Request $request, string $subdomain, string $event, string $poll): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $pollModel = $eventModel->polls()->live()->where('id', $poll)->with('options')->firstOrFail();

        if ($pollModel->type === EventPoll::TYPE_QUIZ && $pollModel->timeUp()) {
            return response()->json(['message' => "Time's up for this question."], 422);
        }

        // The poll's own type decides which field is required — not something
        // the client sends, since it can't be trusted to say what kind of
        // poll this is.
        $validated = $request->validate([
            'option_id' => [
                in_array($pollModel->type, [EventPoll::TYPE_MULTIPLE_CHOICE, EventPoll::TYPE_QUIZ], true) ? 'required' : 'prohibited',
                'nullable',
                Rule::exists('event_poll_options', 'id')->where('poll_id', $pollModel->id),
            ],
            'response_text' => [
                $pollModel->type === EventPoll::TYPE_OPEN ? 'required' : 'prohibited',
                'nullable',
                'string',
                'max:1000',
            ],
            'respondent_name' => ['nullable', 'string', 'max:100'],
            'respondent_token' => ['required', 'string', 'max:64'],
        ]);

        $existing = $pollModel->responses()->where('respondent_token', $validated['respondent_token'])->exists();
        if ($existing) {
            return response()->json(['message' => 'You already responded to this poll.'], 422);
        }

        $selectedOption = $pollModel->type === EventPoll::TYPE_QUIZ
            ? $pollModel->options->firstWhere('id', $validated['option_id'] ?? null)
            : null;

        $response = $pollModel->responses()->create([
            'tenant_id' => $tenant->id,
            'option_id' => $validated['option_id'] ?? null,
            'response_text' => $validated['response_text'] ?? null,
            'respondent_name' => $validated['respondent_name'] ?? null,
            'respondent_token' => $validated['respondent_token'],
            'is_correct' => $selectedOption ? $selectedOption->is_correct : null,
            'points_awarded' => $selectedOption?->is_correct ? $pollModel->points : 0,
            'is_approved' => $pollModel->type === EventPoll::TYPE_OPEN && $pollModel->requires_moderation ? null : true,
        ]);

        return response()->json([
            'message' => 'Thanks for responding!',
            'is_correct' => $pollModel->type === EventPoll::TYPE_QUIZ ? $response->is_correct : null,
            'points_awarded' => $response->points_awarded,
        ]);
    }

    public function leaderboard(string $subdomain, string $event): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        $rows = EventPollResponse::query()
            ->whereHas('poll', fn ($q) => $q->where('event_id', $eventModel->id)->where('type', EventPoll::TYPE_QUIZ))
            ->selectRaw('respondent_token, SUM(points_awarded) as total_points, MAX(COALESCE(respondent_name, \'\')) as respondent_name')
            ->groupBy('respondent_token')
            ->orderByDesc('total_points')
            ->limit(20)
            ->get();

        return response()->json($rows->map(fn ($r): array => [
            'name' => $r->respondent_name !== '' ? $r->respondent_name : 'Anonymous',
            'points' => (int) $r->total_points,
        ])->values());
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
