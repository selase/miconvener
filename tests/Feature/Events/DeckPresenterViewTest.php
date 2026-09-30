<?php

declare(strict_types=1);

use App\Models\EventPoll;
use App\Models\PollDeck;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A deck with a presenter token, and the tenant host it is served from.
 *
 * @return array{0: mixed, 1: mixed, 2: PollDeck, 3: EventPoll, 4: EventPoll, 5: string, 6: string}
 */
function presenterLinkScenario(string $slug): array
{
    [$tenant, $event, $deck, $first, $second] = presenterScenario($slug);
    $deck->update(['present_token' => Str::random(48)]);
    $host = eventSubdomainHost($slug);
    $base = "http://{$host}/e/{$event->slug}/decks/{$deck->id}/present/{$deck->present_token}";

    return [$tenant, $event, $deck, $first, $second, $host, $base];
}

test('the presenter link opens the presenter screen for its deck', function (): void {
    [$tenant, $event, $deck, $first, $second, $host, $base] = presenterLinkScenario('pv-open');

    $props = $this->get($base, ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['deck']['title'])->toBe('Plenary')
        ->and($props['deck']['status'])->toBe(PollDeck::STATUS_DRAFT)
        ->and($props['deck']['total'])->toBe(2)
        ->and($props['deck']['next']['question'])->toBe('First')
        ->and($props['deck']['questions'])->toBe(['First', 'Second']);
});

test('a speaker with only the link can drive the deck from start to end', function (): void {
    [$tenant, $event, $deck, $first, $second, $host, $base] = presenterLinkScenario('pv-drive');

    // No login anywhere in this test: the link is the whole credential.
    $this->postJson("{$base}/start", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('status', PollDeck::STATUS_LIVE)
        ->assertJsonPath('position', 1)
        ->assertJsonPath('current.question', 'First')
        ->assertJsonPath('next.question', 'Second')
        ->assertJsonPath('can_go_back', false)
        ->assertJsonPath('can_advance', true);

    $this->postJson("{$base}/advance", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('position', 2)
        ->assertJsonPath('current.question', 'Second')
        ->assertJsonPath('next', null)
        ->assertJsonPath('can_advance', false);

    $this->postJson("{$base}/previous", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('position', 1);

    $this->postJson("{$base}/close", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('current.status', EventPoll::STATUS_CLOSED);

    $this->postJson("{$base}/end", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('status', PollDeck::STATUS_ENDED)
        ->assertJsonPath('current', null);
});

test('a wrong token is not found, and moves nothing', function (): void {
    [$tenant, $event, $deck, $first, $second, $host] = presenterLinkScenario('pv-wrong');

    $wrong = "http://{$host}/e/{$event->slug}/decks/{$deck->id}/present/".Str::random(48);

    $this->get($wrong, ['HTTP_HOST' => $host])->assertNotFound();
    $this->postJson("{$wrong}/start", [], ['HTTP_HOST' => $host])->assertNotFound();

    expect($deck->fresh()->status)->toBe(PollDeck::STATUS_DRAFT);
});

test('a deck without a presenter token cannot be reached by guessing an empty one', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('pv-untokened');
    $host = eventSubdomainHost('pv-untokened');

    expect($deck->fresh()->present_token)->toBeNull();

    // A null token must never match an empty or placeholder segment.
    $this->get("http://{$host}/e/{$event->slug}/decks/{$deck->id}/present/null", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test("one deck's link cannot drive another deck", function (): void {
    [$tenant, $event, $deckA, $firstA, $secondA, $host] = presenterLinkScenario('pv-cross');

    $deckB = PollDeck::create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Other',
        'join_code' => PollDeck::generateJoinCode(), 'status' => PollDeck::STATUS_DRAFT,
        'present_token' => Str::random(48),
    ]);
    deckPoll($tenant, $event, $deckB, 'Other question', 0);

    // Deck A's token on deck B's URL.
    $this->postJson("http://{$host}/e/{$event->slug}/decks/{$deckB->id}/present/{$deckA->present_token}/start", [], ['HTTP_HOST' => $host])
        ->assertNotFound();

    expect($deckB->fresh()->status)->toBe(PollDeck::STATUS_DRAFT);
});

test("a presenter link does not work from another tenant's host", function (): void {
    [$tenantA, $eventA, $deckA, $firstA, $secondA, $hostA, $baseA] = presenterLinkScenario('pv-home');
    presenterScenario('pv-elsewhere');
    $hostB = eventSubdomainHost('pv-elsewhere');

    $path = parse_url($baseA, PHP_URL_PATH);

    $this->postJson("http://{$hostB}{$path}/start", [], ['HTTP_HOST' => $hostB])->assertNotFound();

    expect($deckA->fresh()->status)->toBe(PollDeck::STATUS_DRAFT);
});

test('an unknown action is not found rather than an error', function (): void {
    [$tenant, $event, $deck, $first, $second, $host, $base] = presenterLinkScenario('pv-action');

    $this->postJson("{$base}/delete", [], ['HTTP_HOST' => $host])->assertNotFound();
});

test('a deck id that is not a uuid is not found rather than a database error', function (): void {
    [$tenant, $event, $deck, $first, $second, $host] = presenterLinkScenario('pv-baduuid');

    // Unconstrained, this reached Postgres as a uuid comparison and threw.
    $this->get("http://{$host}/e/{$event->slug}/decks/not-a-uuid/present/{$deck->present_token}", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('the presenter screen carries counts, never the people behind them', function (): void {
    [$tenant, $event, $deck, $first, $second, $host, $base] = presenterLinkScenario('pv-privacy');
    $option = $first->options()->create(['tenant_id' => $tenant->id, 'label' => 'Yes', 'sort_order' => 0]);

    $this->postJson("{$base}/start", [], ['HTTP_HOST' => $host])->assertOk();

    $first->responses()->create([
        'tenant_id' => $tenant->id,
        'option_id' => $option->id,
        'respondent_token' => 'a-token-that-must-never-be-shown',
        'is_approved' => true,
    ]);

    $state = $this->getJson("{$base}/state", ['HTTP_HOST' => $host])->assertOk();

    expect($state->json('current.total_responses'))->toBe(1)
        ->and(json_encode($state->json()))->not->toContain('a-token-that-must-never-be-shown')
        ->and(json_encode($state->json()))->not->toContain('respondent_token')
        ->and(json_encode($state->json()))->not->toContain($deck->present_token);
});

test('issuing a new presenter link revokes the old one', function (): void {
    [$tenant, $event, $deck, $first, $second, $owner, $consoleHost] = deckConsole('pv-rotate');

    $issued = $this->actingAs($owner)
        ->getJson("http://{$consoleHost}/events/{$event->id}/decks/{$deck->id}/presenter-link", ['HTTP_HOST' => $consoleHost])
        ->assertOk()
        ->json('presenter_url');

    $this->actingAs($owner)
        ->postJson("http://{$consoleHost}/events/{$event->id}/decks/{$deck->id}/presenter-link/rotate", [], ['HTTP_HOST' => $consoleHost])
        ->assertOk();

    auth()->logout();

    // The laptop still holding the old link finds nothing on its next press.
    $this->postJson("{$issued}/start", [], ['HTTP_HOST' => $consoleHost])->assertNotFound();

    expect($deck->fresh()->status)->toBe(PollDeck::STATUS_DRAFT);
});

test('asking for the presenter link twice returns the same link', function (): void {
    [$tenant, $event, $deck, $first, $second, $owner, $consoleHost] = deckConsole('pv-stable');

    $url = "http://{$consoleHost}/events/{$event->id}/decks/{$deck->id}/presenter-link";

    $one = $this->actingAs($owner)->getJson($url, ['HTTP_HOST' => $consoleHost])->json('presenter_url');
    $two = $this->actingAs($owner)->getJson($url, ['HTTP_HOST' => $consoleHost])->json('presenter_url');

    // Reopening the console must not silently revoke a link a speaker is using.
    expect($two)->toBe($one);
});

test('a bystander cannot mint or rotate a presenter link', function (): void {
    [$tenant, $event, $deck, $first, $second, $owner, $consoleHost] = deckConsole('pv-perms');

    $bystander = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($bystander->id);
    setPermissionsTeamId($tenant->id);

    // The link grants control, so getting it needs the same right as pressing
    // Next yourself -- not merely the right to read the event.
    $this->actingAs($bystander)
        ->getJson("http://{$consoleHost}/events/{$event->id}/decks/{$deck->id}/presenter-link", ['HTTP_HOST' => $consoleHost])
        ->assertForbidden();

    $this->actingAs($bystander)
        ->postJson("http://{$consoleHost}/events/{$event->id}/decks/{$deck->id}/presenter-link/rotate", [], ['HTTP_HOST' => $consoleHost])
        ->assertForbidden();

    expect($deck->fresh()->present_token)->toBeNull();
});
