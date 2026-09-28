<?php

declare(strict_types=1);

use App\Models\EventPoll;
use App\Models\PollDeck;
use App\Services\Events\DeckPresenter;

test('an organiser creates a deck and puts questions in it in order', function (): void {
    [$tenant, $event, $deck, $first, $second, $user, $host] = deckConsole('manage-create');

    $created = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/decks", ['title' => 'Closing plenary'], ['HTTP_HOST' => $host])
        ->assertCreated();

    $newDeckId = $created->json('id');
    expect($created->json('join_code'))->not->toBeNull();

    $this->actingAs($user)
        ->putJson("http://{$host}/events/{$event->id}/decks/{$newDeckId}/polls", [
            'poll_ids' => [$second->id, $first->id],
        ], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('total', 2);

    $listed = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/decks", ['HTTP_HOST' => $host])
        ->assertOk()
        ->json();

    $fresh = collect($listed)->firstWhere('id', $newDeckId);

    // The order given is the order the room will see, not creation order.
    expect(collect($fresh['polls'])->pluck('question')->all())->toBe(['Second', 'First']);
});

test('reordering a deck does not collide on the unique position index', function (): void {
    [$tenant, $event, $deck, $first, $second, $user, $host] = deckConsole('manage-reorder');
    $third = deckPoll($tenant, $event, $deck, 'Third', 2);

    // Swapping two questions writes a position that is still held by the other
    // until the write lands. Detaching first is what keeps this from failing.
    $this->actingAs($user)
        ->putJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/polls", [
            'poll_ids' => [$second->id, $first->id, $third->id],
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($deck->fresh()->polls->pluck('question')->all())->toBe(['Second', 'First', 'Third']);
});

test('removing the question a live deck is pointing at clears the pointer', function (): void {
    [$tenant, $event, $deck, $first, $second, $user, $host] = deckConsole('manage-remove-current');

    app(DeckPresenter::class)->start($deck);
    expect($deck->fresh()->current_poll_id)->toBe($first->id);

    $this->actingAs($user)
        ->putJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/polls", [
            'poll_ids' => [$second->id],
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    // Left pointing at a question no longer in the deck, the wall would publish
    // a poll id that the deck no longer owns.
    expect($deck->fresh()->current_poll_id)->toBeNull();
});

test('a deck will not adopt a question from another event', function (): void {
    [$tenantA, $eventA, $deckA, $firstA, $secondA, $userA, $hostA] = deckConsole('manage-mine');
    [$tenantB, $eventB, $deckB, $firstB] = deckConsole('manage-theirs');

    $this->actingAs($userA)
        ->putJson("http://{$hostA}/events/{$eventA->id}/decks/{$deckA->id}/polls", [
            'poll_ids' => [$firstA->id, $firstB->id],
        ], ['HTTP_HOST' => $hostA])
        ->assertOk();

    // The stranger's question is dropped, not adopted; the deck keeps its own.
    expect($deckA->fresh()->polls->pluck('id')->all())->toBe([$firstA->id])
        ->and($firstB->fresh()->deck_id)->toBe($deckB->id);
});

test('deleting a deck keeps the questions it held', function (): void {
    [$tenant, $event, $deck, $first, $second, $user, $host] = deckConsole('manage-delete');

    $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/decks/{$deck->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect(PollDeck::withoutGlobalScopes()->find($deck->id))->toBeNull()
        ->and(EventPoll::find($first->id))->not->toBeNull()
        ->and($first->fresh()->deck_id)->toBeNull();
});

test('a bystander cannot create or reorder a deck', function (): void {
    [$tenant, $event, $deck, $first, $second, $owner, $host] = deckConsole('manage-perms');

    $bystander = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($bystander->id);
    setPermissionsTeamId($tenant->id);

    $this->actingAs($bystander)
        ->postJson("http://{$host}/events/{$event->id}/decks", ['title' => 'Nope'], ['HTTP_HOST' => $host])
        ->assertForbidden();

    $this->actingAs($bystander)
        ->putJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/polls", [
            'poll_ids' => [$second->id],
        ], ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('an attendee at a virtual event can check themselves in and then vote', function (): void {
    // The whole loop the branch depends on: anonymous voting is closed, so
    // this is the only path from "watching" to "answering" at a virtual event.
    [$tenant, $event, $registration] = virtualEventScenario('loop-closes');

    $deck = PollDeck::create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Plenary',
        'join_code' => PollDeck::generateJoinCode(), 'status' => PollDeck::STATUS_DRAFT,
    ]);
    $question = deckPoll($tenant, $event, $deck, 'Are you with us?', 0);
    $option = App\Models\EventPollOption::create([
        'tenant_id' => $tenant->id, 'poll_id' => $question->id, 'label' => 'Yes', 'sort_order' => 0,
    ]);

    app(DeckPresenter::class)->start($deck);

    $host = app(App\Services\Tenancy\TenantHostMatcher::class)->baseDomain();
    $session = proofFor($registration->email);

    // Registered but not present: the gate turns them away and names why.
    $this->withSession($session)
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$question->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(403);

    $this->withSession($session)
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('checked_in', true);

    $this->withSession($session)
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$question->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($question->responses()->count())->toBe(1);
});
