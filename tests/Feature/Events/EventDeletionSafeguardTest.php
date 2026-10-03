<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function deletionSafeguardHost(string $slug = 'acme'): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('event deletion requires typing the exact event title', function () {
    [$tenant, $user] = deletionSafeguardHost();
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Accra Tech Summit 2026',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // Missing confirm_name
    $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}", [], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['confirm_name']);

    // Mismatched confirm_name
    $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}", ['confirm_name' => 'Wrong Name'], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['confirm_name']);

    // Correct confirm_name
    $response = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}", ['confirm_name' => 'Accra Tech Summit 2026'], ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'recovery_window_human']);

    // Event should be soft-deleted and purge_at should be roughly 6 hours from now
    $event->refresh();
    expect($event->trashed())->toBeTrue()
        ->and($event->deleted_at)->not->toBeNull()
        ->and($event->purge_at)->not->toBeNull()
        ->and($event->purge_at->isFuture())->toBeTrue()
        ->and(abs((int) round(now()->diffInHours($event->purge_at))))->toBe(6);
});

test('event deletion is blocked when in-flight financial payouts exist', function () {
    [$tenant, $user] = deletionSafeguardHost();
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Finance Block Summit',
    ]);

    // Create an in-flight payout awaiting OTP via factory
    EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 500000,
        'net_paid_amount' => 490000,
        'status' => EventPayout::STATUS_AWAITING_OTP,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}", ['confirm_name' => 'Finance Block Summit'], ['HTTP_HOST' => $host]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['event']);

    $event->refresh();
    expect($event->trashed())->toBeFalse();
});

test('event deletion is blocked when registrations are awaiting offline proof verification', function () {
    [$tenant, $user] = deletionSafeguardHost();
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Offline Block Gathering',
    ]);

    // Create a registration awaiting offline verification
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
        'offline_payment_status' => EventRegistration::OFFLINE_STATUS_PENDING_VERIFICATION,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}", ['confirm_name' => 'Offline Block Gathering'], ['HTTP_HOST' => $host]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['event']);

    $event->refresh();
    expect($event->trashed())->toBeFalse();
});

test('soft-deleted events are excluded from active index and exposed in recentlyDeletedEvents', function () {
    [$tenant, $user] = deletionSafeguardHost();
    $liveEvent = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'Live Gathering']);
    $deletedEvent = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'Deleted Gathering']);

    $deletedEvent->update(['purge_at' => now()->addHours(6)]);
    $deletedEvent->delete();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)
        ->get("http://{$host}/events", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.name', 'Live Gathering')
            ->has('recentlyDeletedEvents', 1)
            ->where('recentlyDeletedEvents.0.name', 'Deleted Gathering')
            ->where('recentlyDeletedEvents.0.id', $deletedEvent->id)
            ->has('recentlyDeletedEvents.0.remaining_human')
        );
});

test('1-click restore recovers a soft-deleted event and clears purge_at', function () {
    [$tenant, $user] = deletionSafeguardHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'Recoverable Event']);

    $event->update(['purge_at' => now()->addHours(6)]);
    $event->delete();
    expect($event->fresh()->trashed())->toBeTrue();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/restore", [], ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJsonPath('event.name', 'Recoverable Event');

    $event->refresh();
    expect($event->trashed())->toBeFalse()
        ->and($event->deleted_at)->toBeNull()
        ->and($event->purge_at)->toBeNull();
});

test('force purge permanently erases the event record when title is confirmed and no payouts in flight', function () {
    [$tenant, $user] = deletionSafeguardHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'To Be Erased']);

    $event->update(['purge_at' => now()->addHours(6)]);
    $event->delete();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // Force purge with incorrect title fails
    $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/force-purge", ['confirm_name' => 'Wrong'], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    // Force purge with exact title succeeds
    $response = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/force-purge", ['confirm_name' => 'To Be Erased'], ['HTTP_HOST' => $host]);

    $response->assertOk();

    expect(Event::withTrashed()->find($event->id))->toBeNull();
});

test('force purge is blocked when in-flight payouts exist', function () {
    [$tenant, $user] = deletionSafeguardHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'Payout Protected']);

    EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventPayout::STATUS_PROCESSING,
    ]);

    $event->update(['purge_at' => now()->addHours(6)]);
    $event->delete();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/force-purge", ['confirm_name' => 'Payout Protected'], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['event']);

    expect(Event::withTrashed()->find($event->id))->not->toBeNull();
});

test('unique slug generation does not collide with soft-deleted events', function () {
    [$tenant, $user] = deletionSafeguardHost();
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Same Title Gathering',
        'slug' => 'same-title-gathering',
    ]);

    $event->update(['purge_at' => now()->addHours(6)]);
    $event->delete();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // Create a new event with identical title
    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Same Title Gathering',
        'status' => Event::STATUS_PUBLISHED,
        'starts_at' => now()->addDays(7)->toIso8601String(),
        'ends_at' => now()->addDays(8)->toIso8601String(),
        'ticket_price' => 0,
        'currency' => 'GHS',
        'capacity' => 100,
        'timezone' => 'UTC',
        'location_type' => 'in_person',
        'address' => 'Accra',
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();

    $newEvent = Event::where('tenant_id', $tenant->id)->latest('created_at')->first();
    expect($newEvent->slug)->toBe('same-title-gathering-1');
});

test('public event page renders Suspended view with 410 Gone during 6-hour recovery window for published events', function () {
    [$tenant] = deletionSafeguardHost();
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'suspended-gathering',
        'name' => 'Suspended Gathering',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // When active, returns 200 OK
    $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertOk();

    // Move to recovery window (soft deleted)
    $event->update(['purge_at' => now()->addHours(6)]);
    $event->delete();

    // During recovery window, returns 410 Gone with Suspended view
    $response = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host]);
    $response->assertStatus(SymfonyResponse::HTTP_GONE)
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/Suspended')
            ->where('event.name', 'Suspended Gathering')
        );
});

test('public event page returns 404 for draft unpublished events when soft-deleted', function () {
    [$tenant] = deletionSafeguardHost();
    $draftEvent = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => Event::STATUS_DRAFT,
        'slug' => 'secret-internal-draft',
        'name' => 'Secret Internal Draft',
    ]);

    $draftEvent->update(['purge_at' => now()->addHours(6)]);
    $draftEvent->delete();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // Must return 404 so confidential draft details are never leaked
    $this->get("http://{$host}/e/{$draftEvent->slug}", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('public event page returns 404 once purge window has elapsed', function () {
    [$tenant] = deletionSafeguardHost();
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'past-purge-gathering',
    ]);

    // Purge window in the past
    $event->update(['purge_at' => now()->subMinutes(10)]);
    $event->delete();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('artisan events:purge-deleted purges expired events while preserving active recovery events', function () {
    [$tenant] = deletionSafeguardHost();

    // Event 1: in recovery window (purge_at in 4 hours)
    $activeRecoveryEvent = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Active Recovery Event',
    ]);
    $activeRecoveryEvent->update(['purge_at' => now()->addHours(4)]);
    $activeRecoveryEvent->delete();

    // Event 2: expired recovery window (purge_at was 10 minutes ago)
    $expiredEvent = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Expired Purge Event',
    ]);
    $expiredEvent->update(['purge_at' => now()->subMinutes(10)]);
    $expiredEvent->delete();

    // Test dry-run does not delete
    Artisan::call('events:purge-deleted', ['--dry-run' => true]);
    expect(Event::withTrashed()->find($expiredEvent->id))->not->toBeNull();

    // Run real purge
    Artisan::call('events:purge-deleted');

    // Expired event should be permanently deleted from DB
    expect(Event::withTrashed()->find($expiredEvent->id))->toBeNull();

    // Active recovery event should still be intact in soft-deleted state
    expect(Event::withTrashed()->find($activeRecoveryEvent->id))->not->toBeNull()
        ->and($activeRecoveryEvent->fresh()->trashed())->toBeTrue();
});

test('cross-tenant isolation prevents tenant A from deleting or restoring tenant B event', function () {
    [$tenantA, $userA] = deletionSafeguardHost('tenant-a');
    [$tenantB, $userB] = deletionSafeguardHost('tenant-b');

    $eventB = Event::factory()->published()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Conference',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $hostA = "tenant-a.{$baseDomain}";

    // User A cannot delete Tenant B's event
    $this->actingAs($userA)
        ->deleteJson("http://{$hostA}/events/{$eventB->id}", ['confirm_name' => 'Tenant B Conference'], ['HTTP_HOST' => $hostA])
        ->assertNotFound();

    // Soft delete event B under Tenant B
    $eventB->update(['purge_at' => now()->addHours(6)]);
    $eventB->delete();

    // User A cannot restore Tenant B's event
    $this->actingAs($userA)
        ->postJson("http://{$hostA}/events/{$eventB->id}/restore", [], ['HTTP_HOST' => $hostA])
        ->assertNotFound();

    // User A cannot force purge Tenant B's event
    $this->actingAs($userA)
        ->deleteJson("http://{$hostA}/events/{$eventB->id}/force-purge", ['confirm_name' => 'Tenant B Conference'], ['HTTP_HOST' => $hostA])
        ->assertNotFound();
});

test('unauthorized users without delete event permission are rejected with 403', function () {
    [$tenant] = deletionSafeguardHost();
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Gated Event',
    ]);

    // Create user without roles or permissions
    $plainUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($plainUser->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($plainUser)
        ->deleteJson("http://{$host}/events/{$event->id}", ['confirm_name' => 'Gated Event'], ['HTTP_HOST' => $host])
        ->assertForbidden();

    $event->update(['purge_at' => now()->addHours(6)]);
    $event->delete();

    $this->actingAs($plainUser)
        ->postJson("http://{$host}/events/{$event->id}/restore", [], ['HTTP_HOST' => $host])
        ->assertForbidden();

    $this->actingAs($plainUser)
        ->deleteJson("http://{$host}/events/{$event->id}/force-purge", ['confirm_name' => 'Gated Event'], ['HTTP_HOST' => $host])
        ->assertForbidden();
});
