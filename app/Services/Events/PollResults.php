<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;

/**
 * Shapes a poll for a screen a whole room can read.
 *
 * One place, because three things render it -- the first page load, the
 * broadcast that follows each vote, and the poll the screen falls back to when
 * it has no socket -- and results that disagreed depending on which arrived
 * last would be worse than results that arrived late.
 *
 * Counts only. This payload reaches a page opened without any login, so it must
 * never carry a respondent token, a name, or anything that ties an answer to a
 * person. Open-text answers are the one exception and are included only once a
 * moderator has approved them.
 */
final class PollResults
{
    /**
     * @return array<string, mixed>
     */
    public function forDisplay(EventPoll $poll): array
    {
        $poll->loadMissing('options');

        // Grouped in SQL, not counted in PHP. A thousand people answering in a
        // hall used to mean a thousand hydrated models per render, and this
        // renders on every broadcast.
        //
        // is_approved is compared strictly to true. A response awaiting a
        // moderator carries null, and "not rejected" would put it on the wall,
        // which is the one thing moderating it was meant to prevent. Everything
        // unmoderated is written as approved at the point it is recorded.
        $grouped = EventPollResponse::query()
            ->where('poll_id', $poll->id)
            ->where('is_approved', true)
            ->selectRaw('option_id, count(*) as total')
            ->groupBy('option_id')
            ->get();

        // Summed across every group, including the null one an open answer
        // falls into, so an open poll still reports how many people replied.
        $total = (int) $grouped->sum('total');
        $counts = $grouped->pluck('total', 'option_id');

        $options = $poll->options
            ->sortBy('sort_order')
            ->map(function (EventPollOption $option) use ($poll, $counts, $total): array {
                $count = (int) ($counts[$option->id] ?? 0);

                return [
                    'id' => $option->id,
                    'label' => $option->label,
                    'count' => $count,
                    // Rounded for a wall, not for arithmetic: these are read at
                    // ten metres, and four of them need not total exactly 100.
                    'percentage' => $total > 0 ? (int) round($count / $total * 100) : 0,
                    // Only worth revealing once nobody can still be answering.
                    'is_correct' => $poll->status === EventPoll::STATUS_CLOSED
                        ? (bool) $option->is_correct
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'type' => $poll->type,
            'status' => $poll->status,
            'total_responses' => $total,
            'options' => $options,
            // Capped in the query rather than after loading everything: the
            // wall shows the last thirty, however many were written.
            'open_responses' => $poll->type === EventPoll::TYPE_OPEN
                ? EventPollResponse::query()
                    ->where('poll_id', $poll->id)
                    ->where('is_approved', true)
                    ->whereNotNull('response_text')
                    ->where('response_text', '!=', '')
                    ->orderByDesc('created_at')
                    ->limit(30)
                    ->get()
                    ->map(fn (EventPollResponse $r): array => [
                        'id' => $r->id,
                        'text' => $r->response_text,
                        // Shown only where the poll itself asked for a name.
                        'name' => $r->respondent_name,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
