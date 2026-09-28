<?php

declare(strict_types=1);

use App\Events\PollResultsUpdated;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PollBroadcastCoalescer;
use App\Services\Events\PollResults;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;

function scaleScenario(string $slug, string $type = EventPoll::TYPE_MULTIPLE_CHOICE): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'Scale',
        'type' => $type,
        'status' => EventPoll::STATUS_LIVE,
        'went_live_at' => now(),
    ]);
    $option = EventPollOption::create([
        'tenant_id' => $tenant->id, 'poll_id' => $poll->id, 'label' => 'One', 'sort_order' => 0,
    ]);

    return [$tenant, $event, $poll, $option];
}

test('a hundred votes do not cause a hundred broadcasts', function (): void {
    $this->freezeTime();
    EventFacade::fake([PollResultsUpdated::class]);

    [$tenant, $event, $poll, $option] = scaleScenario('scale-coalesce');
    $coalescer = app(PollBroadcastCoalescer::class);

    for ($i = 0; $i < 100; $i++) {
        $coalescer->schedule($poll);
    }

    // The number is the point: a wall updating once a second reads as live to a
    // room, and costs a hundredth of what updating per vote costs.
    EventFacade::assertDispatchedTimes(PollResultsUpdated::class, 1);
});

test('the first vote in a window broadcasts and the rest report that one is already out', function (): void {
    $this->freezeTime();
    EventFacade::fake([PollResultsUpdated::class]);

    [$tenant, $event, $poll] = scaleScenario('scale-window');
    $coalescer = app(PollBroadcastCoalescer::class);

    expect($coalescer->schedule($poll))->toBeTrue()
        ->and($coalescer->schedule($poll))->toBeFalse();
});

test('two polls answered at once do not silence each other', function (): void {
    $this->freezeTime();
    EventFacade::fake([PollResultsUpdated::class]);

    [$tenantA, $eventA, $pollA] = scaleScenario('scale-two-a');
    [$tenantB, $eventB, $pollB] = scaleScenario('scale-two-b');
    $coalescer = app(PollBroadcastCoalescer::class);

    // The window is per poll. Two rooms voting in the same second must both
    // see their own wall move.
    expect($coalescer->schedule($pollA))->toBeTrue()
        ->and($coalescer->schedule($pollB))->toBeTrue();

    // Both of the above pass against an unconditional dispatch. These are what
    // say the window is real AND that it is keyed per poll: A is now suppressed
    // while B, opened in the same second, still went out.
    expect($coalescer->schedule($pollA))->toBeFalse()
        ->and($coalescer->schedule($pollB))->toBeFalse();

    EventFacade::assertDispatchedTimes(PollResultsUpdated::class, 2);
});

test('counting a thousand answers does not load a thousand rows', function (): void {
    [$tenant, $event, $poll, $option] = scaleScenario('scale-counting');

    $rows = [];
    foreach (range(1, 1000) as $i) {
        $rows[] = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'poll_id' => $poll->id,
            'option_id' => $option->id,
            'respondent_token' => "voter-{$i}",
            'is_approved' => true,
            'points_awarded' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    EventPollResponse::on('landlord')->insert($rows);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $fresh = $poll->fresh();
    $results = app(PollResults::class)->forDisplay($fresh);

    expect($results['total_responses'])->toBe(1000)
        ->and($results['options'][0]['count'])->toBe(1000)
        ->and($results['options'][0]['percentage'])->toBe(100)
        ->and($queries)->toBeLessThan(6);

    // The query count alone would not have caught the old implementation --
    // loading every response was two queries, same as counting in SQL. What
    // changed is that a thousand rows are no longer hydrated into models on
    // every render, and this is the assertion that says so.
    expect($fresh->relationLoaded('responses'))->toBeFalse();
});

test('a moderated answer is not counted until it is approved', function (): void {
    [$tenant, $event, $poll, $option] = scaleScenario('scale-moderation');

    EventPollResponse::create([
        'tenant_id' => $tenant->id, 'poll_id' => $poll->id, 'option_id' => $option->id,
        'respondent_token' => 'approved-one', 'is_approved' => true,
    ]);
    // Pending, not rejected. Counting "not rejected" would put an unread answer
    // on the wall, which is the one thing moderation exists to prevent.
    EventPollResponse::create([
        'tenant_id' => $tenant->id, 'poll_id' => $poll->id, 'option_id' => $option->id,
        'respondent_token' => 'pending-one', 'is_approved' => null,
    ]);
    EventPollResponse::create([
        'tenant_id' => $tenant->id, 'poll_id' => $poll->id, 'option_id' => $option->id,
        'respondent_token' => 'rejected-one', 'is_approved' => false,
    ]);

    $results = app(PollResults::class)->forDisplay($poll->fresh());

    expect($results['total_responses'])->toBe(1)
        ->and($results['options'][0]['count'])->toBe(1);
});

test('an open poll counts its replies and shows only the last thirty', function (): void {
    [$tenant, $event, $poll] = scaleScenario('scale-open', EventPoll::TYPE_OPEN);

    foreach (range(1, 40) as $i) {
        $reply = EventPollResponse::create([
            'tenant_id' => $tenant->id,
            'poll_id' => $poll->id,
            'response_text' => "Answer {$i}",
            'respondent_token' => "open-{$i}",
            'is_approved' => true,
        ]);
        // Spread the timestamps so "the last thirty" is a real ordering rather
        // than whatever order the rows happen to come back in.
        $reply->forceFill(['created_at' => now()->addSeconds($i)])->save();
    }
    // Neither an empty string nor three spaces is an answer. Both must stay off
    // the wall and out of the thirty slots someone else's answer wants.
    EventPollResponse::create([
        'tenant_id' => $tenant->id, 'poll_id' => $poll->id, 'response_text' => '',
        'respondent_token' => 'open-blank', 'is_approved' => true,
    ]);
    EventPollResponse::create([
        'tenant_id' => $tenant->id, 'poll_id' => $poll->id, 'response_text' => '   ',
        'respondent_token' => 'open-spaces', 'is_approved' => true,
    ]);

    $results = app(PollResults::class)->forDisplay($poll->fresh());

    // Every reply counts toward the total even though an open answer carries no
    // option_id and so falls into the null group.
    expect($results['total_responses'])->toBe(42)
        ->and($results['open_responses'])->toHaveCount(30)
        ->and($results['open_responses'][0]['text'])->toBe('Answer 40')
        ->and(collect($results['open_responses'])->pluck('text'))
        ->each(fn ($text) => $text->not->toBe('')->not->toBe('   '));
});

test('the wall does not read every answer to draw the bars', function (): void {
    [$tenant, $event, $poll, $option] = scaleScenario('scale-wall');
    $event->update(['present_token' => Str::random(40)]);

    $rows = [];
    foreach (range(1, 200) as $i) {
        $rows[] = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'poll_id' => $poll->id,
            'option_id' => $option->id,
            'respondent_token' => "wall-{$i}",
            'is_approved' => true,
            'points_awarded' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    EventPollResponse::on('landlord')->insert($rows);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $host = 'scale-wall.'.mb_ltrim((string) config('session.domain'), '.');
    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['poll']['total_responses'])->toBe(200);

    // The wall's own page load, and the poll it falls back to without a socket,
    // used to eager load every response alongside the poll. Counting moved to
    // SQL but this read did not, so the cost stayed on the busiest path.
    $bulkReads = collect($statements)
        ->filter(fn (string $sql): bool => str_contains($sql, 'event_poll_responses')
            && ! str_contains($sql, 'count(*)'))
        ->all();

    expect($bulkReads)->toBe([]);
});

test('a phone and the wall agree on the count while answers await a moderator', function (): void {
    [$tenant, $event, $poll, $option] = scaleScenario('scale-agree', EventPoll::TYPE_OPEN);
    $poll->update(['requires_moderation' => true]);

    foreach (['yes-one', 'yes-two'] as $token) {
        EventPollResponse::create([
            'tenant_id' => $tenant->id, 'poll_id' => $poll->id,
            'response_text' => 'Approved', 'respondent_token' => $token, 'is_approved' => true,
        ]);
    }
    EventPollResponse::create([
        'tenant_id' => $tenant->id, 'poll_id' => $poll->id,
        'response_text' => 'Waiting', 'respondent_token' => 'pending-one', 'is_approved' => null,
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'agree@example.com',
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now(),
    ]);

    $host = app(TenantHostMatcher::class)->baseDomain();
    $phone = $this->withSession(proofFor($registration->email))
        ->getJson("http://{$host}/my/events/{$registration->id}/poll", ['HTTP_HOST' => $host])
        ->assertOk();

    $wall = app(PollResults::class)->forDisplay($poll->fresh());

    // The phone counted every row and the wall counted only approved ones, so
    // the same question showed two different totals depending where you looked.
    expect($phone->json('poll.total_votes'))->toBe(2)
        ->and($wall['total_responses'])->toBe(2);
});
