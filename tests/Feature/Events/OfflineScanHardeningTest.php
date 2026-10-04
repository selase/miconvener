<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\EventRegistration;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use App\Services\Events\DoorCheckIn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Failure modes found in review: scans lost, double-counted, credited to the
 * wrong person or the wrong day, and phones locked out mid-shift.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    $this->tenant = Tenant::factory()->create(['slug' => 'hardening', 'isolation_mode' => 'shared']);
    $this->host = 'hardening.'.mb_ltrim((string) config('session.domain'), '.');
    $this->event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'timezone' => 'Africa/Accra',
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addHours(4),
    ]);
    $this->link = EventStaffLink::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $this->base = "http://{$this->host}/staff/{$this->link->token}";
    $this->guest = EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'full_name' => 'Ama Owusu',
    ]);
});

function syncScans(object $test, array $scans, array $extra = [])
{
    return $test->postJson("{$test->base}/checkin/sync", ['scans' => $scans, ...$extra], ['HTTP_HOST' => $test->host]);
}

test('scans made before a link was switched off still sync; later ones are refused', function (): void {
    $before = ['client_scan_id' => 'c-1', 'registration_id' => $this->guest->id, 'scanned_at' => now()->subMinutes(30)->toIso8601String()];
    $this->link->update(['revoked_at' => now()->subMinutes(10)]);
    $other = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'confirmed']);
    $after = ['client_scan_id' => 'c-2', 'registration_id' => $other->id, 'scanned_at' => now()->subMinutes(5)->toIso8601String()];

    $results = syncScans($this, [$before, $after])->assertOk()->json('results');

    expect($results[0]['outcome'])->toBe('admitted')
        ->and($results[1]['outcome'])->toBe('refused')
        ->and($this->getJson("{$this->base}/offline-pack", ['HTTP_HOST' => $this->host])->status())->toBe(410);
});

test('a live scan that timed out on the phone and was queued is not counted twice', function (): void {
    $id = (string) Str::uuid();

    $this->postJson("{$this->base}/checkin/scan", ['token' => $this->guest->qr_token, 'client_scan_id' => $id], ['HTTP_HOST' => $this->host])->assertOk();
    $synced = syncScans($this, [['client_scan_id' => $id, 'registration_id' => $this->guest->id, 'scanned_at' => now()->toIso8601String()]]);

    expect($synced->json('results.0.outcome'))->toBe('admitted')
        ->and(EventDoorScan::query()->where('registration_id', $this->guest->id)->count())->toBe(1);
});

test('an offline scan of a ticket transferred meanwhile is refused, not credited to the new holder', function (): void {
    $oldHash = hash('sha256', $this->guest->qr_token);
    $this->guest->rotateTicketCredentials();
    $this->guest->save();

    $result = syncScans($this, [[
        'client_scan_id' => 'c-9', 'registration_id' => $this->guest->id,
        'scanned_at' => now()->subMinute()->toIso8601String(), 'qr_hash' => $oldHash,
    ]])->json('results.0');

    expect($result['outcome'])->toBe('refused')
        ->and($this->guest->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test("a phone's clock is corrected by its offset from the server", function (): void {
    // The phone is two hours slow: it thinks it is two hours earlier.
    $phoneNow = now()->subHours(2);

    syncScans($this, [[
        'client_scan_id' => 'c-3', 'registration_id' => $this->guest->id,
        'scanned_at' => $phoneNow->copy()->subMinutes(10)->toIso8601String(),
    ]], ['sent_at' => $phoneNow->toIso8601String()])->assertOk();

    expect((int) round(abs($this->guest->fresh()->checked_in_at->diffInMinutes(now()))))->toBe(10);
});

test('a scan dated long before the event is pulled to the event, not 1970', function (): void {
    syncScans($this, [[
        'client_scan_id' => 'c-4', 'registration_id' => $this->guest->id,
        'scanned_at' => '1970-01-01T00:00:00Z',
    ]])->assertOk();

    expect($this->guest->fresh()->checked_in_at->greaterThan($this->event->starts_at->copy()->subDay()))->toBeTrue();
});

test('a PIN unlock survives the session ending, and a new PIN locks it again', function (): void {
    $this->link->setPin('4821');
    $this->link->save();

    $unlock = $this->post("{$this->base}/unlock", ['pin' => '4821'], ['HTTP_HOST' => $this->host])->assertRedirect();
    $cookie = collect($unlock->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'staff_unlock_'));
    expect($cookie)->not->toBeNull();

    $this->flushSession();
    $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
        ->getJson("{$this->base}/checkin/search?q=a", ['HTTP_HOST' => $this->host])->assertOk();

    $this->link->setPin('9999');
    $this->link->save();
    $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
        ->getJson("{$this->base}/checkin/search?q=a", ['HTTP_HOST' => $this->host])->assertForbidden();
});

test('only a ready staff page is marked cacheable for offline use', function (): void {
    $this->get($this->base, ['HTTP_HOST' => $this->host])->assertHeader('X-Staff-State', 'ready');

    $this->link->setPin('4821');
    $this->link->save();
    $this->flushSession();

    $this->get($this->base, ['HTTP_HOST' => $this->host])->assertHeader('X-Staff-State', 'pin');
});

test("a guest checked in earlier who is scanned again stays in today's headcount", function (): void {
    $this->guest->update(['status' => EventRegistration::STATUS_CHECKED_IN, 'checked_in_at' => now()->subMinutes(30)]);
    $door = app(DoorCheckIn::class);

    $door->checkIn($this->guest->fresh()); // already in: logged as already_in

    expect($door->counts($this->event)['checked_in'])->toBe(1);
});

test('an evening event that runs past midnight is one day', function (): void {
    $event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'timezone' => 'Africa/Accra',
        'starts_at' => Carbon::parse('2026-11-06 20:00', 'Africa/Accra'),
        'ends_at' => Carbon::parse('2026-11-07 02:00', 'Africa/Accra'),
    ]);
    $guest = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $event->id, 'status' => 'confirmed']);
    $door = app(DoorCheckIn::class);

    $this->travelTo(Carbon::parse('2026-11-06 23:00', 'Africa/Accra'));
    $door->checkIn($guest);
    $this->travelTo(Carbon::parse('2026-11-07 00:30', 'Africa/Accra'));

    expect($door->checkIn($guest->fresh())['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ALREADY_IN)
        ->and($door->counts($event)['checked_in'])->toBe(1)
        ->and($event->isMultiDay())->toBeFalse();

    $link = EventStaffLink::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $event->id]);
    $pack = $this->getJson("http://{$this->host}/staff/{$link->token}/offline-pack", ['HTTP_HOST' => $this->host])->assertOk();
    expect($pack->json('day'))->toBe('2026-11-06')
        ->and($pack->json('multi_day'))->toBeFalse()
        ->and(collect($pack->json('guests'))->firstWhere('id', $guest->id)['in_today_at'])->not->toBeNull();
});

test('a refused sync says whose ticket it was', function (): void {
    $this->guest->update(['status' => EventRegistration::STATUS_CANCELLED]);

    $message = syncScans($this, [['client_scan_id' => 'c-5', 'registration_id' => $this->guest->id, 'scanned_at' => now()->toIso8601String()]])
        ->json('results.0.message');

    expect($message)->toContain('Ama Owusu')->toContain($this->guest->ticket_code);
});

test('a scan before the first day is labelled day 1, never day 0', function (): void {
    $event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'timezone' => 'Africa/Accra',
        'starts_at' => Carbon::parse('2026-11-02 08:00', 'Africa/Accra'),
        'ends_at' => Carbon::parse('2026-11-04 17:00', 'Africa/Accra'),
    ]);

    expect($event->dayNumber('2026-11-01'))->toBe(1)
        ->and($event->dayNumber('2026-11-03'))->toBe(2);
});

test('a sync batch looks its guests up in one query, not one per scan', function (): void {
    $guests = EventRegistration::factory()->count(20)->create([
        'tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'confirmed',
    ]);
    $scans = $guests->map(fn (EventRegistration $guest): array => [
        'client_scan_id' => (string) Str::uuid(), 'registration_id' => $guest->id, 'scanned_at' => now()->toIso8601String(),
    ])->all();

    \Illuminate\Support\Facades\DB::connection('landlord')->enableQueryLog();
    syncScans($this, $scans)->assertOk();
    $lookups = collect(\Illuminate\Support\Facades\DB::connection('landlord')->getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "event_registrations"') && str_contains($q['query'], '"id" in'))
        ->count();

    expect($lookups)->toBe(1);
});

test('the offline worker is served uncached, so a deploy reaches phones at once', function (): void {
    $response = $this->get("http://{$this->host}/staff-worker.js", ['HTTP_HOST' => $this->host])->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('javascript')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe('/staff/')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toContain('miconvener-staff-v');
});
