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
        // A poll is answered from the portal now, where the voter is a known,
        // checked-in registration. This route stays so an old QR or a bookmarked page
        // says something useful rather than 404ing in someone's hand.
        return response()->json([
            'message' => 'Open your ticket in the MiConvener portal to answer.',
            'portal_url' => url('/my'),
        ], 410);
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
