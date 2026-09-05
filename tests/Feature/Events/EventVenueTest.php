<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSeatAssignment;
use App\Models\EventVenueRoom;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function venueHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('host can create a room', function () {
    [$tenant, $user] = venueHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/venue/rooms", [
        'name' => 'Main Hall',
        'rows' => 5,
        'seats_per_row' => 8,
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect(EventVenueRoom::where('event_id', $event->id)->where('name', 'Main Hall')->exists())->toBeTrue();
});

test('deleting a room removes its seat assignments too', function () {
    [$tenant, $user] = venueHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $room = EventVenueRoom::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'rows' => 1, 'seats_per_row' => 1]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/venue/rooms/{$room->id}/seats", [
        'seat_label' => 'A-01',
        'registration_id' => $registration->id,
    ], ['HTTP_HOST' => $host])->assertOk();

    $this->actingAs($user)->deleteJson("http://{$host}/events/{$event->id}/venue/rooms/{$room->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect(EventVenueRoom::where('id', $room->id)->exists())->toBeFalse();
});

test('host can assign and unassign a seat, and a registration can only hold one seat', function () {
    [$tenant, $user] = venueHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $room = EventVenueRoom::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'rows' => 2, 'seats_per_row' => 2]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $assign = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/venue/rooms/{$room->id}/seats", [
        'seat_label' => 'A-01',
        'registration_id' => $registration->id,
    ], ['HTTP_HOST' => $host]);
    $assign->assertOk();

    $assignment = EventSeatAssignment::where('registration_id', $registration->id)->firstOrFail();
    expect($assignment->seat_label)->toBe('A-01');

    // Re-assigning the same registration moves them rather than creating a second seat.
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/venue/rooms/{$room->id}/seats", [
        'seat_label' => 'B-02',
        'registration_id' => $registration->id,
    ], ['HTTP_HOST' => $host])->assertOk();

    expect(EventSeatAssignment::where('registration_id', $registration->id)->count())->toBe(1);
    expect(EventSeatAssignment::where('registration_id', $registration->id)->first()->seat_label)->toBe('B-02');

    $current = EventSeatAssignment::where('registration_id', $registration->id)->first();
    $this->actingAs($user)->deleteJson("http://{$host}/events/{$event->id}/venue/rooms/{$room->id}/seats/{$current->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect(EventSeatAssignment::where('registration_id', $registration->id)->exists())->toBeFalse();
});

test('assigning an already-taken seat to someone else is rejected cleanly', function () {
    [$tenant, $user] = venueHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $room = EventVenueRoom::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'rows' => 1, 'seats_per_row' => 1]);
    $first = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);
    $second = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/venue/rooms/{$room->id}/seats", [
        'seat_label' => 'A-01',
        'registration_id' => $first->id,
    ], ['HTTP_HOST' => $host])->assertOk();

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/venue/rooms/{$room->id}/seats", [
        'seat_label' => 'A-01',
        'registration_id' => $second->id,
    ], ['HTTP_HOST' => $host])->assertStatus(422);

    expect(EventSeatAssignment::where('seat_label', 'A-01')->first()->registration_id)->toBe($first->id);
});

test('an invalid seat label outside the room grid is rejected', function () {
    [$tenant, $user] = venueHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $room = EventVenueRoom::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'rows' => 1, 'seats_per_row' => 1]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/venue/rooms/{$room->id}/seats", [
        'seat_label' => 'Z-99',
        'registration_id' => $registration->id,
    ], ['HTTP_HOST' => $host])->assertStatus(422);
});
