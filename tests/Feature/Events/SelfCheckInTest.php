<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventRegistration;
use App\Services\Events\SelfCheckIn;
use App\Services\Tenancy\TenantHostMatcher;

test('an attendee at a virtual event can mark themselves present', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin');
    EventNotificationRule::create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Self checked in',
        'target_role' => 'attendee', 'target_audience' => 'checked_in',
        'trigger_type' => EventNotificationRule::TRIGGER_ON_CHECKIN, 'channels' => ['email'],
        'subject' => 'Checked in', 'body_template' => 'Hello {name}.', 'is_active' => true,
    ]);
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
    expect(EventNotificationLog::query()->where('source_id', $registration->id)->count())->toBe(1);
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
