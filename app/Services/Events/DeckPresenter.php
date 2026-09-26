<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Events\PollResultsUpdated;
use App\Models\EventPoll;
use App\Models\PollDeck;
use Illuminate\Support\Facades\DB;

final class DeckPresenter
{
    /**
     * The only writer of current_poll_id. Moving the pointer and changing the
     * two polls' statuses happen together or not at all: a room that saw the
     * question change must never be voting on one that is still open behind it.
     */
    public function start(PollDeck $deck): void
    {
        $first = $deck->polls()->first();

        if ($first === null) {
            return;
        }

        DB::transaction(function () use ($deck, $first): void {
            $deck->update(['status' => PollDeck::STATUS_LIVE]);
            $deck->setCurrentPoll($first);
            $first->update(['status' => EventPoll::STATUS_LIVE, 'went_live_at' => now()]);
        });

        PollResultsUpdated::dispatch($first->fresh(['options', 'responses']));
    }

    public function advance(PollDeck $deck): ?EventPoll
    {
        return $this->moveTo($deck, $this->neighbour($deck, forward: true));
    }

    public function previous(PollDeck $deck): ?EventPoll
    {
        return $this->moveTo($deck, $this->neighbour($deck, forward: false));
    }

    public function closeCurrent(PollDeck $deck): void
    {
        $current = $deck->currentPoll;

        if ($current === null) {
            return;
        }

        $current->update(['status' => EventPoll::STATUS_CLOSED]);

        PollResultsUpdated::dispatch($current->fresh(['options', 'responses']));
    }

    public function end(PollDeck $deck): void
    {
        DB::transaction(function () use ($deck): void {
            $deck->currentPoll?->update(['status' => EventPoll::STATUS_CLOSED]);
            $deck->update(['status' => PollDeck::STATUS_ENDED]);
            $deck->setCurrentPoll(null);
        });
    }

    private function neighbour(PollDeck $deck, bool $forward): ?EventPoll
    {
        $current = $deck->currentPoll;

        if ($current === null) {
            return $forward ? $deck->polls()->first() : null;
        }

        // reorder() first: polls() already carries ORDER BY position, and an
        // appended descending sort never wins, so going back would land on the
        // first question in the deck rather than the one just behind.
        return $deck->polls()
            ->where('position', $forward ? '>' : '<', $current->position)
            ->reorder()
            ->orderBy('position', $forward ? 'asc' : 'desc')
            ->first();
    }

    private function moveTo(PollDeck $deck, ?EventPoll $next): ?EventPoll
    {
        if ($next === null) {
            return null;
        }

        $outgoing = $deck->currentPoll;

        DB::transaction(function () use ($deck, $next, $outgoing): void {
            $outgoing?->update(['status' => EventPoll::STATUS_CLOSED]);
            $next->update(['status' => EventPoll::STATUS_LIVE, 'went_live_at' => now()]);
            $deck->setCurrentPoll($next);
        });

        PollResultsUpdated::dispatch($next->fresh(['options', 'responses']));

        return $next;
    }
}
