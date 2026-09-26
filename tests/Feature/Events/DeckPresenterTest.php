<?php

declare(strict_types=1);

use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventRegistration;
use App\Models\PollDeck;
use App\Models\User;
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
        ->assertStatus(422)
        ->assertJsonPath('message', 'That question has closed.');

    expect($first->responses()->count())->toBe(0);
});

test('a question still marked live but no longer the deck\'s is refused too', function (): void {
    // The test above passes on status alone, because advance() closes what it
    // leaves. This one isolates the pointer: the question stays LIVE and only
    // stops being current, which is the condition isCurrent() exists to catch.
    [$tenant, $event, $deck, $first, $second] = presenterScenario('presenter-pointer');
    $option = EventPollOption::create([
        'tenant_id' => $tenant->id, 'poll_id' => $first->id, 'label' => 'Yes', 'sort_order' => 0,
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'pointer@example.com',
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now(),
    ]);

    app(DeckPresenter::class)->start($deck);
    $deck->fresh()->setCurrentPoll($second);
    $first->update(['status' => EventPoll::STATUS_LIVE]);

    expect($first->fresh()->status)->toBe(EventPoll::STATUS_LIVE)
        ->and($first->fresh()->isCurrent())->toBeFalse();

    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$first->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That question has closed.');

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

/**
 * The service is well covered above; these exercise the HTTP surface, which
 * nothing else touches -- routes, authorization, tenancy and payload shape.
 */
function deckConsole(string $slug): array
{
    [$tenant, $event, $deck, $first, $second] = presenterScenario($slug);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    makeTenantOwner($user, $tenant);
    $tenant->users()->attach($user->id);
    $host = "{$slug}.".mb_ltrim((string) config('session.domain'), '.');

    return [$tenant, $event, $deck, $first, $second, $user, $host];
}

test('the console drives the deck and reports where the presenter is standing', function (): void {
    [$tenant, $event, $deck, $first, $second, $user, $host] = deckConsole('console-drive');

    $start = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/start", [], ['HTTP_HOST' => $host]);

    $start->assertOk()
        ->assertJsonPath('status', PollDeck::STATUS_LIVE)
        ->assertJsonPath('current_poll_id', $first->id)
        ->assertJsonPath('position', 0)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('join_code', $deck->join_code);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/advance", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('current_poll_id', $second->id)
        ->assertJsonPath('position', 1);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/previous", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('current_poll_id', $first->id);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/close", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($first->fresh()->status)->toBe(EventPoll::STATUS_CLOSED);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/end", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('status', PollDeck::STATUS_ENDED)
        ->assertJsonPath('current_poll_id', null);
});

test('the deck endpoints refuse a signed-out caller', function (): void {
    [$tenant, $event, $deck] = deckConsole('console-guest');

    $host = 'console-guest.'.mb_ltrim((string) config('session.domain'), '.');

    $this->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/start", [], ['HTTP_HOST' => $host])
        ->assertUnauthorized();

    expect($deck->fresh()->status)->toBe(PollDeck::STATUS_DRAFT);
});

test('a deck belonging to another tenant is not found, whoever asks', function (): void {
    [$tenantA, $eventA, $deckA, $firstA, $secondA, $userA, $hostA] = deckConsole('console-mine');
    [$tenantB, $eventB, $deckB] = deckConsole('console-theirs');

    // Someone else's deck id on my own event's URL. TenantScope is a no-op
    // without a tenant in context, so findDeck's explicit scoping is the only
    // thing standing between these two rooms.
    $this->actingAs($userA)
        ->postJson("http://{$hostA}/events/{$eventA->id}/decks/{$deckB->id}/start", [], ['HTTP_HOST' => $hostA])
        ->assertNotFound();

    expect($deckB->fresh()->status)->toBe(PollDeck::STATUS_DRAFT);
});

test('starting a deck that is already running does not send the room back to question one', function (): void {
    [$tenant, $event, $deck, $first, $second, $user, $host] = deckConsole('console-restart');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/start", [], ['HTTP_HOST' => $host])->assertOk();
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/advance", [], ['HTTP_HOST' => $host])->assertOk();

    // A reloaded presenter tab, or a double tap. Restarting would reopen
    // question one while question two was still live.
    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/start", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('current_poll_id', $second->id);

    expect($first->fresh()->status)->toBe(EventPoll::STATUS_CLOSED)
        ->and($second->fresh()->status)->toBe(EventPoll::STATUS_LIVE);
});

test('an advance after the deck has ended is refused, not obeyed', function (): void {
    [$tenant, $event, $deck, $first, $second, $user, $host] = deckConsole('console-ended');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/start", [], ['HTTP_HOST' => $host])->assertOk();
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/end", [], ['HTTP_HOST' => $host])->assertOk();

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/decks/{$deck->id}/advance", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('status', PollDeck::STATUS_ENDED)
        ->assertJsonPath('current_poll_id', null);

    // Nothing stranded live on a deck the room has been told is over.
    expect($second->fresh()->status)->not->toBe(EventPoll::STATUS_LIVE);
});
