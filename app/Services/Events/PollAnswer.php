<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventPoll;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Turns a submitted answer into what is stored for it, for every question type.
 *
 * The poll's own type decides what a valid answer is -- never something the
 * client sends -- so a phone cannot slip a number into a yes/no question or a
 * list into a rating.
 */
final class PollAnswer
{
    /** The most words one person may add to a word cloud. */
    public const int MAX_WORDS = 3;

    /**
     * @return array{option_id: ?string, response_text: ?string, response_number: ?float, response_payload: ?list<string>}
     *
     * @throws ValidationException
     */
    public function read(Request $request, EventPoll $poll): array
    {
        return app(QuestionAnswerValidator::class)->read($request, $poll->type, $poll->options->pluck('id')->all(), $poll->settings ?? []);
    }

    /**
     * Lower case, trimmed of the punctuation people type around a word, and one
     * of each: "Data!" and "data" are the same word, and one person entering a
     * word three times should not make it three times the size.
     *
     * @param  array<int, mixed>  $words
     * @return list<string>
     */
    public function normaliseWords(array $words): array
    {
        return app(QuestionAnswerValidator::class)->normaliseWords($words);
    }
}
