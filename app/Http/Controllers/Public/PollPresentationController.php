<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPoll;
use App\Services\Events\PollResults;
use App\Services\Events\QrCodeGenerator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen behind the speaker.
 *
 * Reached by a token rather than a login, because the laptop driving a
 * projector at a conference is rarely the organiser's own. The token opens this
 * and nothing else, and issuing a new one revokes the old.
 */
final class PollPresentationController extends Controller
{
    public function __construct(private readonly PollResults $results) {}

    public function show(string $subdomain, string $event, string $token): Response
    {
        $eventModel = $this->findByToken($event, $token);

        $joinUrl = route('public.events.poll.show', [
            'subdomain' => $this->tenant()->slug,
            'event' => $eventModel->slug,
        ]);

        // The room needs to reach the voting page without being read a URL.
        $joinPage = url("/e/{$eventModel->slug}/poll");

        return Inertia::render('Public/Events/PollPresentation', [
            'event' => [
                'id' => $eventModel->id,
                'name' => $eventModel->name,
            ],
            'organiser' => ['name' => $this->tenant()->name],
            'poll' => $this->livePayload($eventModel),
            'join' => [
                'url' => $joinPage,
                'qr' => QrCodeGenerator::svgDataUri($joinPage),
            ],
            'resultsUrl' => route('public.events.present.results', [
                'subdomain' => $this->tenant()->slug,
                'event' => $eventModel->slug,
                'token' => $token,
            ]),
            'joinRoute' => $joinUrl,
        ]);
    }

    /**
     * What the screen falls back to when it has no socket.
     */
    public function results(string $subdomain, string $event, string $token): JsonResponse
    {
        $eventModel = $this->findByToken($event, $token);

        return response()->json(['poll' => $this->livePayload($eventModel)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function livePayload(Event $event): ?array
    {
        $poll = $event->polls()
            ->whereIn('status', [EventPoll::STATUS_LIVE, EventPoll::STATUS_CLOSED])
            ->with(['options', 'responses'])
            // A poll that is open beats one that has closed, however recently.
            // Ordering on went_live_at alone would let the last poll an
            // organiser closed sit on the wall while the room is answering the
            // next one.
            ->orderByRaw('case when status = ? then 0 else 1 end', [EventPoll::STATUS_LIVE])
            ->orderByDesc('went_live_at')
            ->first();

        return $poll instanceof EventPoll ? $this->results->forDisplay($poll) : null;
    }

    private function findByToken(string $event, string $token): Event
    {
        return Event::where('tenant_id', $this->tenant()->id)
            ->where('slug', $event)
            ->where('present_token', $token)
            ->firstOrFail();
    }

    private function tenant(): \App\Models\Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
