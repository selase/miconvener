<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollResponse;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function pollHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

// A poll is now answered from the attendee portal by a registered, checked-in
// person -- not by an anonymous caller supplying its own respondent_token.
// This stands in for "someone at the event, signed in on their phone."
function checkedInVoter(Tenant $tenant, Event $event, string $email): EventRegistration
{
    return EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now(),
    ]);
}

test('host can create a multiple choice poll with options', function () {
    [$tenant, $user] = pollHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/polls", [
        'question' => 'Which resource carries allergies?',
        'type' => 'multiple_choice',
        'options' => ['AllergyIntolerance', 'Condition', 'Observation'],
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('options'))->toHaveCount(3);
});

test('the public poll endpoint only returns a live poll', function () {
    [$tenant] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventPoll::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => 'draft']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $withoutLive = $this->getJson("http://{$host}/e/{$event->slug}/poll", ['HTTP_HOST' => $host]);
    $withoutLive->assertOk();
    expect($withoutLive->json('poll'))->toBeNull();

    EventPoll::factory()->live()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'question' => 'Live one']);

    $this->getJson("http://{$host}/e/{$event->slug}/poll", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('poll.question', 'Live one');
});

test('the public respond route is closed and points to the portal', function () {
    [$tenant] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::factory()->live()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // An old QR or a bookmarked page should say something useful rather than
    // 404ing in someone's hand, and never accept an anonymous vote again.
    $response = $this->postJson("http://{$host}/e/{$event->slug}/poll/{$poll->id}/respond", [
        'respondent_token' => 'token-abc',
    ], ['HTTP_HOST' => $host]);

    $response->assertStatus(410);
    expect($response->json('message'))->toContain('portal');
    expect($response->json('portal_url'))->not->toBeNull();
});

test('a respondent can only answer a poll once', function () {
    [$tenant] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::factory()->live()->open()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $registration = checkedInVoter($tenant, $event, 'voter@example.com');
    $host = app(TenantHostMatcher::class)->baseDomain();
    $url = "http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond";

    $this->withSession(proofFor($registration->email))
        ->postJson($url, ['response_text' => 'First answer'], ['HTTP_HOST' => $host])
        ->assertOk();

    $this->withSession(proofFor($registration->email))
        ->postJson($url, ['response_text' => 'Second try'], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect(EventPollResponse::where('poll_id', $poll->id)->count())->toBe(1);
});

test('a multiple choice response cannot be submitted with an option from a different poll', function () {
    [$tenant] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::factory()->live()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $otherPoll = EventPoll::factory()->live()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $foreignOption = $otherPoll->options()->create(['tenant_id' => $tenant->id, 'label' => 'Foreign option']);

    $registration = checkedInVoter($tenant, $event, 'voter@example.com');
    $host = app(TenantHostMatcher::class)->baseDomain();

    // respondPoll has three 422 branches now; name the one that must fire, or
    // this stays green when a different refusal starts answering first.
    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $foreignOption->id,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonValidationErrors('option_id');
});

test('host can create a quiz question with a correct answer marked', function () {
    [$tenant, $user] = pollHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/polls", [
        'question' => 'What year was FHIR R4 released?',
        'type' => 'quiz',
        'options' => ['2017', '2019', '2021'],
        'correct_option_index' => 1,
        'timer_seconds' => 20,
        'points' => 15,
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('options.1.is_correct'))->toBeTrue();
    expect($response->json('options.0.is_correct'))->toBeFalse();
    expect($response->json('timer_seconds'))->toBe(20);
    expect($response->json('points'))->toBe(15);
});

test('answering a quiz correctly awards points and answering wrong awards none', function () {
    [$tenant] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::factory()->live()->quiz()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'points' => 15]);
    $correct = $poll->options()->create(['tenant_id' => $tenant->id, 'label' => 'Right', 'is_correct' => true]);
    $wrong = $poll->options()->create(['tenant_id' => $tenant->id, 'label' => 'Wrong', 'is_correct' => false]);

    $host = app(TenantHostMatcher::class)->baseDomain();

    $rightVoter = checkedInVoter($tenant, $event, 'right@example.com');
    $correctResponse = $this->withSession(proofFor($rightVoter->email))
        ->postJson("http://{$host}/my/events/{$rightVoter->id}/poll/{$poll->id}/respond", [
            'option_id' => $correct->id,
            'respondent_name' => 'Ama',
        ], ['HTTP_HOST' => $host]);
    $correctResponse->assertOk();
    expect($correctResponse->json('is_correct'))->toBeTrue();
    expect($correctResponse->json('points_awarded'))->toBe(15);

    $wrongVoter = checkedInVoter($tenant, $event, 'wrong@example.com');
    $wrongResponse = $this->withSession(proofFor($wrongVoter->email))
        ->postJson("http://{$host}/my/events/{$wrongVoter->id}/poll/{$poll->id}/respond", [
            'option_id' => $wrong->id,
        ], ['HTTP_HOST' => $host]);
    $wrongResponse->assertOk();
    expect($wrongResponse->json('is_correct'))->toBeFalse();
    expect($wrongResponse->json('points_awarded'))->toBe(0);
});

test('a quiz response is rejected once the timer has run out', function () {
    [$tenant] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'type' => EventPoll::TYPE_QUIZ,
        'status' => EventPoll::STATUS_LIVE,
        'timer_seconds' => 20,
        'went_live_at' => now()->subSeconds(30),
    ]);
    $option = $poll->options()->create(['tenant_id' => $tenant->id, 'label' => 'Option', 'is_correct' => true]);

    $registration = checkedInVoter($tenant, $event, 'voter@example.com');
    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonPath('message', "Time's up for this question.");
});

test('the quiz leaderboard sums points across quiz questions per respondent', function () {
    [$tenant, $user] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $pollOne = EventPoll::factory()->live()->quiz()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'points' => 10]);
    $optionOneCorrect = $pollOne->options()->create(['tenant_id' => $tenant->id, 'label' => 'Right', 'is_correct' => true]);

    $pollTwo = EventPoll::factory()->live()->quiz()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'points' => 5]);
    $optionTwoCorrect = $pollTwo->options()->create(['tenant_id' => $tenant->id, 'label' => 'Right again', 'is_correct' => true]);

    $registration = checkedInVoter($tenant, $event, 'kwame@example.com');
    $attendeeHost = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$attendeeHost}/my/events/{$registration->id}/poll/{$pollOne->id}/respond", [
            'option_id' => $optionOneCorrect->id,
            'respondent_name' => 'Kwame',
        ], ['HTTP_HOST' => $attendeeHost])->assertOk();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$attendeeHost}/my/events/{$registration->id}/poll/{$pollTwo->id}/respond", [
            'option_id' => $optionTwoCorrect->id,
            'respondent_name' => 'Kwame',
        ], ['HTTP_HOST' => $attendeeHost])->assertOk();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $publicLeaderboard = $this->getJson("http://{$host}/e/{$event->slug}/quiz/leaderboard", ['HTTP_HOST' => $host]);
    $publicLeaderboard->assertOk();
    expect($publicLeaderboard->json('0.name'))->toBe('Kwame');
    expect($publicLeaderboard->json('0.points'))->toBe(15);

    $hostLeaderboard = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/quiz/leaderboard", ['HTTP_HOST' => $host]);
    $hostLeaderboard->assertOk();
    expect($hostLeaderboard->json('0.points'))->toBe(15);
});

test('an open poll marked to require moderation holds responses back until a host approves them', function () {
    [$tenant, $user] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::factory()->live()->open()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'requires_moderation' => true]);

    $registration = checkedInVoter($tenant, $event, 'voter@example.com');
    $attendeeHost = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$attendeeHost}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'response_text' => 'Pending answer',
        ], ['HTTP_HOST' => $attendeeHost])->assertOk();

    $response = EventPollResponse::where('poll_id', $poll->id)->firstOrFail();
    expect($response->is_approved)->toBeNull();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $listing = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/polls", ['HTTP_HOST' => $host]);
    $pollPayload = collect($listing->json())->firstWhere('id', $poll->id);
    expect($pollPayload['open_responses'])->toBeEmpty();
    expect($pollPayload['pending_responses'])->toHaveCount(1);

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/polls/{$poll->id}/responses/{$response->id}", [
        'is_approved' => true,
    ], ['HTTP_HOST' => $host])->assertOk();

    $listingAfter = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/polls", ['HTTP_HOST' => $host]);
    $pollPayloadAfter = collect($listingAfter->json())->firstWhere('id', $poll->id);
    expect($pollPayloadAfter['open_responses'])->toHaveCount(1);
    expect($pollPayloadAfter['pending_responses'])->toBeEmpty();
});

test('an open poll without moderation auto-approves responses as before', function () {
    [$tenant] = pollHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::factory()->live()->open()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $registration = checkedInVoter($tenant, $event, 'voter@example.com');
    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'response_text' => 'Instant answer',
        ], ['HTTP_HOST' => $host])->assertOk();

    expect(EventPollResponse::where('poll_id', $poll->id)->first()->is_approved)->toBeTrue();
});
