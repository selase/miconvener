<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\PollDeck;
use App\Services\Events\PollResults;
use App\Services\Events\QrCodeGenerator;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantHostMatcher;
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

        if (! $this->tenant()->canUseLivePolling($eventModel)) {
            abort(403, 'Live Polling presentation is locked on this event. An upgrade or event pass is required.');
        }

        $joinUrl = route('public.events.poll.show', [
            'subdomain' => $this->tenant()->slug,
            'event' => $eventModel->slug,
        ]);

        // The room needs to reach somewhere it can actually answer without
        // being read a URL. This used to point at /e/{event}/poll, the data
        // endpoint the public page fetches, so anyone who scanned it got a
        // blob of JSON. Answering happens in the portal now.
        //
        // Built against the platform host, not this request's: the wall is
        // served from the tenant's subdomain and the portal is not, so url()
        // would send a scanned phone to a subdomain that redirects it away.
        $joinPage = request()->getScheme().'://'.app(TenantHostMatcher::class)->baseDomain().'/my';

        return Inertia::render('Public/Events/PollPresentation', [
            'event' => [
                'id' => $eventModel->id,
                'name' => $eventModel->name,
            ],
            'organiser' => ['name' => $this->tenant()->name],
            'poll' => $this->livePayload($eventModel),
            // Zero bars and a room that cannot answer yet look identical from
            // the back of a hall, and only one of them is something an
            // organiser can act on.
            'eligibleVoters' => $eventModel->registrations()
                ->confirmed()
                ->whereNotNull('checked_in_at')
                ->count(),
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

        if (! $this->tenant()->canUseLivePolling($eventModel)) {
            abort(403, 'Live Polling presentation is locked on this event.');
        }

        return response()->json(['poll' => $this->livePayload($eventModel)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function livePayload(Event $event): ?array
    {
        // A deck decides what the room is looking at. Only the polls are eager
        // loaded, never the responses -- PollResults counts in SQL, and the
        // wall is the busiest reader there is.
        $deck = PollDeck::query()
            ->where('event_id', $event->id)
            ->where('status', PollDeck::STATUS_LIVE)
            ->with('currentPoll.options')
            ->first();

        if ($deck?->currentPoll !== null) {
            return $this->results->forDisplay($deck->currentPoll);
        }

        // No deck running: fall back to whatever went live on its own.
        $poll = $event->polls()
            // The relation sorts by created_at, which would otherwise decide
            // this outright and leave the ordering below as a tiebreaker that
            // only ever fires when two polls are created in the same second.
            ->reorder()
            ->whereIn('status', [EventPoll::STATUS_LIVE, EventPoll::STATUS_CLOSED])
            ->with('options')
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
