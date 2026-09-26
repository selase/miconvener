<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventPoll;
use App\Models\PollDeck;
use App\Models\Tenant;

function deckScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $event];
}

function deckPoll(Tenant $tenant, Event $event, PollDeck $deck, string $question, int $position): EventPoll
{
    return EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'deck_id' => $deck->id,
        'position' => $position,
        'question' => $question,
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_DRAFT,
    ]);
}

test('a deck orders its questions and knows where the presenter is standing', function (): void {
    [$tenant, $event] = deckScenario('deck-order');

    $deck = PollDeck::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Opening plenary',
        'join_code' => PollDeck::generateJoinCode(),
        'status' => PollDeck::STATUS_DRAFT,
    ]);

    $second = deckPoll($tenant, $event, $deck, 'Second question', 1);
    $first = deckPoll($tenant, $event, $deck, 'First question', 0);

    expect($deck->polls()->pluck('question')->all())
        ->toBe(['First question', 'Second question']);

    $deck->update(['current_poll_id' => $first->id]);

    expect($first->fresh()->isCurrent())->toBeTrue()
        ->and($second->fresh()->isCurrent())->toBeFalse();
});

test('a join code avoids the characters people misread aloud', function (): void {
    foreach (range(1, 50) as $ignored) {
        expect(PollDeck::generateJoinCode())
            ->not->toContain('O')->not->toContain('0')
            ->not->toContain('I')->not->toContain('1');
    }
});

test('a poll with no deck behaves exactly as it did', function (): void {
    [$tenant, $event] = deckScenario('deck-none');

    $poll = EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'Standalone',
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_LIVE,
        'went_live_at' => now(),
    ]);

    expect($poll->deck_id)->toBeNull()
        ->and($poll->isCurrent())->toBeTrue();
});
