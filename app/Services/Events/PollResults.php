<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;
use Illuminate\Database\Eloquent\Builder;

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
        // Through the poll, not the tenant in context, for the same reason as
        // answers(): left to the global scope, a mismatched context returns no
        // options at all, and "no option has a count" then reads as success.
        $poll->loadMissing(['options' => fn ($query) => $query->withoutGlobalScopes()]);

        // Grouped in SQL, not counted in PHP. A thousand people answering in a
        // hall used to mean a thousand hydrated models per render, and this
        // renders on every broadcast.
        //
        // is_approved is compared strictly to true. A response awaiting a
        // moderator carries null, and "not rejected" would put it on the wall,
        // which is the one thing moderating it was meant to prevent. Everything
        // unmoderated is written as approved at the point it is recorded.
        $grouped = $this->answers($poll)
            ->where('is_approved', true)
            ->selectRaw('option_id, count(*) as total')
            ->groupBy('option_id')
            ->get();

        // Summed across every group, including the null one an open answer
        // falls into, so an open poll still reports how many people replied.
        $total = (int) $grouped->sum('total');
        // Answers with no option -- a number, a scale point, words -- group
        // under a null option_id, which is no array key: PHP 8.5 deprecates it.
        $counts = $grouped->whereNotNull('option_id')->pluck('total', 'option_id');

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

        $suppressed = in_array($poll->type, EventPoll::SUPPRESSED_TYPES, true) && $total < EventPoll::SUPPRESS_BELOW;

        $summary = match ($poll->type) {
            EventPoll::TYPE_MULTI_SELECT => $this->multiSelect($poll, $total, $options),
            EventPoll::TYPE_RANKING => $this->ranking($poll, $total, $options),
            EventPoll::TYPE_RATING => $suppressed ? null : $this->rating($total, $options),
            EventPoll::TYPE_SCALE => $suppressed ? null : $this->scale($poll, $total),
            EventPoll::TYPE_NUMBER => $suppressed ? null : $this->number($poll, $total),
            EventPoll::TYPE_WORD_CLOUD => ['words' => $this->words($poll)],
            default => null,
        };

        if (in_array($poll->type, [EventPoll::TYPE_MULTI_SELECT, EventPoll::TYPE_RANKING], true)) {
            $options = $summary['options'];
            unset($summary['options']);
        }

        if ($suppressed) {
            // Withheld rather than hidden: this payload reaches pages anyone can
            // open, so a count kept off the screen but left in the data has
            // still been disclosed.
            $options = array_map(fn (array $o): array => [...$o, 'count' => null, 'percentage' => null], $options);
        }

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'type' => $poll->type,
            'status' => $poll->status,
            'total_responses' => $total,
            'options' => $options,
            'settings' => $this->publicSettings($poll),
            'suppressed' => $suppressed,
            'suppress_below' => EventPoll::SUPPRESS_BELOW,
            'summary' => $summary,
            // Capped in the query rather than after loading everything: the
            // wall shows the last thirty, however many were written.
            'open_responses' => $poll->type === EventPoll::TYPE_OPEN
                ? $this->answers($poll)
                    ->where('is_approved', true)
                    ->whereNotNull('response_text')
                    // Must hold at least one character that is not whitespace.
                    // btrim() strips only spaces by default, so a tab- or
                    // newline-only answer survived it -- the same blank bubble,
                    // taking one of the thirty slots, via a narrower door.
                    ->whereRaw("response_text ~ '[^[:space:]]'")
                    // created_at is second-precision, so a busy question ties
                    // dozens of rows. Without the id as a tiebreaker the LIMIT
                    // decides WHICH thirty, and the wall reshuffles between
                    // renders. Ids are time-ordered UUIDv7, so this also keeps
                    // the newest first.
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
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

    /**
     * One poll's answers, pinned by the poll rather than by whichever tenant is
     * in context. The poll was found through its event already, and the raw
     * aggregates below cannot see a global scope at all; filtering the same way
     * everywhere means the total and its breakdown can never disagree.
     *
     * @return Builder<EventPollResponse>
     */
    private function answers(EventPoll $poll): Builder
    {
        return EventPollResponse::withoutGlobalScopes()->where('poll_id', $poll->id);
    }

    /**
     * Only what a chart needs to draw itself -- a scale's ends and labels, a
     * number's unit. Nothing an organiser might keep private lives here.
     *
     * @return array<string, mixed>
     */
    private function publicSettings(EventPoll $poll): array
    {
        return match ($poll->type) {
            EventPoll::TYPE_SCALE => [
                'min' => (int) $poll->setting('min', 1),
                'max' => (int) $poll->setting('max', 10),
                'label_min' => $poll->setting('label_min'),
                'label_max' => $poll->setting('label_max'),
            ],
            EventPoll::TYPE_NUMBER => ['unit' => $poll->setting('unit')],
            default => [],
        };
    }

    /**
     * Each option as a share of the people who answered, not of the ticks: when
     * everyone picks every option, each is 100%, and a chart whose bars add up
     * to 500% would be a lie.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array{respondents: int, options: list<array<string, mixed>>}
     */
    private function multiSelect(EventPoll $poll, int $respondents, array $options): array
    {
        $counts = collect(EventPollResponse::query()->getConnection()->select(
            'select picked as option_id, count(*) as total
               from event_poll_responses, jsonb_array_elements_text(response_payload) as picked
              where poll_id = ? and is_approved = true
              group by picked',
            [$poll->id],
        ))->pluck('total', 'option_id');

        return [
            'respondents' => $respondents,
            'options' => array_map(function (array $option) use ($counts, $respondents): array {
                $count = (int) ($counts[$option['id']] ?? 0);

                return [
                    ...$option,
                    'count' => $count,
                    'percentage' => $respondents > 0 ? (int) round($count / $respondents * 100) : 0,
                ];
            }, $options),
        ];
    }

    /**
     * Options in the order the room put them, by average position. The count
     * of people goes with it, because an average over four voters is not a
     * result.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array{respondents: int, options: list<array<string, mixed>>}
     */
    private function ranking(EventPoll $poll, int $respondents, array $options): array
    {
        $averages = collect(EventPollResponse::query()->getConnection()->select(
            'select ranked.option_id, avg(ranked.position) as average_position
               from event_poll_responses,
                    jsonb_array_elements_text(response_payload) with ordinality as ranked(option_id, position)
              where poll_id = ? and is_approved = true
              group by ranked.option_id',
            [$poll->id],
        ))->pluck('average_position', 'option_id');

        $ranked = array_map(fn (array $option): array => [
            ...$option,
            'average_position' => isset($averages[$option['id']]) ? round((float) $averages[$option['id']], 2) : null,
        ], $options);

        // Unranked options, before anyone has answered, keep the order the
        // organiser wrote them in.
        usort($ranked, fn (array $a, array $b): int => ($a['average_position'] ?? PHP_INT_MAX) <=> ($b['average_position'] ?? PHP_INT_MAX));

        return ['respondents' => $respondents, 'options' => $ranked];
    }

    /**
     * Stars are the options "1" to "5", so the average is weighted by label.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array{average: float, out_of: int}
     */
    private function rating(int $total, array $options): array
    {
        $sum = array_sum(array_map(fn (array $o): int => (int) $o['label'] * (int) $o['count'], $options));

        return ['average' => $total > 0 ? round($sum / $total, 1) : 0.0, 'out_of' => count($options)];
    }

    /**
     * Every point on the scale, including the ones nobody chose, so the spread
     * reads as a shape along the whole line rather than a few bars.
     *
     * @return array{average: float, distribution: list<array{value: int, count: int}>}
     */
    private function scale(EventPoll $poll, int $total): array
    {
        $min = (int) $poll->setting('min', 1);
        $max = (int) $poll->setting('max', 10);

        $counts = $this->answers($poll)
            ->where('is_approved', true)
            ->selectRaw('cast(response_number as integer) as value, count(*) as total')
            ->groupByRaw('cast(response_number as integer)')
            ->pluck('total', 'value');

        $sum = 0;
        $distribution = [];

        for ($value = $min; $value <= $max; $value++) {
            $count = (int) ($counts[$value] ?? 0);
            $sum += $value * $count;
            $distribution[] = ['value' => $value, 'count' => $count];
        }

        return ['average' => $total > 0 ? round($sum / $total, 1) : 0.0, 'distribution' => $distribution];
    }

    /**
     * Average, median and range, and six even buckets between the smallest and
     * largest answer for the histogram.
     *
     * @return array<string, mixed>
     */
    private function number(EventPoll $poll, int $total): array
    {
        $stats = EventPollResponse::query()->getConnection()->selectOne(
            'select avg(response_number) as average,
                    percentile_cont(0.5) within group (order by response_number) as median,
                    min(response_number) as minimum,
                    max(response_number) as maximum
               from event_poll_responses
              where poll_id = ? and is_approved = true and response_number is not null',
            [$poll->id],
        );

        $min = (float) ($stats->minimum ?? 0);
        $max = (float) ($stats->maximum ?? 0);
        $buckets = 6;

        $histogram = [];

        if ($total > 0 && $max > $min) {
            $width = ($max - $min) / $buckets;
            // The upper edge is nudged so the largest answer lands in the last
            // bucket rather than in an overflow one past it.
            $counts = collect(EventPollResponse::query()->getConnection()->select(
                'select width_bucket(response_number, ?, ?, ?) as bucket, count(*) as total
                   from event_poll_responses
                  where poll_id = ? and is_approved = true and response_number is not null
                  group by bucket',
                [$min, $max + ($width / 1000), $buckets, $poll->id],
            ))->pluck('total', 'bucket');

            for ($bucket = 1; $bucket <= $buckets; $bucket++) {
                $histogram[] = [
                    'from' => round($min + ($bucket - 1) * $width, 2),
                    'to' => round($min + $bucket * $width, 2),
                    'count' => (int) ($counts[$bucket] ?? 0),
                ];
            }
        } elseif ($total > 0) {
            $histogram[] = ['from' => $min, 'to' => $max, 'count' => $total];
        }

        return [
            'average' => round((float) ($stats->average ?? 0), 2),
            'median' => round((float) ($stats->median ?? 0), 2),
            'minimum' => $min,
            'maximum' => $max,
            'histogram' => $histogram,
        ];
    }

    /**
     * The forty most-used words, approved only: a word awaiting a moderator
     * never reaches the wall, exactly as an open answer would not.
     *
     * @return list<array{text: string, count: int}>
     */
    private function words(EventPoll $poll): array
    {
        return collect(EventPollResponse::query()->getConnection()->select(
            'select word, count(*) as total
               from event_poll_responses, jsonb_array_elements_text(response_payload) as word
              where poll_id = ? and is_approved = true
              group by word
              order by total desc, word asc
              limit 40',
            [$poll->id],
        ))->map(fn (object $row): array => ['text' => (string) $row->word, 'count' => (int) $row->total])->values()->all();
    }
}
