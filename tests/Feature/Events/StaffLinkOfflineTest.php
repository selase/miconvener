<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\EventRegistration;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use App\Services\Events\DoorCheckIn;
use Illuminate\Support\Str;

/**
 * A staff link's phone downloads what it needs to keep admitting guests with
 * no connection, and sends what it admitted when the connection returns.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    $this->tenant = Tenant::factory()->create(['slug' => 'offlinecrew', 'isolation_mode' => 'shared']);
    $this->host = 'offlinecrew.'.mb_ltrim((string) config('session.domain'), '.');
    $this->event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(6),
    ]);
    $this->link = EventStaffLink::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $this->base = "http://{$this->host}/staff/{$this->link->token}";
});

function offlineGuest(Event $event, array $attributes = []): EventRegistration
{
    return EventRegistration::factory()->create([
        'tenant_id' => $event->tenant_id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        ...$attributes,
    ]);
}

test('the pack lists guests with hashed QR tokens and no contact details', function (): void {
    $guest = offlineGuest($this->event);

    $pack = $this->getJson("{$this->base}/offline-pack", ['HTTP_HOST' => $this->host])->assertOk();

    $row = collect($pack->json('guests'))->firstWhere('id', $guest->id);
    expect($row['qr_hash'])->toBe(hash('sha256', $guest->qr_token))
        ->and($row)->not->toHaveKeys(['email', 'phone', 'qr_token'])
        ->and((string) json_encode($pack->json()))->not->toContain($guest->qr_token)->not->toContain($guest->email);
});

test('a transferred ticket appears in the pack under its new token', function (): void {
    $guest = offlineGuest($this->event);
    $old = hash('sha256', $guest->qr_token);
    $guest->rotateTicketCredentials();
    $guest->save();

    $hashes = collect($this->getJson("{$this->base}/offline-pack", ['HTTP_HOST' => $this->host])->json('guests'))->pluck('qr_hash');

    expect($hashes)->not->toContain($old)->toContain(hash('sha256', $guest->fresh()->qr_token));
});

test('a requests-only link gets no pack', function (): void {
    $this->link->update(['can_check_in' => false, 'can_handle_requests' => true]);

    $this->getJson("{$this->base}/offline-pack", ['HTTP_HOST' => $this->host])->assertForbidden();
});

test('synced scans are applied once, with the scan time, credited to the link', function (): void {
    $guest = offlineGuest($this->event);
    $scan = ['client_scan_id' => (string) Str::uuid(), 'registration_id' => $guest->id, 'scanned_at' => now()->subMinutes(20)->toIso8601String()];

    $first = $this->postJson("{$this->base}/checkin/sync", ['scans' => [$scan]], ['HTTP_HOST' => $this->host])->assertOk();
    $this->postJson("{$this->base}/checkin/sync", ['scans' => [$scan]], ['HTTP_HOST' => $this->host])->assertOk();

    $guest->refresh();
    expect($first->json('results.0.outcome'))->toBe('admitted')
        ->and(EventDoorScan::query()->where('registration_id', $guest->id)->count())->toBe(1)
        ->and((int) $guest->checked_in_at->diffInMinutes(now()))->toBeGreaterThanOrEqual(19)
        ->and($guest->checked_in_by_staff_link_id)->toBe($this->link->id);
});

test('a scan time in the future is clamped to now', function (): void {
    $guest = offlineGuest($this->event);

    $this->postJson("{$this->base}/checkin/sync", ['scans' => [[
        'client_scan_id' => (string) Str::uuid(),
        'registration_id' => $guest->id,
        'scanned_at' => now()->addHours(3)->toIso8601String(),
    ]]], ['HTTP_HOST' => $this->host])->assertOk();

    expect($guest->fresh()->checked_in_at->lessThanOrEqualTo(now()))->toBeTrue();
});

test('two phones on one link sync independently, and the second admission is a duplicate', function (): void {
    $guest = offlineGuest($this->event);
    $send = fn (int $minutesAgo) => $this->postJson("{$this->base}/checkin/sync", ['scans' => [[
        'client_scan_id' => (string) Str::uuid(),
        'registration_id' => $guest->id,
        'scanned_at' => now()->subMinutes($minutesAgo)->toIso8601String(),
    ]]], ['HTTP_HOST' => $this->host]);

    expect($send(10)->json('results.0.outcome'))->toBe('admitted')
        ->and($send(5)->json('results.0.outcome'))->toBe('duplicate');
});

test('a scan for a cancelled or another event\'s registration is refused, not dropped', function (): void {
    $cancelled = offlineGuest($this->event, ['status' => EventRegistration::STATUS_CANCELLED]);
    $other = offlineGuest(Event::factory()->published()->create(['tenant_id' => $this->tenant->id]));

    $results = $this->postJson("{$this->base}/checkin/sync", ['scans' => [
        ['client_scan_id' => 'a-1', 'registration_id' => $cancelled->id, 'scanned_at' => now()->toIso8601String()],
        ['client_scan_id' => 'a-2', 'registration_id' => $other->id, 'scanned_at' => now()->toIso8601String()],
    ]], ['HTTP_HOST' => $this->host])->assertOk()->json('results');

    expect(collect($results)->pluck('outcome')->all())->toBe(['refused', 'refused'])
        ->and(collect($results)->pluck('client_scan_id')->all())->toBe(['a-1', 'a-2'])
        ->and($other->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('sync returns admissions made at other doors since the last sync', function (): void {
    $since = now()->subMinute()->toIso8601String();
    $guest = offlineGuest($this->event);
    app(DoorCheckIn::class)->checkIn($guest);

    $response = $this->postJson("{$this->base}/checkin/sync", ['scans' => [], 'since' => $since], ['HTTP_HOST' => $this->host])->assertOk();

    expect(collect($response->json('admitted_since'))->pluck('registration_id'))->toContain($guest->id);
});

test('a switched-off link gets 410 for pack and sync', function (): void {
    $this->link->update(['revoked_at' => now()]);

    $this->getJson("{$this->base}/offline-pack", ['HTTP_HOST' => $this->host])->assertStatus(410);
    $this->postJson("{$this->base}/checkin/sync", ['scans' => []], ['HTTP_HOST' => $this->host])->assertStatus(410);
});

test('one link works on every day of a three-day event', function (): void {
    $this->event->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2)]);
    $this->travel(2)->days();

    $this->getJson("{$this->base}/offline-pack", ['HTTP_HOST' => $this->host])->assertOk();
});

test('a malformed scan is a validation error, not a server error', function (): void {
    $this->postJson("{$this->base}/checkin/sync", ['scans' => [[
        'client_scan_id' => 'x', 'registration_id' => 'not-a-uuid', 'scanned_at' => now()->toIso8601String(),
    ]]], ['HTTP_HOST' => $this->host])->assertUnprocessable()->assertJsonValidationErrors('scans.0.registration_id');
});
