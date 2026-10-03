<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventPoll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        $optionIds = $poll->options->pluck('id')->all();

        $answer = [
            'option_id' => null,
            'response_text' => null,
            'response_number' => null,
            'response_payload' => null,
        ];

        switch ($poll->type) {
            case EventPoll::TYPE_MULTIPLE_CHOICE:
            case EventPoll::TYPE_QUIZ:
            case EventPoll::TYPE_YES_NO:
            case EventPoll::TYPE_RATING:
                $answer['option_id'] = $this->validate($request, [
                    'option_id' => ['required', 'string', Rule::in($optionIds)],
                ])['option_id'];
                break;

            case EventPoll::TYPE_OPEN:
                $answer['response_text'] = $this->validate($request, [
                    'response_text' => ['required', 'string', 'max:1000'],
                ])['response_text'];
                break;

            case EventPoll::TYPE_SCALE:
                $min = (int) $poll->setting('min', 1);
                $max = (int) $poll->setting('max', 10);
                $answer['response_number'] = (float) $this->validate($request, [
                    'response_number' => ['required', 'integer', "between:{$min},{$max}"],
                ])['response_number'];
                break;

            case EventPoll::TYPE_NUMBER:
                $answer['response_number'] = round((float) $this->validate($request, [
                    'response_number' => ['required', 'numeric', 'between:-1000000000,1000000000'],
                ])['response_number'], 4);
                break;

            case EventPoll::TYPE_MULTI_SELECT:
                $picked = $this->validate($request, [
                    'option_ids' => ['required', 'array', 'min:1'],
                    'option_ids.*' => ['string', 'distinct', Rule::in($optionIds)],
                ])['option_ids'];
                // In the poll's own order, so two people who tick the same
                // boxes in a different order store the same answer.
                $answer['response_payload'] = array_values(array_intersect($optionIds, $picked));
                break;

            case EventPoll::TYPE_RANKING:
                $order = $this->validate($request, [
                    'option_ids' => ['required', 'array', 'size:'.count($optionIds)],
                    'option_ids.*' => ['string', 'distinct', Rule::in($optionIds)],
                ])['option_ids'];
                $answer['response_payload'] = array_values($order);
                break;

            case EventPoll::TYPE_WORD_CLOUD:
                $words = $this->validate($request, [
                    'words' => ['required', 'array', 'min:1', 'max:'.self::MAX_WORDS],
                    'words.*' => ['nullable', 'string', 'max:40'],
                ])['words'];
                $normalised = $this->normaliseWords($words);

                if ($normalised === []) {
                    throw ValidationException::withMessages(['words' => 'Enter at least one word.']);
                }

                $answer['response_payload'] = $normalised;
                // What a moderator reads in the console before approving.
                $answer['response_text'] = implode(', ', $normalised);
                break;

            default:
                throw ValidationException::withMessages(['type' => 'This question cannot be answered.']);
        }

        return $answer;
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
        $clean = [];

        foreach ($words as $word) {
            $word = mb_strtolower(mb_trim((string) $word));
            $word = (string) preg_replace('/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $word);
            $word = (string) preg_replace('/\s+/u', ' ', $word);

            if ($word !== '') {
                $clean[$word] = true;
            }
        }

        return array_slice(array_keys($clean), 0, self::MAX_WORDS);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(Request $request, array $rules): array
    {
        return Validator::make($request->all(), $rules)->validate();
    }
}
