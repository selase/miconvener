<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\PollDeck;
use App\Models\Tenant;
use App\Services\Events\DeckPresenter;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventPollDeckController extends Controller
{
    public function __construct(private readonly DeckPresenter $presenter) {}

    /**
     * Every deck on this event, with the questions each holds, in order.
     */
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');

        $decks = PollDeck::where('tenant_id', $this->tenant()->id)
            ->where('event_id', $event)
            ->with(['polls:id,deck_id,position,question,type,status'])
            ->orderBy('created_at')
            ->get();

        return response()->json($decks->map(fn (PollDeck $deck): array => [
            'id' => $deck->id,
            'title' => $deck->title,
            'status' => $deck->status,
            'join_code' => $deck->join_code,
            'current_poll_id' => $deck->current_poll_id,
            'polls' => $deck->polls->map(fn (EventPoll $poll): array => [
                'id' => $poll->id,
                'position' => $poll->position,
                'question' => $poll->question,
                'type' => $poll->type,
                'status' => $poll->status,
            ])->values(),
        ])->values());
    }

    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
        ]);

        $eventModel = Event::where('tenant_id', $this->tenant()->id)
            ->where('id', $event)
            ->firstOrFail();

        if (! $this->tenant()->canUseLivePolling($eventModel)) {
            return response()->json([
                'message' => 'Live Polling & Audience Q&A requires the Growth plan or an active Live Polling add-on/event pass.',
                'upgrade_required' => true,
            ], 403);
        }

        $deck = PollDeck::create([
            'tenant_id' => $eventModel->tenant_id,
            'event_id' => $eventModel->id,
            'title' => $validated['title'],
            'join_code' => PollDeck::generateJoinCode(),
            'status' => PollDeck::STATUS_DRAFT,
        ]);

        return response()->json(['id' => $deck->id, 'title' => $deck->title, 'join_code' => $deck->join_code], 201);
    }

    /**
     * Set which questions this deck holds, and their order.
     *
     * Detaching first matters: (deck_id, position) is unique, so writing the
     * new positions straight over the old ones collides the moment two
     * questions swap places.
     */
    public function setPolls(Request $request, string $subdomain, string $event, string $deck): JsonResponse
    {
        $deckModel = $this->findDeck($event, $deck);

        $validated = $request->validate([
            'poll_ids' => ['present', 'array'],
            'poll_ids.*' => ['uuid'],
        ]);

        $owned = EventPoll::where('event_id', $deckModel->event_id)
            ->whereIn('id', $validated['poll_ids'])
            ->pluck('id')
            ->all();

        $ordered = array_values(array_filter(
            $validated['poll_ids'],
            static fn (string $id): bool => in_array($id, $owned, true)
        ));

        DB::transaction(function () use ($deckModel, $ordered): void {
            // A question being removed must not stay pointed at by the deck.
            if ($deckModel->current_poll_id !== null && ! in_array($deckModel->current_poll_id, $ordered, true)) {
                $deckModel->setCurrentPoll(null);
            }

            EventPoll::where('deck_id', $deckModel->id)->update(['deck_id' => null, 'position' => 0]);

            foreach ($ordered as $position => $pollId) {
                EventPoll::where('id', $pollId)->update([
                    'deck_id' => $deckModel->id,
                    'position' => $position,
                ]);
            }
        });

        return $this->payload($event, $deck);
    }

    public function destroy(string $subdomain, string $event, string $deck): JsonResponse
    {
        $deckModel = $this->findDeck($event, $deck);

        DB::transaction(function () use ($deckModel): void {
            // The questions outlive the deck; only the grouping goes.
            $deckModel->setCurrentPoll(null);
            EventPoll::where('deck_id', $deckModel->id)->update(['deck_id' => null, 'position' => 0]);
            $deckModel->delete();
        });

        return response()->json(['deleted' => true]);
    }

    /**
     * The link a presenter drives this deck from. Minted on first request rather
     * than whenever the console loads: it grants control, not just a view, so it
     * should exist only once someone has asked to hand it over. Asking needs the
     * same permission as pressing Next yourself.
     */
    public function presenterLink(string $subdomain, string $event, string $deck): JsonResponse
    {
        $deckModel = $this->findDeck($event, $deck);

        if (blank($deckModel->present_token)) {
            $deckModel->update(['present_token' => Str::random(48)]);
        }

        return response()->json(['presenter_url' => $this->presenterUrl($deckModel)]);
    }

    /**
     * Issues a new token, which is how the old link is revoked: a laptop still
     * holding it gets a 404 on its next press.
     */
    public function rotatePresenterLink(string $subdomain, string $event, string $deck): JsonResponse
    {
        $deckModel = $this->findDeck($event, $deck);
        $deckModel->update(['present_token' => Str::random(48)]);

        return response()->json(['presenter_url' => $this->presenterUrl($deckModel)]);
    }

    public function start(string $subdomain, string $event, string $deck): JsonResponse
    {
        $deckModel = $this->findDeck($event, $deck);
        $eventModel = Event::where('tenant_id', $this->tenant()->id)->where('id', $event)->firstOrFail();

        if (! $this->tenant()->canUseLivePolling($eventModel)) {
            return response()->json([
                'message' => 'Live Polling & Audience Q&A requires the Growth plan or an active Live Polling add-on/event pass.',
                'upgrade_required' => true,
            ], 403);
        }

        $this->presenter->start($deckModel);

        return $this->payload($event, $deck);
    }

    public function advance(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->advance($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    public function previous(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->previous($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    public function close(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->closeCurrent($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    public function end(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->end($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    private function presenterUrl(PollDeck $deck): string
    {
        return route('public.events.decks.presenter', [
            'subdomain' => $this->tenant()->slug,
            'event' => $deck->event->slug,
            'deck' => $deck->id,
            'token' => $deck->present_token,
        ]);
    }

    private function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        return $tenant;
    }

    private function findDeck(string $event, string $deck): PollDeck
    {
        $this->authorize('update event');

        return PollDeck::where('tenant_id', $this->tenant()->id)
            ->where('event_id', $event)
            ->where('id', $deck)
            ->with(['polls', 'currentPoll'])
            ->firstOrFail();
    }

    private function payload(string $event, string $deck): JsonResponse
    {
        $fresh = $this->findDeck($event, $deck);

        return response()->json([
            'id' => $fresh->id,
            'status' => $fresh->status,
            'join_code' => $fresh->join_code,
            'current_poll_id' => $fresh->current_poll_id,
            'position' => $fresh->currentPoll?->position,
            'total' => $fresh->polls->count(),
        ]);
    }
}
