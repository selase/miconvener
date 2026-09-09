<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

/**
 * "Get help" raises a request for water, a technician, accessibility support.
 * It is only answerable while there are staff in the room, so the window has to
 * be enforced on the endpoint -- hiding the tab would still leave the route open.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function helpScenario(string $slug, array $eventAttrs, array $registrationAttrs = []): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([...$eventAttrs, 'tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        ...$registrationAttrs,
    ]);

    return [$event, $registration, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('help cannot be requested weeks before the event', function () {
    [$event, $registration, $host] = helpScenario('help-early', [
        'starts_at' => now()->addDays(21), 'ends_at' => now()->addDays(21)->addHours(6),
    ]);

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/service-requests", [
        'type' => EventServiceRequest::TYPE_REFRESHMENT,
    ], ['HTTP_HOST' => $host])->assertStatus(422);

    expect(EventServiceRequest::where('event_id', $event->id)->count())->toBe(0);
});

test('help can be requested while the event is running', function () {
    [$event, $registration, $host] = helpScenario('help-live', [
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5),
    ]);

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/service-requests", [
        'type' => EventServiceRequest::TYPE_REFRESHMENT,
        'location' => 'Table 14',
    ], ['HTTP_HOST' => $host])->assertOk();

    expect(EventServiceRequest::where('event_id', $event->id)->count())->toBe(1);
});

test('a checked-in attendee can ask before the published start time', function () {
    // People arrive early. Being scanned in is better evidence of being in the
    // room than the clock is.
    [$event, $registration, $host] = helpScenario('help-earlybird', [
        'starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(9),
    ], ['checked_in_at' => now()->subMinutes(10)]);

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/service-requests", [
        'type' => EventServiceRequest::TYPE_ASSISTANCE,
    ], ['HTTP_HOST' => $host])->assertOk();

    expect(EventServiceRequest::where('event_id', $event->id)->count())->toBe(1);
});

test('the confirmation page only offers the tabs that can be acted on', function () {
    [$event, $registration, $host] = helpScenario('help-tabs', [
        'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addHours(4),
    ]);

    $props = $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['canRequestHelp'])->toBeFalse();
    expect($props['materials'])->toBe([]);
});
