<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\PollResultsUpdated;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\DeckPresenter;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;

/**
 * The results screen is opened by whoever is driving the projector, on a token
 * rather than a login. That buys convenience at the cost of a URL that can be
 * forwarded, so what it serves must be safe on a wall: counts, never people.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function presentScenario(string $slug, string $type = EventPoll::TYPE_MULTIPLE_CHOICE): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'present_token' => Str::random(40),
    ]);

    $poll = EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'How are you joining us today?',
        'type' => $type,
        'status' => EventPoll::STATUS_LIVE,
        'went_live_at' => now()->subMinute(),
        'points' => 10,
    ]);

    $inRoom = EventPollOption::create([
        'tenant_id' => $tenant->id,
        'poll_id' => $poll->id,
        'label' => 'In the room',
        'sort_order' => 0,
        'is_correct' => true,
    ]);
    EventPollOption::create([
        'tenant_id' => $tenant->id,
        'poll_id' => $poll->id,
        'label' => 'Watching online',
        'sort_order' => 1,
    ]);

    return [$tenant, $event, $poll, $inRoom];
}

test('the screen opens on its token and serves counts, never people', function (): void {
    [$tenant, $event, $poll, $option] = presentScenario('present-ok');
    $host = eventSubdomainHost('present-ok');

    EventPollResponse::create([
        'tenant_id' => $tenant->id,
        'poll_id' => $poll->id,
        'option_id' => $option->id,
        'respondent_token' => 'a-token-that-must-never-be-shown',
        'respondent_name' => null,
        'is_approved' => true,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['poll']['question'])->toBe('How are you joining us today?')
        ->and($props['poll']['total_responses'])->toBe(1)
        ->and($props['poll']['options'][0]['count'])->toBe(1)
        ->and($props['poll']['options'][0]['percentage'])->toBe(100)
        // Somewhere a person can vote, not the JSON endpoint the public page
        // fetches. Asserting '/poll' here is what let the broken QR ship green.
        ->and($props['join']['url'])->toContain('/my');

    // Nothing that ties an answer to a person may reach a page anyone can open.
    $response->assertDontSee('a-token-that-must-never-be-shown');
    expect(json_encode($props))->not->toContain('respondent_token');
});

test('the correct answer stays hidden until voting closes', function (): void {
    [, $event, $poll] = presentScenario('present-quiz', EventPoll::TYPE_QUIZ);
    $host = eventSubdomainHost('present-quiz');

    $live = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    // Revealing it while people are still choosing would give it away to the
    // whole room from the back wall.
    expect($live['poll']['options'][0]['is_correct'])->toBeNull();

    $poll->update(['status' => EventPoll::STATUS_CLOSED]);

    $closed = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    expect($closed['poll']['options'][0]['is_correct'])->toBeTrue();
});

test('a wrong or missing token opens nothing', function (): void {
    [, $event] = presentScenario('present-guard');
    $host = eventSubdomainHost('present-guard');

    $this->get("http://{$host}/e/{$event->slug}/present/not-the-token", ['HTTP_HOST' => $host])
        ->assertNotFound();

    $this->get("http://{$host}/e/{$event->slug}/present/not-the-token/results", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('a token does not open another organisation event', function (): void {
    [, $mine] = presentScenario('present-mine');
    [, $theirs] = presentScenario('present-theirs');
    $host = eventSubdomainHost('present-theirs');

    // Their host, their event slug, my token.
    $this->get("http://{$host}/e/{$theirs->slug}/present/{$mine->present_token}", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('voting moves the screen without anyone reloading it', function (): void {
    EventFacade::fake([PollResultsUpdated::class]);

    [$tenant, $event, $poll, $option] = presentScenario('present-live');

    // The wall is driven by the portal now: a poll is answered by a
    // registered, checked-in attendee, not an anonymous caller-supplied token.
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'voter@example.com',
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now(),
    ]);
    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    EventFacade::assertDispatched(PollResultsUpdated::class, function (PollResultsUpdated $broadcast) use ($event): bool {
        $payload = $broadcast->broadcastWith();

        return $broadcast->broadcastOn()[0]->name === "event.{$event->id}.poll"
            && $payload['total_responses'] === 1
            // Public channel, so the payload must carry nothing personal.
            && ! str_contains(json_encode($payload), 'respondent_token');
    });
});

test('the console mints a link and revoking it breaks the old one', function (): void {
    [$tenant, $user] = eventHost('present-console');
    $host = eventSubdomainHost('present-console');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    // First ask mints it -- an organiser should not have to think about tokens.
    $first = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/polls/present-link", ['HTTP_HOST' => $host])
        ->assertOk()
        ->json('present_url');

    expect($first)->toContain('/present/')
        ->and($event->fresh()->present_token)->not->toBeNull();

    $rotated = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/polls/present-link/rotate", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->json('present_url');

    expect($rotated)->not->toBe($first);

    // The laptop still holding the old link stops showing results.
    $oldToken = mb_substr($first, mb_strrpos($first, '/') + 1);
    $this->get("http://{$host}/e/{$event->slug}/present/{$oldToken}", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('an open poll beats a closed one, however recently it closed', function (): void {
    [$tenant, $event] = presentScenario('present-order');
    $host = eventSubdomainHost('present-order');

    // Closed later than the live one went live -- the organiser moved on, and
    // the wall should have moved with them.
    $closed = EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'The one we just finished',
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_CLOSED,
        'went_live_at' => now(),
    ]);

    // Created after the live one, and said so explicitly. The polls relation
    // sorts on created_at, so without that being set aside this closed poll
    // wins -- which is how this test once passed while production showed the
    // wrong question: both rows landed in the same second and the tie fell the
    // right way by luck.
    $closed->forceFill(['created_at' => now()->addMinute()])->saveQuietly();

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    expect($props['poll']['question'])->toBe('How are you joining us today?');
});

test('an answer awaiting a moderator does not reach the wall', function (): void {
    [$tenant, $event, $poll] = presentScenario('present-moderated', EventPoll::TYPE_OPEN);
    $host = eventSubdomainHost('present-moderated');

    $poll->update(['requires_moderation' => true]);

    EventPollResponse::create([
        'tenant_id' => $tenant->id,
        'poll_id' => $poll->id,
        'response_text' => 'approved and safe to show',
        'respondent_token' => 'voter-approved',
        'is_approved' => true,
    ]);
    EventPollResponse::create([
        'tenant_id' => $tenant->id,
        'poll_id' => $poll->id,
        'response_text' => 'still waiting on a moderator',
        'respondent_token' => 'voter-pending',
        'is_approved' => null,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host]);
    $props = $response->viewData('page')['props'];

    expect(collect($props['poll']['open_responses'])->pluck('text'))
        ->toContain('approved and safe to show')
        ->not->toContain('still waiting on a moderator');

    $response->assertDontSee('still waiting on a moderator');
});

test('a poll nobody answered shows zeroes rather than dividing by them', function (): void {
    [, $event] = presentScenario('present-empty');
    $host = eventSubdomainHost('present-empty');

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    expect($props['poll']['total_responses'])->toBe(0)
        ->and($props['poll']['options'][0]['percentage'])->toBe(0)
        ->and($props['poll']['options'][0]['count'])->toBe(0);
});

test('the wall shows the deck question, not whatever went live last', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('wall-follows');
    $event->update(['present_token' => Str::random(40)]);
    $host = eventSubdomainHost('wall-follows');

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->advance($deck->fresh());

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    expect($props['poll']['question'])->toBe('Second');
});

test('a loose poll that went live later does not steal the wall from the deck', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('wall-steal');
    $event->update(['present_token' => Str::random(40)]);
    $host = eventSubdomainHost('wall-steal');

    app(DeckPresenter::class)->start($deck);

    // Opened after the deck's question and with a later went_live_at, so the
    // old ordering would have put it on the wall in front of the room.
    EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'Loose and later',
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_LIVE,
        'went_live_at' => now()->addMinutes(5),
    ]);

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    expect($props['poll']['question'])->toBe('First');
});

test('a room where nobody has checked in says so rather than showing silence as a result', function (): void {
    [$tenant, $event, $deck, $first] = presenterScenario('wall-empty');
    $event->update(['present_token' => Str::random(40)]);
    $host = eventSubdomainHost('wall-empty');

    app(DeckPresenter::class)->start($deck);

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    // Zero bars and "nobody can answer yet" look identical on a wall, and the
    // second is the one an organiser can act on.
    expect($props['poll']['total_responses'])->toBe(0)
        ->and($props['eligibleVoters'])->toBe(0);
});

test('the wall counts only the people who are actually here', function (): void {
    [$tenant, $event, $deck, $first] = presenterScenario('wall-eligible');
    $event->update(['present_token' => Str::random(40)]);
    $host = eventSubdomainHost('wall-eligible');

    EventRegistration::factory()->count(2)->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CHECKED_IN, 'checked_in_at' => now(),
    ]);
    // Confirmed but not through the door: cannot answer, so must not be counted
    // as someone the room is waiting on.
    EventRegistration::factory()->count(3)->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED, 'checked_in_at' => null,
    ]);

    app(DeckPresenter::class)->start($deck);

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    expect($props['eligibleVoters'])->toBe(2);
});

test('the code on the wall leads somewhere a person can vote', function (): void {
    [$tenant, $event, $deck] = presenterScenario('wall-qr');
    $event->update(['present_token' => Str::random(40)]);
    $host = eventSubdomainHost('wall-qr');

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    // It pointed at /e/{event}/poll, which returns JSON. Someone in a room
    // scanned it and got a blob.
    $join = $props['join']['url'];

    expect($join)->toContain('/my')
        ->and($join)->not->toContain('/poll');

    $platformHost = app(TenantHostMatcher::class)->baseDomain();

    expect($join)->toStartWith("http://{$platformHost}/");

    $this->get($join, ['HTTP_HOST' => $platformHost])->assertOk();
});
