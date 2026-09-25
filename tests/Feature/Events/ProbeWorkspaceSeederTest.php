<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantHostMatcher;
use Database\Seeders\ProbeWorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * The seeder exists so the workspace can be looked at with everything switched
 * on. That is only true if every conditional part actually turns on, so this
 * asserts it through the workspace itself rather than by counting rows.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Storage::fake(Event::uploadDisk());
});

function seedProbeWorkspace(string $email = 'probe@example.com'): array
{
    $tenant = Tenant::factory()->create(['slug' => 'probe-seeder', 'isolation_mode' => 'shared']);

    config()->set('attendee_portal.probe.tenant_slug', $tenant->slug);
    config()->set('attendee_portal.probe.attendee_email', $email);

    Artisan::call('db:seed', ['--class' => ProbeWorkspaceSeeder::class]);

    $event = Event::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('slug', ProbeWorkspaceSeeder::EVENT_SLUG)
        ->firstOrFail();

    $registration = $event->registrations()->withoutGlobalScopes()->where('email', $email)->firstOrFail();

    return [$tenant, $event, $registration];
}

function probeProof(string $email): array
{
    return [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(PlatformAttendeeVerification::VERIFIED_HOURS)->getTimestamp(),
        ],
    ];
}

test('the seeded workspace turns on every conditional part at once', function (): void {
    [, $event, $registration] = seedProbeWorkspace();
    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(probeProof('probe@example.com'))
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // My ticket, with a seat.
            ->where('registration.status', 'checked_in')
            ->where('registration.seat_label', 'C-14')
            ->where('registration.room_name', 'Main Auditorium')
            // Every contextual tab's precondition.
            ->where('has_live_poll', true)
            ->where('has_forum', true)
            ->where('has_forms', true)
            ->where('canRequestHelp', true)
            // My day, and at least one released download.
            ->has('event.sessions', 4)
            ->has('materials', 2));

    expect($event->starts_at->isPast())->toBeTrue()
        ->and($event->ends_at->isFuture())->toBeTrue();
});

test('the seeded attendee has records behind every dashboard panel', function (): void {
    [, , $registration] = seedProbeWorkspace();
    $host = app(TenantHostMatcher::class)->baseDomain();
    $session = probeProof('probe@example.com');

    $this->withSession($session)
        ->getJson("http://{$host}/my/certificates", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonCount(1);

    $this->withSession($session)
        ->getJson("http://{$host}/my/abstracts", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonCount(1);

    $this->withSession($session)
        ->getJson("http://{$host}/my/attendance", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonCount(2);

    $history = $this->withSession($session)
        ->getJson("http://{$host}/my/events", ['HTTP_HOST' => $host])
        ->assertOk()
        ->json();

    // The event is running, so it belongs in Live now.
    expect(collect($history['live_now'])->pluck('registration_id'))->toContain($registration->id);
});

test('running the seeder twice changes nothing and moves the event to the new now', function (): void {
    [$tenant, $event] = seedProbeWorkspace();
    $firstStart = $event->starts_at;

    $countsBefore = [
        'sessions' => $event->sessions()->withoutGlobalScopes()->count(),
        'materials' => $event->materials()->withoutGlobalScopes()->count(),
        'registrations' => $event->registrations()->withoutGlobalScopes()->count(),
    ];

    $this->travel(3)->hours();

    Artisan::call('db:seed', ['--class' => ProbeWorkspaceSeeder::class]);

    $event->refresh();

    expect($event->sessions()->withoutGlobalScopes()->count())->toBe($countsBefore['sessions'])
        ->and($event->materials()->withoutGlobalScopes()->count())->toBe($countsBefore['materials'])
        ->and($event->registrations()->withoutGlobalScopes()->count())->toBe($countsBefore['registrations'])
        ->and($event->starts_at->greaterThan($firstStart))->toBeTrue()
        ->and($event->ends_at->isFuture())->toBeTrue();
});

test('the withheld file stays withheld for the whole event, not just its first hours', function (): void {
    [, $event, $registration] = seedProbeWorkspace();
    $host = app(TenantHostMatcher::class)->baseDomain();

    $titles = fn (): array => collect(
        $this->withSession(probeProof('probe@example.com'))
            ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
            ->viewData('page')['props']['materials']
    )->pluck('title')->all();

    expect($titles())->toHaveCount(2)
        ->and(implode(' ', $titles()))->not->toContain('Workshop handout');

    // Walk to the last hour of the event. A fixture whose withheld file quietly
    // appears part way through its own window teaches whoever is testing that
    // the release policy is broken at exactly the moment it is working.
    $this->travelTo($event->ends_at->copy()->subMinutes(30));

    expect($titles())->toHaveCount(2)
        ->and(implode(' ', $titles()))->not->toContain('Workshop handout');

    // And it does still release eventually -- it is dated, not hidden.
    $this->travelTo($event->ends_at->copy()->addHours(2));

    expect(implode(' ', $titles()))->toContain('Workshop handout');
});

test('re-titling a fixture replaces it instead of leaving a stale copy beside it', function (): void {
    [, $event] = seedProbeWorkspace();

    $materials = fn () => EventMaterial::withoutGlobalScopes()
        ->where('event_id', $event->id)
        ->get();

    expect($materials())->toHaveCount(3);

    // Stand in for a renamed fixture: the row the seeder would previously have
    // orphaned, sharing a path with the one it is about to write.
    $handout = $materials()->firstWhere('title', 'Workshop handout (released after the event)');
    $handout->update(['title' => 'Workshop handout (some older wording)']);

    Artisan::call('db:seed', ['--class' => ProbeWorkspaceSeeder::class]);

    $after = $materials();

    expect($after)->toHaveCount(3)
        ->and($after->pluck('file_path')->unique())->toHaveCount(3)
        ->and($after->pluck('title'))->toContain('Workshop handout (released after the event)')
        ->and($after->pluck('title'))->not->toContain('Workshop handout (some older wording)');
});
