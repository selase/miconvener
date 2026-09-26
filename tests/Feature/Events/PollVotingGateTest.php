<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Tenancy\TenantHostMatcher;

function votingScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(3),
    ]);
    $poll = EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'Are you with us?',
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_LIVE,
        'went_live_at' => now()->subMinutes(2),
    ]);
    $option = EventPollOption::create([
        'tenant_id' => $tenant->id,
        'poll_id' => $poll->id,
        'label' => 'Yes',
        'sort_order' => 0,
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'voter@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    return [$tenant, $event, $poll, $option, $registration];
}

test('a confirmed attendee who has not checked in is told which condition failed', function (): void {
    [$tenant, $event, $poll, $option, $registration] = votingScenario('vote-not-present');
    $host = app(TenantHostMatcher::class)->baseDomain();

    $response = $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(403);

    // Not a blank refusal: a person standing in a room needs to know it is the
    // check-in desk they want, not the help desk.
    expect($response->json('message'))->toContain('checked in');
});

test('a present attendee votes', function (): void {
    [$tenant, $event, $poll, $option, $registration] = votingScenario('vote-present');
    $registration->update(['checked_in_at' => now(), 'status' => EventRegistration::STATUS_CHECKED_IN]);
    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($poll->responses()->count())->toBe(1);
});

test('a second device cannot vote again for the same registration', function (): void {
    [$tenant, $event, $poll, $option, $registration] = votingScenario('vote-twice');
    $registration->update(['checked_in_at' => now(), 'status' => EventRegistration::STATUS_CHECKED_IN]);
    $host = app(TenantHostMatcher::class)->baseDomain();

    $vote = fn () => $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host]);

    $vote()->assertOk();

    // A fresh session stands in for a second phone: the token is derived from
    // the registration, so clearing storage earns nothing.
    $vote()->assertStatus(422);

    expect($poll->responses()->count())->toBe(1);
});
