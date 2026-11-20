<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventRegistration;
use App\Models\PollDeck;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

function guestPollScenario(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'guest-room']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $poll = EventPoll::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'question' => 'What matters most?', 'type' => 'multiple_choice', 'status' => 'live', 'went_live_at' => now()]);
    $option = $poll->options()->create(['tenant_id' => $tenant->id, 'label' => 'Skills', 'sort_order' => 0]);
    $host = eventSubdomainHost($tenant->slug);
    $url = "http://{$host}/e/{$event->slug}/live-polls";

    return [$tenant, $event, $poll, $option, $host, $url];
}

test('guest polling is off by default', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $this->get($url, ['HTTP_HOST' => $host])->assertNotFound();
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertNotFound();
});

test('guests join and answer without registration or communications and cannot answer twice', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    Illuminate\Support\Facades\Mail::fake();
    Illuminate\Support\Facades\Notification::fake();
    $event->update(['guest_polls_enabled' => true]);
    $this->get($url, ['HTTP_HOST' => $host])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id, 'respondent_token' => 'forged', 'respondent_name' => 'Do not store'], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertUnprocessable();
    $this->getJson($url.'/state', ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('poll.user_response.option_id', $option->id);
    expect($poll->responses()->count())->toBe(1)
        ->and($poll->responses()->first()->respondent_name)->toBeNull()
        ->and($poll->responses()->first()->respondent_token)->not->toBe('forged')
        ->and(EventRegistration::withoutGlobalScopes()->where('event_id', $event->id)->count())->toBe(0);
    Illuminate\Support\Facades\Mail::assertNothingSent();
    Illuminate\Support\Facades\Mail::assertNothingQueued();
    Illuminate\Support\Facades\Notification::assertNothingSent();
});

test('a visitor cannot submit without explicitly joining', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertForbidden();
});

test('guest joins never grant portal access', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    expect(session()->has('platform_attendee_verified_emails'))->toBeFalse();
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $this->getJson('http://'.app(App\Services\Tenancy\TenantHostMatcher::class)->baseDomain().'/my/events/'.$registration->id.'/poll')->assertUnauthorized();
});

test('guest answers are refused outside the event window and when paused', function (string $condition): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    match ($condition) {
        'before' => $event->update(['starts_at' => now()->addMinute()]),
        'end' => $event->update(['ends_at' => now()]),
        'paused' => $event->update(['guest_polls_enabled' => false]),
        'cancelled' => $event->update(['status' => Event::STATUS_CANCELLED]),
        'private' => $event->update(['visibility' => Event::VISIBILITY_PRIVATE]),
    };
    $response = $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host]);
    expect($response->status())->toBeIn([403, 404]);
    expect($poll->responses()->count())->toBe(0);
})->with(['before', 'end', 'paused', 'cancelled', 'private']);

test('closed questions and another events questions or options cannot be answered', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson($url.'/state/'.Str::uuid().'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertNotFound();
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => (string) Str::uuid()], ['HTTP_HOST' => $host])->assertUnprocessable();
    $poll->update(['status' => 'closed']);
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertUnprocessable();
});

test('guest answers follow the presenters current deck and preserve text moderation', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $open = EventPoll::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'question' => 'Your question?', 'type' => 'open', 'status' => 'live', 'went_live_at' => now(), 'requires_moderation' => true]);
    PollDeck::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Session', 'status' => 'live', 'current_poll_id' => $open->id]);
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    $this->getJson($url.'/state', ['HTTP_HOST' => $host])->assertJsonPath('poll.id', $open->id);
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertUnprocessable();
    $this->postJson($url.'/state/'.$open->id.'/respond', ['response_text' => 'A private question'], ['HTTP_HOST' => $host])->assertOk();
    expect($open->responses()->first()->is_approved)->toBeNull();
    $this->getJson($url.'/state', ['HTTP_HOST' => $host])->assertJsonPath('poll.results.total_responses', 0)->assertJsonPath('poll.results.open_responses', []);
});

test('only authorised event staff can enable guest participation', function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/polls/guests", ['enabled' => true], ['HTTP_HOST' => $host])->assertForbidden();
    $user->assignRole('Org Superadmin');
    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/polls/guests", ['enabled' => true], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('enabled', true);
    expect($event->fresh()->guest_polls_enabled)->toBeTrue();
});

test('guest mode never opens private events even before joining', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true, 'visibility' => Event::VISIBILITY_PRIVATE]);
    $this->get($url, ['HTTP_HOST' => $host])->assertNotFound();
});

test('a guest session cannot answer for another event', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    $other = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'guest_polls_enabled' => true]);
    $this->postJson("http://{$host}/e/{$other->slug}/live-polls/state/{$poll->id}/respond", ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertForbidden();
});

test('a guest cannot view or answer after the polling entitlement is removed', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    $tenant->features()->create(['feature_key' => 'live_polling', 'enabled' => false]);
    $this->getJson($url.'/state', ['HTTP_HOST' => $host])->assertNotFound();
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertNotFound();
});

test('guest quiz timers and scores use server state', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $poll->update(['type' => EventPoll::TYPE_QUIZ, 'timer_seconds' => 5, 'points' => 20, 'went_live_at' => now()->subSeconds(6)]);
    $option->update(['is_correct' => true]);
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id, 'score' => 999], ['HTTP_HOST' => $host])->assertUnprocessable();
    $poll->update(['went_live_at' => now()]);
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id, 'score' => 999], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('score', 20);
});

test('the presenter joins guests without claiming nobody can vote and updates its QR when paused', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true, 'present_token' => Str::random(40)]);
    $wall = "http://{$host}/e/{$event->slug}/present/{$event->present_token}";
    $this->get($wall, ['HTTP_HOST' => $host])->assertInertia(fn ($page) => $page->where('guestParticipation', true)->where('join.url', $url));
    $event->update(['guest_polls_enabled' => false]);
    $this->getJson($wall.'/results', ['HTTP_HOST' => $host])->assertJsonPath('guestParticipation', false)->assertJsonPath('join.url', 'http://'.app(App\Services\Tenancy\TenantHostMatcher::class)->baseDomain().'/my');
});

test('guest write limits separate browsers on the same hall wifi', function (): void {
    $limiter = Illuminate\Support\Facades\RateLimiter::limiter('guest-poll-write');
    expect($limiter)->not->toBeNull();
    $request = Illuminate\Http\Request::create('/');
    $session = app('session')->driver();
    $request->setLaravelSession($session);
    $session->setId(Str::random(40));
    $first = $limiter($request);
    $session->setId(Str::random(40));
    $second = $limiter($request);
    expect($first[0]->key)->not->toBe($second[0]->key)
        ->and($first[1]->maxAttempts)->toBeGreaterThanOrEqual(2000);
});

test('guests retain the selected questions results after the presenter closes voting', function (): void {
    [$tenant, $event, $poll, $option, $host, $url] = guestPollScenario();
    $event->update(['guest_polls_enabled' => true]);
    $deck = PollDeck::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Discussion', 'status' => 'live', 'current_poll_id' => $poll->id]);
    $poll->update(['deck_id' => $deck->id]);
    $this->postJson($url.'/join', [], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertOk();
    $poll->update(['status' => 'closed']);
    $this->getJson($url.'/state', ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('poll.id', $poll->id)->assertJsonPath('poll.status', 'closed')->assertJsonPath('poll.results.total_responses', 1);
    $this->postJson($url.'/state/'.$poll->id.'/respond', ['option_id' => $option->id], ['HTTP_HOST' => $host])->assertUnprocessable();
});
