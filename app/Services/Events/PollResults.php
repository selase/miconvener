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
        $poll->loadMissing(['options', 'responses']);

        $approved = $poll->responses->filter(
            fn (EventPollResponse $r): bool => $r->is_approved !== false
        );

        $total = $approved->count();

        $options = $poll->options
            ->sortBy('sort_order')
            ->map(function (EventPollOption $option) use ($poll, $approved, $total): array {
                $count = $approved->where('option_id', $option->id)->count();

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
            'open_responses' => $poll->type === EventPoll::TYPE_OPEN
                ? $approved
                    ->filter(fn (EventPollResponse $r): bool => filled($r->response_text))
                    ->sortByDesc('created_at')
                    ->take(30)
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
