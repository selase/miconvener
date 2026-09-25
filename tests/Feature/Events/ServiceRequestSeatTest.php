<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSeatAssignment;
use App\Models\EventServiceRequest;
use App\Models\EventVenueRoom;
use Illuminate\Support\Facades\Artisan;

/**
 * A steward reading "Ama Serwaa wants water" has to walk the room asking who
 * asked. Where seats are allocated, the seat is the difference between a name
 * and a destination, and the registration already knows it.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('an open request carries the seat and room of whoever raised it', function () {
    [$tenant, $user] = eventHost('seat-req');
    $host = eventSubdomainHost('seat-req');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $room = EventVenueRoom::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Main Auditorium',
        'rows' => 10,
        'seats_per_row' => 20,
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Ama Serwaa',
        'status' => EventRegistration::STATUS_CHECKED_IN,
    ]);
    EventSeatAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'room_id' => $room->id,
        'registration_id' => $registration->id,
        'seat_label' => 'C-14',
    ]);

    EventServiceRequest::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'type' => EventServiceRequest::TYPE_REFRESHMENT,
        'status' => EventServiceRequest::STATUS_OPEN,
        'priority' => EventServiceRequest::PRIORITY_NORMAL,
    ]);

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/service-requests", ['HTTP_HOST' => $host])
        ->assertOk();

    expect($response->json('0.registrant_name'))->toBe('Ama Serwaa')
        ->and($response->json('0.seat_label'))->toBe('C-14')
        ->and($response->json('0.room_name'))->toBe('Main Auditorium');
});

test('a request from an attendee with no allocated seat simply has none', function () {
    [$tenant, $user] = eventHost('seat-req-none');
    $host = eventSubdomainHost('seat-req-none');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Kofi Mensah',
        'status' => EventRegistration::STATUS_CHECKED_IN,
    ]);

    EventServiceRequest::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'type' => EventServiceRequest::TYPE_TECHNICAL,
        'status' => EventServiceRequest::STATUS_OPEN,
        'priority' => EventServiceRequest::PRIORITY_NORMAL,
    ]);

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/service-requests", ['HTTP_HOST' => $host])
        ->assertOk();

    expect($response->json('0.registrant_name'))->toBe('Kofi Mensah')
        ->and($response->json('0.seat_label'))->toBeNull()
        ->and($response->json('0.room_name'))->toBeNull();
});
