<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\PollResultsUpdated;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;
use App\Models\Tenant;
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
        ->and($props['join']['url'])->toContain('/poll');

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
    $host = eventSubdomainHost('present-live');

    $this->postJson("http://{$host}/e/{$event->slug}/poll/{$poll->id}/respond", [
        'option_id' => $option->id,
        'respondent_token' => 'voter-1',
    ], ['HTTP_HOST' => $host])->assertOk();

    EventFacade::assertDispatched(PollResultsUpdated::class, function (PollResultsUpdated $broadcast) use ($event): bool {
        $payload = $broadcast->broadcastWith();

        return $broadcast->broadcastOn()[0]->name === "event.{$event->id}.poll"
            && $payload['total_responses'] === 1
            // Public channel, so the payload must carry nothing personal.
            && ! str_contains(json_encode($payload), 'respondent_token');
    });

    unset($tenant);
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
