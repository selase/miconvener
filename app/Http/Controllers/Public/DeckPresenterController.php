<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventPoll;
use App\Models\PollDeck;
use App\Models\Tenant;
use App\Services\Events\DeckPresenter;
use App\Services\Events\PollResults;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The presenter's own screen: the question the room is on, what comes next,
 * and the controls to move between them.
 *
 * Reached by a token rather than a login, for the same reason as the wall --
 * the person at the lectern is often a speaker with no console account, on a
 * laptop that is not theirs. The token is the deck's own, so it drives that one
 * deck and nothing else, and issuing a new one revokes the old.
 */
final class DeckPresenterController extends Controller
{
    public function __construct(
        private readonly DeckPresenter $presenter,
        private readonly PollResults $results,
    ) {}

    public function show(string $subdomain, string $event, string $deck, string $token): Response
    {
        $deckModel = $this->findByToken($event, $deck, $token);
        $eventModel = $deckModel->event;
        $tenant = $this->tenant();

        if (! $tenant->canUseLivePolling($eventModel)) {
            abort(403, 'Live Polling presentation is locked on this event. An upgrade or event pass is required.');
        }

        return Inertia::render('Public/Events/DeckPresenter', [
            'event' => [
                'id' => $eventModel->id,
                'name' => $eventModel->name,
            ],
            'organiser' => ['name' => $tenant->name],
            'deck' => $this->snapshot($deckModel),
            'stateUrl' => route('public.events.decks.presenter.state', [
                'subdomain' => $tenant->slug,
                'event' => $event,
                'deck' => $deck,
                'token' => $token,
            ]),
            'actionUrl' => route('public.events.decks.presenter', [
                'subdomain' => $tenant->slug,
                'event' => $event,
                'deck' => $deck,
                'token' => $token,
            ]),
            // The presenter already holds more authority than the wall grants,
            // so handing them the wall's link discloses nothing new.
            'wallUrl' => filled($eventModel->present_token)
                ? route('public.events.present', [
                    'subdomain' => $tenant->slug,
                    'event' => $eventModel->slug,
                    'token' => $eventModel->present_token,
                ])
                : null,
        ]);
    }

    /**
     * What the presenter screen re-reads when it has no socket.
     */
    public function state(string $subdomain, string $event, string $deck, string $token): JsonResponse
    {
        $deckModel = $this->findByToken($event, $deck, $token);

        if (! $this->tenant()->canUseLivePolling($deckModel->event)) {
            abort(403, 'Live Polling presentation is locked on this event.');
        }

        return response()->json($this->snapshot($deckModel));
    }

    public function act(string $subdomain, string $event, string $deck, string $token, string $action): JsonResponse
    {
        $deckModel = $this->findByToken($event, $deck, $token);

        if (! $this->tenant()->canUseLivePolling($deckModel->event)) {
            abort(403, 'Live Polling presentation is locked on this event.');
        }

        match ($action) {
            'start' => $this->presenter->start($deckModel),
            'advance' => $this->presenter->advance($deckModel),
            'previous' => $this->presenter->previous($deckModel),
            'close' => $this->presenter->closeCurrent($deckModel),
            'end' => $this->presenter->end($deckModel),
            default => abort(404),
        };

        return response()->json($this->snapshot($this->findByToken($event, $deck, $token)));
    }

    /**
     * Counts only, like the wall. The presenter sees what the room sees, plus
     * where they are in the deck and what comes next -- never who answered.
     *
     * @return array<string, mixed>
     */
    private function snapshot(PollDeck $deck): array
    {
        $deck->loadMissing(['polls:id,deck_id,position,question,type,status', 'currentPoll.options']);

        $polls = $deck->polls->values();
        $index = $deck->current_poll_id === null
            ? false
            : $polls->search(fn (EventPoll $poll): bool => $poll->id === $deck->current_poll_id);

        $next = match (true) {
            $index !== false => $polls->get($index + 1),
            $deck->status === PollDeck::STATUS_DRAFT => $polls->first(),
            default => null,
        };

        return [
            'id' => $deck->id,
            'title' => $deck->title,
            'status' => $deck->status,
            'position' => $index === false ? null : $index + 1,
            'total' => $polls->count(),
            'current' => $deck->currentPoll !== null ? $this->results->forDisplay($deck->currentPoll) : null,
            'next' => $next !== null ? ['question' => $next->question] : null,
            'can_go_back' => $index !== false && $index > 0,
            'can_advance' => $index !== false && $index < $polls->count() - 1,
            'questions' => $polls->map(fn (EventPoll $poll): string => $poll->question)->all(),
        ];
    }

    private function findByToken(string $event, string $deck, string $token): PollDeck
    {
        return PollDeck::query()
            ->where('tenant_id', $this->tenant()->id)
            ->where('id', $deck)
            ->where('present_token', $token)
            ->whereHas('event', fn ($query) => $query->where('slug', $event))
            ->with('event')
            ->firstOrFail();
    }

    private function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        return $tenant;
    }
}
