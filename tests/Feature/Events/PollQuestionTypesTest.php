<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollResponse;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PollAnswer;
use App\Services\Events\PollResults;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Str;

/**
 * A live poll of the given type, created the way the console creates one, on a
 * running event with an organiser who can create it.
 *
 * @param  array<string, mixed>  $extra
 * @return array{0: Tenant, 1: Event, 2: EventPoll}
 */
function typedPoll(string $slug, string $type, array $extra = []): array
{
    [$tenant, $event, , , , $owner, $host] = deckConsole($slug);

    $id = test()->actingAs($owner)
        ->postJson("http://{$host}/events/{$event->id}/polls", [
            'question' => "A {$type} question",
            'type' => $type,
            ...$extra,
        ], ['HTTP_HOST' => $host])
        ->assertOk()
        ->json('id');

    $poll = EventPoll::query()->findOrFail($id);
    $poll->update(['status' => EventPoll::STATUS_LIVE, 'went_live_at' => now()]);

    return [$tenant, $event, $poll->fresh('options')];
}

/**
 * A confirmed, checked-in delegate who may vote.
 */
function presentVoter(Tenant $tenant, Event $event): EventRegistration
{
    return EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'voter-'.Str::random(8).'@example.com',
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $answer
 */
function castVote(EventRegistration $voter, EventPoll $poll, array $answer): Illuminate\Testing\TestResponse
{
    $host = app(TenantHostMatcher::class)->baseDomain();

    return test()->withSession(proofFor($voter->email))
        ->postJson("http://{$host}/my/events/{$voter->id}/poll/{$poll->id}/respond", $answer, ['HTTP_HOST' => $host]);
}

/**
 * Writes answers straight to the table, for the aggregation tests that need a
 * room's worth of them.
 *
 * @param  list<array<string, mixed>>  $answers
 */
function seedAnswers(EventPoll $poll, array $answers): void
{
    foreach ($answers as $i => $answer) {
        EventPollResponse::query()->create([
            'tenant_id' => $poll->tenant_id,
            'poll_id' => $poll->id,
            'respondent_token' => "seeded-{$i}",
            'is_approved' => true,
            ...$answer,
        ]);
    }
}

// --- Creating -----------------------------------------------------------------

test('yes/no and rating questions get their options from the type, not the organiser', function (): void {
    [, , $yesNo] = typedPoll('qt-create-yn', EventPoll::TYPE_YES_NO);
    [, , $rating] = typedPoll('qt-create-rating', EventPoll::TYPE_RATING);

    expect($yesNo->options->pluck('label')->all())->toBe(['Yes', 'No'])
        ->and($rating->options->pluck('label')->all())->toBe(['1', '2', '3', '4', '5']);
});

test('a scale needs two ends, and the top must be above the bottom', function (): void {
    [, $event, , , , $owner, $host] = deckConsole('qt-create-scale');
    $url = "http://{$host}/events/{$event->id}/polls";

    $this->actingAs($owner)->postJson($url, ['question' => 'How ready?', 'type' => 'scale'], ['HTTP_HOST' => $host])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.min', 'settings.max']);

    $this->actingAs($owner)->postJson($url, [
        'question' => 'How ready?', 'type' => 'scale', 'settings' => ['min' => 5, 'max' => 5],
    ], ['HTTP_HOST' => $host])->assertJsonValidationErrors('settings.max');

    $created = $this->actingAs($owner)->postJson($url, [
        'question' => 'How ready?', 'type' => 'scale',
        'settings' => ['min' => 1, 'max' => 10, 'label_min' => 'Not at all', 'label_max' => 'Completely', 'unit' => 'stray'],
    ], ['HTTP_HOST' => $host])->assertOk();

    // A setting the type does not use is dropped rather than stored. Compared
    // without regard to order: Postgres jsonb stores keys in its own order.
    expect(EventPoll::query()->find($created->json('id'))->settings)
        ->toEqualCanonicalizing(['min' => 1, 'max' => 10, 'label_min' => 'Not at all', 'label_max' => 'Completely'])
        ->not->toHaveKey('unit');
});

test('multi-select and ranking need options written by the organiser', function (): void {
    [, $event, , , , $owner, $host] = deckConsole('qt-create-authored');

    foreach ([EventPoll::TYPE_MULTI_SELECT, EventPoll::TYPE_RANKING] as $type) {
        $this->actingAs($owner)
            ->postJson("http://{$host}/events/{$event->id}/polls", ['question' => 'Which?', 'type' => $type], ['HTTP_HOST' => $host])
            ->assertJsonValidationErrors('options');
    }
});

// --- Answering ------------------------------------------------------------------

test('a scale answer must sit between the ends the organiser set', function (): void {
    [$tenant, $event, $poll] = typedPoll('qt-vote-scale', EventPoll::TYPE_SCALE, ['settings' => ['min' => 1, 'max' => 5]]);
    $voter = presentVoter($tenant, $event);

    castVote($voter, $poll, ['response_number' => 6])->assertJsonValidationErrors('response_number');
    castVote($voter, $poll, ['response_number' => 4])->assertOk();

    expect($poll->responses()->first()->response_number)->toBe(4.0);
});

test('a number answer must be a number', function (): void {
    [$tenant, $event, $poll] = typedPoll('qt-vote-number', EventPoll::TYPE_NUMBER);
    $voter = presentVoter($tenant, $event);

    castVote($voter, $poll, ['response_number' => 'twelve'])->assertJsonValidationErrors('response_number');
    castVote($voter, $poll, ['response_number' => 12.5])->assertOk();

    expect($poll->responses()->first()->response_number)->toBe(12.5);
});

test('multi-select keeps only real options, stored in the poll\'s own order', function (): void {
    [$tenant, $event, $poll] = typedPoll('qt-vote-multi', EventPoll::TYPE_MULTI_SELECT, ['options' => ['Clinical', 'Policy', 'Data']]);
    [$clinical, $policy, $data] = $poll->options->pluck('id')->all();
    $voter = presentVoter($tenant, $event);

    castVote($voter, $poll, ['option_ids' => [$data, Str::uuid()->toString()]])->assertJsonValidationErrors('option_ids.1');
    castVote($voter, $poll, ['option_ids' => []])->assertJsonValidationErrors('option_ids');

    // Ticked in a different order from the poll's.
    castVote($voter, $poll, ['option_ids' => [$data, $clinical]])->assertOk();

    expect($poll->responses()->first()->response_payload)->toBe([$clinical, $data]);
    unset($policy);
});

test('a ranking must place every option exactly once', function (): void {
    [$tenant, $event, $poll] = typedPoll('qt-vote-rank', EventPoll::TYPE_RANKING, ['options' => ['A', 'B', 'C']]);
    [$a, $b, $c] = $poll->options->pluck('id')->all();
    $voter = presentVoter($tenant, $event);

    castVote($voter, $poll, ['option_ids' => [$a, $b]])->assertJsonValidationErrors('option_ids');
    castVote($voter, $poll, ['option_ids' => [$a, $a, $b]])->assertJsonValidationErrors('option_ids.1');
    castVote($voter, $poll, ['option_ids' => [$c, $a, $b]])->assertOk();

    expect($poll->responses()->first()->response_payload)->toBe([$c, $a, $b]);
});

test('word cloud answers are normalised, so one word is one word', function (): void {
    expect(app(PollAnswer::class)->normaliseWords(['Data!', ' data ', '  Health   care.', '', '"AI"']))
        ->toBe(['data', 'health care', 'ai']);
});

test('a word cloud refuses an answer with no words, and caps how many one person adds', function (): void {
    [$tenant, $event, $poll] = typedPoll('qt-vote-words', EventPoll::TYPE_WORD_CLOUD);
    $voter = presentVoter($tenant, $event);

    castVote($voter, $poll, ['words' => ['  ', '!!']])->assertJsonValidationErrors('words');
    castVote($voter, $poll, ['words' => ['a', 'b', 'c', 'd']])->assertJsonValidationErrors('words');
    castVote($voter, $poll, ['words' => ['Interoperability', 'trust']])->assertOk();

    expect($poll->responses()->first()->response_payload)->toBe(['interoperability', 'trust']);
});

test('a moderated word cloud keeps an answer off the wall until it is approved', function (): void {
    [$tenant, $event, $poll] = typedPoll('qt-vote-words-mod', EventPoll::TYPE_WORD_CLOUD, ['requires_moderation' => true]);
    castVote(presentVoter($tenant, $event), $poll, ['words' => ['unvetted']])->assertOk();

    expect($poll->responses()->first()->is_approved)->toBeNull()
        ->and(app(PollResults::class)->forDisplay($poll->fresh())['summary']['words'])->toBe([]);
});

// --- Results --------------------------------------------------------------------

test('multi-select reports each option as a share of people, not of ticks', function (): void {
    [, , $poll] = typedPoll('qt-res-multi', EventPoll::TYPE_MULTI_SELECT, ['options' => ['A', 'B', 'C']]);
    [$a, $b, $c] = $poll->options->pluck('id')->all();

    // Two people, both ticking A and B: each is 100%, not 50%.
    seedAnswers($poll, [['response_payload' => [$a, $b]], ['response_payload' => [$a, $b]]]);

    $results = app(PollResults::class)->forDisplay($poll->fresh());
    $byLabel = collect($results['options'])->keyBy('label');

    expect($results['total_responses'])->toBe(2)
        ->and($results['summary']['respondents'])->toBe(2)
        ->and($byLabel['A']['percentage'])->toBe(100)
        ->and($byLabel['B']['percentage'])->toBe(100)
        ->and($byLabel['C']['percentage'])->toBe(0);
    unset($c);
});

test('ranking orders options by average position and says how many ranked them', function (): void {
    [, , $poll] = typedPoll('qt-res-rank', EventPoll::TYPE_RANKING, ['options' => ['A', 'B', 'C']]);
    [$a, $b, $c] = $poll->options->pluck('id')->all();

    seedAnswers($poll, [
        ['response_payload' => [$c, $a, $b]],
        ['response_payload' => [$c, $b, $a]],
        ['response_payload' => [$a, $c, $b]],
    ]);

    $results = app(PollResults::class)->forDisplay($poll->fresh());

    expect(collect($results['options'])->pluck('label')->all())->toBe(['C', 'A', 'B'])
        ->and($results['options'][0]['average_position'])->toBe(1.33)
        ->and($results['summary']['respondents'])->toBe(3);
});

test('rating, scale and number withhold their detail below five respondents', function (): void {
    [, , $rating] = typedPoll('qt-res-supp-rating', EventPoll::TYPE_RATING);
    [, , $scale] = typedPoll('qt-res-supp-scale', EventPoll::TYPE_SCALE, ['settings' => ['min' => 1, 'max' => 5]]);
    [, , $number] = typedPoll('qt-res-supp-number', EventPoll::TYPE_NUMBER);

    $star = $rating->options->firstWhere('label', '4')->id;
    seedAnswers($rating, array_fill(0, 4, ['option_id' => $star]));
    seedAnswers($scale, array_fill(0, 4, ['response_number' => 3]));
    seedAnswers($number, [['response_number' => 30000], ['response_number' => 42000], ['response_number' => 51000], ['response_number' => 60000]]);

    foreach ([$rating, $scale, $number] as $poll) {
        $results = app(PollResults::class)->forDisplay($poll->fresh());

        // Not merely hidden: a page anyone can open receives none of it.
        expect($results['suppressed'])->toBeTrue()
            ->and($results['total_responses'])->toBe(4)
            ->and($results['summary'])->toBeNull()
            ->and(json_encode($results))->not->toContain('42000');
    }

    // The rating's five stars must be present and carry no counts. Asserting
    // only "no option has a count" passed when the options failed to load at
    // all, which proved nothing.
    $stars = app(PollResults::class)->forDisplay($rating->fresh())['options'];

    expect($stars)->toHaveCount(5)
        ->and(array_column($stars, 'count'))->toBe([null, null, null, null, null])
        ->and(array_column($stars, 'percentage'))->toBe([null, null, null, null, null]);
});

test('at five respondents a rating shows its average and its spread', function (): void {
    [, , $poll] = typedPoll('qt-res-rating', EventPoll::TYPE_RATING);
    $ids = $poll->options->pluck('id', 'label');

    seedAnswers($poll, array_map(fn (string $star): array => ['option_id' => $ids[$star]], ['5', '5', '4', '4', '2']));

    $results = app(PollResults::class)->forDisplay($poll->fresh());

    expect($results['suppressed'])->toBeFalse()
        ->and($results['summary'])->toBe(['average' => 4.0, 'out_of' => 5])
        ->and(collect($results['options'])->firstWhere('label', '5')['count'])->toBe(2);
});

test('a scale shows every point on the line, including the empty ones, and the average', function (): void {
    [, , $poll] = typedPoll('qt-res-scale', EventPoll::TYPE_SCALE, ['settings' => ['min' => 1, 'max' => 5, 'label_min' => 'Low', 'label_max' => 'High']]);

    seedAnswers($poll, array_map(fn (int $v): array => ['response_number' => $v], [1, 2, 2, 5, 5]));

    $results = app(PollResults::class)->forDisplay($poll->fresh());

    expect($results['summary']['average'])->toBe(3.0)
        ->and(collect($results['summary']['distribution'])->pluck('count', 'value')->all())->toBe([1 => 1, 2 => 2, 3 => 0, 4 => 0, 5 => 2])
        ->and($results['settings'])->toBe(['min' => 1, 'max' => 5, 'label_min' => 'Low', 'label_max' => 'High']);
});

test('a number question reports average, median, range and a histogram', function (): void {
    [, , $poll] = typedPoll('qt-res-number', EventPoll::TYPE_NUMBER, ['settings' => ['unit' => 'beds']]);

    seedAnswers($poll, array_map(fn (int $v): array => ['response_number' => $v], [10, 20, 30, 40, 100]));

    $summary = app(PollResults::class)->forDisplay($poll->fresh())['summary'];

    expect($summary['average'])->toBe(40.0)
        ->and($summary['median'])->toBe(30.0)
        ->and($summary['minimum'])->toBe(10.0)
        ->and($summary['maximum'])->toBe(100.0)
        ->and($summary['histogram'])->toHaveCount(6)
        // Every answer lands in a bucket, including the largest.
        ->and(array_sum(array_column($summary['histogram'], 'count')))->toBe(5);
});

test('a word cloud counts each word across everyone, most used first', function (): void {
    [, , $poll] = typedPoll('qt-res-words', EventPoll::TYPE_WORD_CLOUD);

    seedAnswers($poll, [
        ['response_payload' => ['data', 'trust']],
        ['response_payload' => ['data']],
        ['response_payload' => ['access', 'data']],
    ]);

    expect(app(PollResults::class)->forDisplay($poll->fresh())['summary']['words'])
        ->toBe([['text' => 'data', 'count' => 3], ['text' => 'access', 'count' => 1], ['text' => 'trust', 'count' => 1]]);
});

test('no new type puts a respondent token on the wall', function (string $type, array $extra): void {
    [$tenant, $event, $poll] = typedPoll('qt-priv-'.Str::slug($type), $type, $extra);
    $poll->load('options');
    $first = $poll->options->first()?->id;

    $answer = match ($type) {
        EventPoll::TYPE_SCALE, EventPoll::TYPE_NUMBER => ['response_number' => 3],
        EventPoll::TYPE_MULTI_SELECT => ['option_ids' => [$first]],
        EventPoll::TYPE_RANKING => ['option_ids' => $poll->options->pluck('id')->all()],
        EventPoll::TYPE_WORD_CLOUD => ['words' => ['open']],
        default => ['option_id' => $first],
    };

    for ($i = 0; $i < 5; $i++) {
        castVote(presentVoter($tenant, $event), $poll, $answer)->assertOk();
    }

    $payload = json_encode(app(PollResults::class)->forDisplay($poll->fresh()));
    $tokens = $poll->responses()->pluck('respondent_token');

    expect($payload)->not->toContain('respondent_token');
    foreach ($tokens as $token) {
        expect($payload)->not->toContain($token);
    }
})->with([
    'yes/no' => [EventPoll::TYPE_YES_NO, []],
    'rating' => [EventPoll::TYPE_RATING, []],
    'scale' => [EventPoll::TYPE_SCALE, ['settings' => ['min' => 1, 'max' => 5]]],
    'number' => [EventPoll::TYPE_NUMBER, []],
    'multi-select' => [EventPoll::TYPE_MULTI_SELECT, ['options' => ['A', 'B']]],
    'word cloud' => [EventPoll::TYPE_WORD_CLOUD, []],
    'ranking' => [EventPoll::TYPE_RANKING, ['options' => ['A', 'B']]],
]);

test("a phone's raw poll data withholds a rating's counts below five respondents too", function (): void {
    [$tenant, $event, $poll] = typedPoll('qt-portal-supp', EventPoll::TYPE_RATING);
    $voter = presentVoter($tenant, $event);
    castVote($voter, $poll, ['option_id' => $poll->options->first()->id])->assertOk();

    $host = app(TenantHostMatcher::class)->baseDomain();
    $options = $this->withSession(proofFor($voter->email))
        ->getJson("http://{$host}/my/events/{$voter->id}/poll", ['HTTP_HOST' => $host])
        ->assertOk()
        ->json('poll.options');

    expect(collect($options)->pluck('votes_count')->filter(fn ($c): bool => $c !== null)->all())->toBe([]);
});
