<?php

declare(strict_types=1);

use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventRegistration;
use App\Models\PollDeck;
use App\Services\Events\DeckPresenter;
use App\Services\Tenancy\TenantHostMatcher;

test('advancing closes the question behind it and opens the next', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('presenter-advance');

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);

    expect($deck->fresh()->current_poll_id)->toBe($first->id)
        ->and($first->fresh()->status)->toBe(EventPoll::STATUS_LIVE);

    $presenter->advance($deck->fresh());

    expect($deck->fresh()->current_poll_id)->toBe($second->id)
        ->and($first->fresh()->status)->toBe(EventPoll::STATUS_CLOSED)
        ->and($second->fresh()->status)->toBe(EventPoll::STATUS_LIVE);
});

test('a vote arriving after the presenter moved on is refused, not recorded', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('presenter-stale');
    $option = EventPollOption::create([
        'tenant_id' => $tenant->id, 'poll_id' => $first->id, 'label' => 'Yes', 'sort_order' => 0,
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'late@example.com',
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now(),
    ]);

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->advance($deck->fresh());

    $host = app(TenantHostMatcher::class)->baseDomain();

    // Someone answering as the slide changed. Their answer belongs to a
    // question the room has left, and counting it would put it on the wrong bar.
    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$first->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($first->responses()->count())->toBe(0);
});

test('ending a deck closes whatever was open', function (): void {
    [$tenant, $event, $deck, $first] = presenterScenario('presenter-end');

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->end($deck->fresh());

    expect($deck->fresh()->status)->toBe(PollDeck::STATUS_ENDED)
        ->and($deck->fresh()->current_poll_id)->toBeNull()
        ->and($first->fresh()->status)->toBe(EventPoll::STATUS_CLOSED);
});

test('advancing past the last question does not wrap around', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('presenter-last');

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->advance($deck->fresh());

    expect($presenter->advance($deck->fresh()))->toBeNull()
        ->and($deck->fresh()->current_poll_id)->toBe($second->id);
});

test('going back lands on the question just behind, not the first one', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('presenter-back');
    $third = deckPoll($tenant, $event, $deck, 'Third', 2);

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->advance($deck->fresh());
    $presenter->advance($deck->fresh());

    expect($deck->fresh()->current_poll_id)->toBe($third->id);

    // polls() already orders by position, so a bare orderBy('position','desc')
    // here is appended and never wins -- back from the third question would
    // jump to the first.
    $presenter->previous($deck->fresh());

    expect($deck->fresh()->current_poll_id)->toBe($second->id);
});
