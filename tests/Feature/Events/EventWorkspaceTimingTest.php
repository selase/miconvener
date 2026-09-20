<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * The menu never reorders itself, so the event's timing shows up in the badges
 * and in what the overview leads with: approvals and readiness before the
 * doors open, arrivals and help requests on the day, certificates afterwards.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * @return array{0: Tenant, 1: User, 2: Event, 3: string}
 */
function timingSetup(string $when, array|string $roleOrPermissions = 'Org Superadmin', string $slug = 'timing'): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    setPermissionsTeamId($tenant->id);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    if (is_string($roleOrPermissions)) {
        $user->assignRole($roleOrPermissions);
    } else {
        $role = Role::create(['name' => 'Desk', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
        $role->givePermissionTo($roleOrPermissions);
        $user->assignRole($role);
    }
    $tenant->users()->attach($user->id);

    [$starts, $ends] = match ($when) {
        'live' => [now()->subHours(2), now()->addHours(6)],
        'after' => [now()->subDays(5), now()->subDays(4)],
        default => [now()->addDays(14), now()->addDays(16)],
    };

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => $starts,
        'ends_at' => $ends,
    ]);

    return [$tenant, $user, $event, "{$slug}.".mb_ltrim((string) config('session.domain'), '.')];
}

function registrationFor(Tenant $tenant, Event $event, string $status, array $extra = []): EventRegistration
{
    return EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => $status,
        ...$extra,
    ]);
}

test('the workspace knows whether the event is coming, running or done', function (string $when, string $expected) {
    [, $user, $event, $host] = timingSetup($when);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('phase', $expected));
})->with([
    'two weeks out' => ['before', 'before'],
    'during the event' => ['live', 'live'],
    'once it has ended' => ['after', 'after'],
]);

test('guests carries a badge while people are waiting for approval', function () {
    [$tenant, $user, $event, $host] = timingSetup('before');
    registrationFor($tenant, $event, EventRegistration::STATUS_PENDING_APPROVAL);
    registrationFor($tenant, $event, EventRegistration::STATUS_PENDING_APPROVAL);
    registrationFor($tenant, $event, EventRegistration::STATUS_CONFIRMED);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('badges.guests.label', 2)
            ->where('badges.guests.kind', 'attention'));
});

test('check-in is marked live only while the event is running', function () {
    [, $user, $event, $host] = timingSetup('live');
    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('badges.check-in.kind', 'live'));

    [, $user2, $event2, $host2] = timingSetup('before', 'Org Superadmin', 'timing-before');
    $this->actingAs($user2)
        ->get("http://{$host2}/events/{$event2->id}", ['HTTP_HOST' => $host2])
        ->assertInertia(fn ($page) => $page->missing('badges.check-in'));
});

test('a badge is not shown for a section the user cannot open', function () {
    // Someone who cannot open check-in has no use for a live marker on it.
    [$tenant, $user, $event, $host] = timingSetup('live', ['read event']);
    registrationFor($tenant, $event, EventRegistration::STATUS_PENDING_APPROVAL);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->missing('badges.check-in')
            ->where('badges.guests.label', 1));
});

test('before the doors open the overview leads with approvals and what is not ready', function () {
    [$tenant, $user, $event, $host] = timingSetup('before');
    registrationFor($tenant, $event, EventRegistration::STATUS_PENDING_APPROVAL, ['full_name' => 'Ama Boateng']);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('overview.phase', 'before')
            ->where('overview.waiting_approval.0.full_name', 'Ama Boateng')
            ->where('overview.readiness', function ($items): bool {
                $keys = collect($items)->pluck('key')->all();

                // The schedule has no sessions, so that check is outstanding
                // and points at the section that fixes it.
                $schedule = collect($items)->firstWhere('key', 'schedule');

                return in_array('tickets', $keys, true)
                    && $schedule['done'] === false
                    && $schedule['section'] === 'schedule';
            }));
});

test('on the day the overview leads with arrivals and open help requests', function () {
    [$tenant, $user, $event, $host] = timingSetup('live');
    registrationFor($tenant, $event, EventRegistration::STATUS_CHECKED_IN, ['checked_in_at' => now()]);
    registrationFor($tenant, $event, EventRegistration::STATUS_CONFIRMED);
    EventServiceRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventServiceRequest::STATUS_OPEN,
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('overview.phase', 'live')
            ->where('overview.checked_in', 1)
            ->where('overview.expected', 2)
            ->has('overview.open_requests', 1)
            ->where('overview.can_check_in', true)
            ->where('badges.help-requests.label', 1));
});

test('afterwards the overview leads with attendance and what is left to wrap up', function () {
    [$tenant, $user, $event, $host] = timingSetup('after');
    registrationFor($tenant, $event, EventRegistration::STATUS_CHECKED_IN, ['checked_in_at' => now()->subDays(5)]);
    registrationFor($tenant, $event, EventRegistration::STATUS_CONFIRMED);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('overview.phase', 'after')
            ->where('overview.attended', 1)
            ->where('overview.expected', 2)
            ->where('overview.wrap_up', fn ($items): bool => collect($items)->firstWhere('key', 'certificates')['done'] === false)
            ->where('badges.certificates.label', 'To issue'));
});

test('the certificates badge clears once they have been issued', function () {
    [$tenant, $user, $event, $host] = timingSetup('after');
    $attendee = registrationFor($tenant, $event, EventRegistration::STATUS_CHECKED_IN, ['checked_in_at' => now()->subDays(5)]);
    EventCertificate::query()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $attendee->id,
        'recipient_name' => $attendee->full_name,
        'recipient_email' => $attendee->email,
        'verification_code' => 'CERT-TEST-0001',
        'issued_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->missing('badges.certificates'));
});

test('door mode opens check-in on its own, with the count, for someone who can scan', function () {
    [$tenant, $user, $event, $host] = timingSetup('live');
    registrationFor($tenant, $event, EventRegistration::STATUS_CHECKED_IN, ['checked_in_at' => now()]);
    registrationFor($tenant, $event, EventRegistration::STATUS_CONFIRMED);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}/check-in/door", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Events/CheckInDoor')
            ->where('counts.checked_in', 1)
            ->where('counts.expected', 2));
});

test('door mode is refused to someone who cannot check people in', function () {
    [, $user, $event, $host] = timingSetup('live', ['read event']);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}/check-in/door", ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('door mode does not open another organization\'s event', function () {
    [, $user, , $host] = timingSetup('live');
    $other = Tenant::factory()->create(['slug' => 'not-ours', 'isolation_mode' => 'shared']);
    $theirs = Event::factory()->create(['tenant_id' => $other->id]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$theirs->id}/check-in/door", ['HTTP_HOST' => $host])
        ->assertNotFound();
});
