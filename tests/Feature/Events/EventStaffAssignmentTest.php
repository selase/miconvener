<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Event Staff can be limited to the events they work. With no events chosen
 * they keep access to every event (as before); once events are chosen, every
 * other event is out of reach, from the list, its pages and its live feed.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->tenant = Tenant::factory()->create(['slug' => 'crewco', 'isolation_mode' => 'shared']);
    $this->host = 'crewco.'.mb_ltrim((string) config('session.domain'), '.');
    $this->summit = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'name' => 'Health Summit']);
    $this->gala = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'name' => 'Awards Gala']);
});

function crewMember(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    $tenant->users()->attach($user->id);

    return $user;
}

function assignCrew(User $user, Event $event): void
{
    EventStaffAssignment::create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'user_id' => $user->id]);
}

test('event staff assigned to one event can open it, but not another', function (): void {
    $staff = crewMember($this->tenant, 'Event Staff');
    assignCrew($staff, $this->summit);

    $this->actingAs($staff)->get("http://{$this->host}/events/{$this->summit->id}/check-in")->assertOk();
    $this->actingAs($staff)->get("http://{$this->host}/events/{$this->gala->id}/check-in")->assertNotFound();
    $this->actingAs($staff)->get("http://{$this->host}/events/{$this->gala->slug}")->assertNotFound();
});

test('assigned event staff see only their events in the list', function (): void {
    $staff = crewMember($this->tenant, 'Event Staff');
    assignCrew($staff, $this->summit);

    $this->actingAs($staff)->get("http://{$this->host}/events")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('events', fn ($events): bool => collect($events)->pluck('id')->all() === [$this->summit->id]));
});

test('event staff with no events chosen keep access to every event', function (): void {
    $staff = crewMember($this->tenant, 'Event Staff');

    $this->actingAs($staff)->get("http://{$this->host}/events/{$this->gala->id}/check-in")->assertOk();
});

test('assignments never limit an organiser or admin', function (): void {
    $admin = crewMember($this->tenant, 'Org Admin');
    assignCrew($admin, $this->summit);

    $this->actingAs($admin)->get("http://{$this->host}/events/{$this->gala->id}/check-in")->assertOk();
});

test('the live request feed is closed to staff not assigned to the event', function (): void {
    $staff = crewMember($this->tenant, 'Event Staff');
    assignCrew($staff, $this->summit);

    expect(\App\Services\Events\EventStaffScope::canAccessEvent($staff, $this->summit))->toBeTrue()
        ->and(\App\Services\Events\EventStaffScope::canAccessEvent($staff, $this->gala))->toBeFalse();
});

test('the organiser picks the events when editing an Event Staff member, and only their own', function (): void {
    $owner = crewMember($this->tenant, 'Org Superadmin');
    $staff = crewMember($this->tenant, 'Event Staff');
    $other = Event::factory()->create(['tenant_id' => Tenant::factory()->create(['isolation_mode' => 'shared'])->id]);
    $role = Role::where('name', 'Event Staff')->whereNull('tenant_id')->firstOrFail();
    $payload = [
        'first_name' => $staff->first_name,
        'last_name' => $staff->last_name,
        'email' => $staff->email,
        'phone_no' => '0241234567',
        'status' => $staff->status ?? 'active',
        'role' => $role->id,
    ];

    $this->actingAs($owner)->put("http://{$this->host}/users/{$staff->uuid}", $payload + ['event_ids' => [$other->id]])
        ->assertSessionHasErrors('event_ids.0');

    $this->actingAs($owner)->put("http://{$this->host}/users/{$staff->uuid}", $payload + ['event_ids' => [$this->summit->id]])
        ->assertSessionHasNoErrors();
    expect(EventStaffAssignment::where('user_id', $staff->id)->pluck('event_id')->all())->toBe([$this->summit->id]);

    // Moving them to another role clears the limit, so it can't linger unseen.
    $admin = Role::where('name', 'Org Admin')->whereNull('tenant_id')->firstOrFail();
    $this->actingAs($owner)->put("http://{$this->host}/users/{$staff->uuid}", ['role' => $admin->id] + $payload)
        ->assertSessionHasNoErrors();
    expect(EventStaffAssignment::where('user_id', $staff->id)->count())->toBe(0);
});

test('a new Event Staff member can be limited to events as they are added', function (): void {
    \Illuminate\Support\Facades\Mail::fake();
    $owner = crewMember($this->tenant, 'Org Superadmin');
    $role = Role::where('name', 'Event Staff')->whereNull('tenant_id')->firstOrFail();

    $this->actingAs($owner)->post("http://{$this->host}/users", [
        'first_name' => 'Kojo',
        'last_name' => 'Usher',
        'email' => 'kojo@crewco.test',
        'phone_no' => '0241234567',
        'role' => $role->id,
        'status' => 'active',
        'event_ids' => [$this->gala->id],
    ])->assertSessionHasNoErrors();

    $kojo = User::where('email', 'kojo@crewco.test')->firstOrFail();
    expect(EventStaffAssignment::where('user_id', $kojo->id)->pluck('event_id')->all())->toBe([$this->gala->id]);
});
