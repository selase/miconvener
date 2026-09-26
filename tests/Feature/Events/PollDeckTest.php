<?php

declare(strict_types=1);

use App\Models\EventPoll;
use App\Models\EventSession;
use App\Models\PollDeck;
use App\Scopes\TenantScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

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

test('isCurrent is safe to call on a poll drawn from a multi-row query', function (): void {
    [$tenant, $event] = deckScenario('deck-strict');

    $deck = PollDeck::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Strict mode',
        'join_code' => PollDeck::generateJoinCode(),
        'status' => PollDeck::STATUS_DRAFT,
    ]);

    $first = deckPoll($tenant, $event, $deck, 'First question', 0);
    $second = deckPoll($tenant, $event, $deck, 'Second question', 1);

    $deck->update(['current_poll_id' => $first->id]);

    // Pins isCurrent() against the actual consumption pattern (a poll drawn
    // from a multi-row collection, as `foreach ($deck->polls as $poll)`
    // does) rather than only the single-row `fresh()` calls above. This
    // repo also has `essentials.AutomaticallyEagerLoadRelationships`
    // enabled, which silently autoloads an unloaded relation across the
    // whole collection instead of throwing -- so it does not currently
    // reproduce a `LazyLoadingViolationException` even without the fix
    // below. isCurrent() still avoids the lazy load explicitly rather than
    // depending on that app-wide toggle staying on.
    $polls = $deck->polls()->get();

    expect($polls)->toHaveCount(2);

    expect($polls->firstWhere('id', $first->id)->isCurrent())->toBeTrue()
        ->and($polls->firstWhere('id', $second->id)->isCurrent())->toBeFalse();
});

test('a join code avoids the characters people misread aloud', function (): void {
    foreach (range(1, 50) as $ignored) {
        $code = PollDeck::generateJoinCode();

        expect($code)
            ->not->toContain('O')->not->toContain('0')
            ->not->toContain('I')->not->toContain('1')
            ->and(mb_strlen($code))->toBe(6);
    }
});

test('a join code is checked for collisions across every tenant, not just the active one', function (): void {
    [$tenantA, $eventA] = deckScenario('deck-cross-a');
    [$tenantB, $eventB] = deckScenario('deck-cross-b');

    app(TenantContext::class)->setTenant($tenantA);

    PollDeck::create([
        'tenant_id' => $tenantA->id,
        'event_id' => $eventA->id,
        'title' => 'Tenant A deck',
        'join_code' => 'ABCDEF',
        'status' => PollDeck::STATUS_DRAFT,
    ]);

    app(TenantContext::class)->setTenant($tenantB);

    // The plain, tenant-scoped query cannot see tenant A's code -- if
    // generateJoinCode() checked collisions this way, a cross-tenant
    // collision would slip through the check and fail at insert instead,
    // against a unique index that is global.
    expect(PollDeck::where('join_code', 'ABCDEF')->exists())->toBeFalse();

    // The unscoped query generateJoinCode() actually runs sees it.
    expect(PollDeck::withoutGlobalScope(TenantScope::class)->where('join_code', 'ABCDEF')->exists())
        ->toBeTrue();
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
        ->and($poll->fresh()->position)->toBe(0)
        ->and($poll->isCurrent())->toBeTrue();
});

test('a deck relates to its event and its optional session', function (): void {
    [$tenant, $event] = deckScenario('deck-relations');

    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
    ]);

    $deck = PollDeck::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'title' => 'Breakout A',
        'join_code' => PollDeck::generateJoinCode(),
        'status' => PollDeck::STATUS_DRAFT,
    ]);

    expect($deck->event->is($event))->toBeTrue()
        ->and($deck->session->is($session))->toBeTrue();
});

test('two polls in the same deck cannot share a position', function (): void {
    [$tenant, $event] = deckScenario('deck-tie');

    $deck = PollDeck::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Tie break',
        'join_code' => PollDeck::generateJoinCode(),
        'status' => PollDeck::STATUS_DRAFT,
    ]);

    deckPoll($tenant, $event, $deck, 'First question', 0);

    expect(fn () => deckPoll($tenant, $event, $deck, 'Also position zero', 0))
        ->toThrow(QueryException::class);
});

test('eager loading the current question gives each deck its own, not the first one', function (): void {
    // A constraint built from $this->getKey() inside the relation looks like a
    // safety net and silently breaks this: Laravel builds it once and applies
    // one deck's id to every row. Task 5's presenter eager loads exactly here.
    [$tenantA, $eventA] = deckScenario('eager-a');
    $deckA = PollDeck::create([
        'tenant_id' => $tenantA->id, 'event_id' => $eventA->id, 'title' => 'A',
        'join_code' => PollDeck::generateJoinCode(), 'status' => PollDeck::STATUS_DRAFT,
    ]);
    $pollA = deckPoll($tenantA, $eventA, $deckA, 'Question A', 0);
    $deckA->setCurrentPoll($pollA);

    [$tenantB, $eventB] = deckScenario('eager-b');
    $deckB = PollDeck::create([
        'tenant_id' => $tenantB->id, 'event_id' => $eventB->id, 'title' => 'B',
        'join_code' => PollDeck::generateJoinCode(), 'status' => PollDeck::STATUS_DRAFT,
    ]);
    $pollB = deckPoll($tenantB, $eventB, $deckB, 'Question B', 0);
    $deckB->setCurrentPoll($pollB);

    $decks = PollDeck::withoutGlobalScopes()
        ->with('currentPoll')
        ->whereIn('id', [$deckA->id, $deckB->id])
        ->get()
        ->keyBy('id');

    expect($decks[$deckA->id]->currentPoll?->id)->toBe($pollA->id)
        ->and($decks[$deckB->id]->currentPoll?->id)->toBe($pollB->id);
});

test('a deck refuses to point at a question that is not its own', function (): void {
    [$tenant, $event] = deckScenario('pointer-guard');
    $mine = PollDeck::create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Mine',
        'join_code' => PollDeck::generateJoinCode(), 'status' => PollDeck::STATUS_DRAFT,
    ]);
    $theirs = PollDeck::create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Theirs',
        'join_code' => PollDeck::generateJoinCode(), 'status' => PollDeck::STATUS_DRAFT,
    ]);
    $strangersQuestion = deckPoll($tenant, $event, $theirs, 'Not yours', 0);

    expect(fn () => $mine->setCurrentPoll($strangersQuestion))
        ->toThrow(InvalidArgumentException::class);

    expect($mine->fresh()->current_poll_id)->toBeNull();
});

test('asking every poll in a deck whether it is current does not lazy load per row', function (): void {
    [$tenant, $event] = deckScenario('no-lazy-load');
    $deck = PollDeck::create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Plenary',
        'join_code' => PollDeck::generateJoinCode(), 'status' => PollDeck::STATUS_DRAFT,
    ]);
    $first = deckPoll($tenant, $event, $deck, 'First', 0);
    deckPoll($tenant, $event, $deck, 'Second', 1);
    deckPoll($tenant, $event, $deck, 'Third', 2);
    $deck->setCurrentPoll($first);

    // Model::shouldBeStrict() is on, so a lazy load inside this loop would
    // throw rather than merely be slow.
    $flags = $deck->fresh()->polls->map(fn (EventPoll $poll): bool => $poll->isCurrent())->all();

    expect($flags)->toBe([true, false, false]);
});
