<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PollDeck;
use App\Models\Tenant;
use App\Services\Events\DeckPresenter;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class EventPollDeckController extends Controller
{
    public function __construct(private readonly DeckPresenter $presenter) {}

    public function start(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->start($this->findDeck($event, $deck));

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

    private function findDeck(string $event, string $deck): PollDeck
    {
        $this->authorize('update event');

        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        return PollDeck::where('tenant_id', $tenant->id)
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
