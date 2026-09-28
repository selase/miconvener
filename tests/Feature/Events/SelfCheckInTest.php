<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Events\SelfCheckIn;
use App\Services\Tenancy\TenantHostMatcher;

test('an attendee at a virtual event can mark themselves present', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin');
    $host = app(TenantHostMatcher::class)->baseDomain();

    expect($registration->checked_in_at)->toBeNull();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('checked_in', true);

    $registration->refresh();

    expect($registration->checked_in_at)->not->toBeNull()
        ->and($registration->checked_in_source)->toBe('self')
        ->and($registration->checked_in_by)->toBeNull()
        ->and($registration->status)->toBe(EventRegistration::STATUS_CHECKED_IN);
});

test('self check-in is closed before the event and after it ends', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin-window');
    $host = app(TenantHostMatcher::class)->baseDomain();

    $event->update(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($registration->fresh()->checked_in_at)->toBeNull();
});

test('an in-person event does not offer self check-in unless the organiser turns it on', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin-inperson');
    $event->update(['location_type' => Event::LOCATION_IN_PERSON]);
    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    $event->update(['allows_self_check_in' => true]);

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertOk();
});

test('a door scan is still distinguishable from a self report', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin-source');

    app(SelfCheckIn::class)->perform($registration);

    expect($registration->fresh()->checked_in_source)->toBe('self');
});
