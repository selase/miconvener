<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function serviceRequestHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('an attendee can raise a service request from their ticket', function () {
    [$tenant] = serviceRequestHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/service-requests", [
        'type' => 'refreshment',
        'location' => 'C-114, Grand Ballroom',
        'note' => 'Still water please',
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $serviceRequest = EventServiceRequest::where('registration_id', $registration->id)->firstOrFail();
    expect($serviceRequest->type)->toBe('refreshment');
    expect($serviceRequest->priority)->toBe(EventServiceRequest::PRIORITY_NORMAL);
    expect($serviceRequest->status)->toBe(EventServiceRequest::STATUS_OPEN);
    expect($serviceRequest->location)->toBe('C-114, Grand Ballroom');
    expect($serviceRequest->note)->toBe('Still water please');
});

test('a medical request is automatically raised at urgent priority', function () {
    [$tenant] = serviceRequestHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/service-requests", [
        'type' => 'medical',
    ], ['HTTP_HOST' => $host])->assertOk();

    $serviceRequest = EventServiceRequest::where('registration_id', $registration->id)->firstOrFail();
    expect($serviceRequest->priority)->toBe(EventServiceRequest::PRIORITY_URGENT);
    expect($serviceRequest->isMedical())->toBeTrue();
});

test('an invalid request type is rejected', function () {
    [$tenant] = serviceRequestHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/service-requests", [
        'type' => 'not-a-real-type',
    ], ['HTTP_HOST' => $host])->assertStatus(422);
});

test('the staff queue lists requests with elapsed time and registrant name', function () {
    [$tenant, $user] = serviceRequestHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Comfort Adjei']);
    EventServiceRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'type' => 'technical',
        'location' => 'Volta Room',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/service-requests", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonFragment(['registrant_name' => 'Comfort Adjei', 'location' => 'Volta Room', 'type' => 'technical']);
});

test('a staff member can claim an open request, moving it to acknowledged and assigning themselves', function () {
    [$tenant, $user] = serviceRequestHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $serviceRequest = EventServiceRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/service-requests/{$serviceRequest->id}/claim", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $serviceRequest->refresh();
    expect($serviceRequest->status)->toBe(EventServiceRequest::STATUS_ACKNOWLEDGED);
    expect((string) $serviceRequest->assigned_to)->toBe((string) $user->id);
    expect($serviceRequest->acknowledged_at)->not->toBeNull();
});

test('a staff member can move a request through in_progress to resolved', function () {
    [$tenant, $user] = serviceRequestHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $serviceRequest = EventServiceRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'status' => EventServiceRequest::STATUS_ACKNOWLEDGED,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/service-requests/{$serviceRequest->id}", ['status' => 'in_progress'], ['HTTP_HOST' => $host])
        ->assertOk();
    expect($serviceRequest->refresh()->status)->toBe(EventServiceRequest::STATUS_IN_PROGRESS);

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/service-requests/{$serviceRequest->id}", ['status' => 'resolved'], ['HTTP_HOST' => $host])
        ->assertOk();

    $serviceRequest->refresh();
    expect($serviceRequest->status)->toBe(EventServiceRequest::STATUS_RESOLVED);
    expect($serviceRequest->resolved_at)->not->toBeNull();
});

test('an invalid status transition value is rejected', function () {
    [$tenant, $user] = serviceRequestHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $serviceRequest = EventServiceRequest::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'registration_id' => $registration->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/service-requests/{$serviceRequest->id}", ['status' => 'not-a-status'], ['HTTP_HOST' => $host])
        ->assertStatus(422);
});

test('service requests are isolated to their own tenant', function () {
    [$tenantA, $userA] = serviceRequestHost();
    $tenantB = Tenant::factory()->create(['slug' => 'other', 'isolation_mode' => 'shared']);
    $eventB = Event::factory()->create(['tenant_id' => $tenantB->id]);
    $registrationB = EventRegistration::factory()->create(['tenant_id' => $tenantB->id, 'event_id' => $eventB->id]);
    $serviceRequestB = EventServiceRequest::factory()->create(['tenant_id' => $tenantB->id, 'event_id' => $eventB->id, 'registration_id' => $registrationB->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $hostA = "acme.{$baseDomain}";

    $this->actingAs($userA)->patchJson("http://{$hostA}/events/{$eventB->id}/service-requests/{$serviceRequestB->id}/claim", [], ['HTTP_HOST' => $hostA])
        ->assertNotFound();
});
