<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\EventStaffLink;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Ushers and floor crew work from a staff link on their own phone: no account,
 * no team seat, nothing reachable beyond scanning and attendee requests for one
 * event, and nothing at all once switched off or once the event is over.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->seed(EventPackageSeeder::class);

    $this->tenant = Tenant::factory()->create([
        'slug' => 'gatecrew',
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', 'starter')->firstOrFail()->id,
    ]);
    $this->tenant->syncFeaturesFromPackage();

    $this->host = 'gatecrew.'.mb_ltrim((string) config('session.domain'), '.');
    $this->event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(5),
    ]);
});

function staffOrganizer(Tenant $tenant, string $role = 'Org Superadmin'): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    $tenant->users()->attach($user->id);

    return $user;
}

function staffLinkFor(Tenant $tenant, Event $event, array $attributes = []): EventStaffLink
{
    return EventStaffLink::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, ...$attributes]);
}

function confirmedGuest(Tenant $tenant, Event $event): EventRegistration
{
    return EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'qr_token' => 'QR-'.fake()->unique()->numerify('######'),
    ]);
}

test('an organizer creates a staff link with a shareable url and QR code', function (): void {
    $organizer = staffOrganizer($this->tenant);

    $response = $this->actingAs($organizer)->postJson("http://{$this->host}/events/{$this->event->id}/staff-links", [
        'name' => 'Gate A – Kofi',
        'pin' => '4821',
        'can_check_in' => true,
        'can_handle_requests' => true,
    ], ['HTTP_HOST' => $this->host]);

    $response->assertCreated()
        ->assertJsonPath('name', 'Gate A – Kofi')
        ->assertJsonPath('has_pin', true)
        ->assertJsonPath('is_active', true);

    expect($response->json('url'))->toContain('/staff/')
        ->and($response->json('qr'))->toStartWith('data:image/svg+xml');

    $link = EventStaffLink::query()->firstOrFail();
    expect($link->pin_hash)->not->toBe('4821');
});

test('a link must allow scanning, requests, or both', function (): void {
    $this->actingAs(staffOrganizer($this->tenant))->postJson("http://{$this->host}/events/{$this->event->id}/staff-links", [
        'name' => 'Nobody',
        'can_check_in' => false,
        'can_handle_requests' => false,
    ], ['HTTP_HOST' => $this->host])->assertUnprocessable();
});

test('the plan allowance caps active links, and an usher pack adds five', function (): void {
    $organizer = staffOrganizer($this->tenant);
    $create = fn (string $name) => $this->actingAs($organizer)->postJson("http://{$this->host}/events/{$this->event->id}/staff-links", [
        'name' => $name, 'can_check_in' => true, 'can_handle_requests' => false,
    ], ['HTTP_HOST' => $this->host]);

    // Starter includes two.
    $create('One')->assertCreated();
    $create('Two')->assertCreated();
    $create('Three')->assertUnprocessable();

    // A switched-off link frees its place.
    EventStaffLink::query()->where('name', 'One')->update(['revoked_at' => now()]);
    $create('Three')->assertCreated();

    TenantAddon::factory()->create([
        'tenant_id' => $this->tenant->id,
        'addon_type' => TenantAddon::TYPE_USHER_PACK,
        'quantity' => 1,
        'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
        'status' => TenantAddon::STATUS_ACTIVE,
        'period_end' => now()->addMonth(),
    ]);

    expect($this->tenant->fresh()->featureLimitValue('staff_links'))->toBe(7);
    $create('Four')->assertCreated();
});

test('a staff link scans a ticket and the check-in records which link did it', function (): void {
    $link = staffLinkFor($this->tenant, $this->event);
    $guest = confirmedGuest($this->tenant, $this->event);

    $this->postJson("http://{$this->host}/staff/{$link->token}/checkin/scan", ['token' => $guest->qr_token], ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->assertJsonPath('already_checked_in', false);

    $guest->refresh();
    expect($guest->status)->toBe(EventRegistration::STATUS_CHECKED_IN)
        ->and($guest->checked_in_by_staff_link_id)->toBe($link->id)
        ->and($guest->checked_in_source)->toBe('staff_link')
        ->and($link->fresh()->last_used_at)->not->toBeNull();
});

test('a staff link cannot reach a ticket from another event', function (): void {
    $link = staffLinkFor($this->tenant, $this->event);
    $otherEvent = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $stranger = confirmedGuest($this->tenant, $otherEvent);

    $this->postJson("http://{$this->host}/staff/{$link->token}/checkin/scan", ['token' => $stranger->qr_token], ['HTTP_HOST' => $this->host])
        ->assertNotFound();

    $this->postJson("http://{$this->host}/staff/{$link->token}/checkin/{$stranger->id}", [], ['HTTP_HOST' => $this->host])
        ->assertNotFound();

    expect($stranger->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('a link only does what its switches allow', function (): void {
    $scanOnly = staffLinkFor($this->tenant, $this->event, ['can_check_in' => true, 'can_handle_requests' => false]);
    $requestsOnly = staffLinkFor($this->tenant, $this->event, ['can_check_in' => false, 'can_handle_requests' => true]);

    $this->getJson("http://{$this->host}/staff/{$scanOnly->token}/requests", ['HTTP_HOST' => $this->host])->assertForbidden();
    $this->getJson("http://{$this->host}/staff/{$requestsOnly->token}/checkin/search?q=a", ['HTTP_HOST' => $this->host])->assertForbidden();
});

test('a staff link claims and resolves an attendee request under its own name', function (): void {
    $link = staffLinkFor($this->tenant, $this->event, ['name' => 'Floor – Ama', 'can_handle_requests' => true]);
    $request = EventServiceRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'registration_id' => confirmedGuest($this->tenant, $this->event)->id,
        'type' => EventServiceRequest::TYPE_REFRESHMENT,
    ]);

    $this->getJson("http://{$this->host}/staff/{$link->token}/requests", ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->assertJsonPath('0.id', $request->id);

    $this->patchJson("http://{$this->host}/staff/{$link->token}/requests/{$request->id}/claim", [], ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->assertJsonPath('assignee_name', 'Floor – Ama')
        ->assertJsonPath('status', EventServiceRequest::STATUS_ACKNOWLEDGED);

    $this->patchJson("http://{$this->host}/staff/{$link->token}/requests/{$request->id}", ['status' => 'resolved'], ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->assertJsonPath('status', EventServiceRequest::STATUS_RESOLVED);

    expect($request->fresh()->assigned_staff_link_id)->toBe($link->id);
});

test('a staff link may not cancel a request, only take and finish it', function (): void {
    $link = staffLinkFor($this->tenant, $this->event, ['can_handle_requests' => true]);
    $request = EventServiceRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'registration_id' => confirmedGuest($this->tenant, $this->event)->id,
    ]);

    $this->patchJson("http://{$this->host}/staff/{$link->token}/requests/{$request->id}", ['status' => 'cancelled'], ['HTTP_HOST' => $this->host])
        ->assertUnprocessable();
});

test('a switched-off link and a link for a finished event stop working', function (): void {
    $revoked = staffLinkFor($this->tenant, $this->event, ['revoked_at' => now()]);
    $pastEvent = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(2),
    ]);
    $expired = staffLinkFor($this->tenant, $pastEvent);

    foreach ([$revoked, $expired] as $link) {
        $this->get("http://{$this->host}/staff/{$link->token}", ['HTTP_HOST' => $this->host])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Public/Staff/Show')->where('state', 'closed'));

        $this->getJson("http://{$this->host}/staff/{$link->token}/checkin/search?q=a", ['HTTP_HOST' => $this->host])
            ->assertStatus(410);
    }
});

test('a link is not found on another organizer’s subdomain', function (): void {
    $link = staffLinkFor($this->tenant, $this->event);
    Tenant::factory()->create(['slug' => 'othercrew', 'isolation_mode' => 'shared']);
    $otherHost = 'othercrew.'.mb_ltrim((string) config('session.domain'), '.');

    $this->getJson("http://{$otherHost}/staff/{$link->token}/checkin/search?q=a", ['HTTP_HOST' => $otherHost])
        ->assertNotFound();
});

test('a PIN must be entered on the phone before the link works, and changing it locks phones out', function (): void {
    $link = staffLinkFor($this->tenant, $this->event);
    $link->setPin('4821');
    $link->save();
    $base = "http://{$this->host}/staff/{$link->token}";

    $this->get($base, ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page->where('state', 'pin'));
    $this->getJson("{$base}/checkin/search?q=a", ['HTTP_HOST' => $this->host])->assertForbidden();

    $this->post("{$base}/unlock", ['pin' => '0000'], ['HTTP_HOST' => $this->host])->assertSessionHasErrors('pin');
    $this->post("{$base}/unlock", ['pin' => '4821'], ['HTTP_HOST' => $this->host])->assertRedirect();

    $this->get($base, ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page->where('state', 'ready'));
    $this->getJson("{$base}/checkin/search?q=a", ['HTTP_HOST' => $this->host])->assertOk();

    $link->setPin('9999');
    $link->save();
    $this->getJson("{$base}/checkin/search?q=a", ['HTTP_HOST' => $this->host])->assertForbidden();
});

test('an organizer switches a link off', function (): void {
    $organizer = staffOrganizer($this->tenant);
    $link = staffLinkFor($this->tenant, $this->event);

    $this->actingAs($organizer)
        ->deleteJson("http://{$this->host}/events/{$this->event->id}/staff-links/{$link->id}", [], ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->assertJsonPath('is_active', false);

    expect($link->fresh()->revoked_at)->not->toBeNull();
});

test('the Event Staff role scans and answers requests but cannot edit the event or hand out links', function (): void {
    $staff = staffOrganizer($this->tenant, 'Event Staff');
    $guest = confirmedGuest($this->tenant, $this->event);
    $request = EventServiceRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'registration_id' => $guest->id,
    ]);

    $this->actingAs($staff)
        ->postJson("http://{$this->host}/events/{$this->event->id}/checkin/scan", ['token' => $guest->qr_token], ['HTTP_HOST' => $this->host])
        ->assertOk();

    $this->actingAs($staff)
        ->patchJson("http://{$this->host}/events/{$this->event->id}/service-requests/{$request->id}/claim", [], ['HTTP_HOST' => $this->host])
        ->assertOk();

    expect($staff->can('update event'))->toBeFalse();

    $this->actingAs($staff)
        ->postJson("http://{$this->host}/events/{$this->event->id}/staff-links", [
            'name' => 'Sneaky', 'can_check_in' => true, 'can_handle_requests' => false,
        ], ['HTTP_HOST' => $this->host])
        ->assertForbidden();
});
