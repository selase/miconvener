# Offline Scanning on Staff Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Staff links keep admitting guests when the internet fails, with per-day entry for multi-day events, and every offline scan reaches the server on reconnect, credited to its usher.

**Architecture:** Every door scan — live or synced — goes through `App\Services\Events\DoorCheckIn`, which now writes an `event_door_scans` log and decides admission per event day. Staff links gain an offline pack (hashed QR tokens) and an idempotent sync endpoint. On the phone, a `staffDoor` library tries the server first (3 s timeout) and falls back to the stored pack, queueing admissions; a service worker keeps the staff page openable offline.

**Tech Stack:** Laravel 13 / PHP 8.5, PostgreSQL, Pest 5; React 19 + Inertia 3, Vitest (jsdom); Web Crypto (`crypto.subtle`), IndexedDB, Service Worker. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-04-offline-staff-scanning-design.md`

## Global Constraints

- Online first: the phone uses the stored pack only when the server request fails or gives no answer within **3000 ms**.
- The pack never contains a raw `qr_token`: only `qr_hash = sha256(qr_token)` (lowercase hex). No emails or phone numbers in the pack.
- An event **day** is the calendar date in `events.timezone` (default `Africa/Accra`). Single-day events behave as before.
- Door scan outcomes, exactly: `admitted`, `already_in`, `duplicate`, `refused`.
  - `admitted` — first admission of that registration that day.
  - `already_in` — a live scan refused because they were already admitted that day (amber).
  - `duplicate` — an offline admission that, on sync, turned out to be the second that day (the guest was let in).
  - `refused` — not a confirmed/checked-in registration.
- "Used twice" = a registration with **≥ 2 scans whose outcome is `admitted` or `duplicate`** on the same day.
- `registrations.checked_in_at` / status keep meaning *first arrival at the event*; a synced admission uses the scan time, never the sync time, and an earlier scan time wins.
- Scan time from a phone is clamped to the server's now (no future times).
- Stored phone data is deleted 12 hours after the event ends (`EventStaffLink::HOURS_AFTER_EVENT`), and immediately on a 410 from the server.
- Scope: staff links only. Not built: door assignment, rotating QR codes, offline console scanner, room-by-room staff scanning.
- Local toolchain: `PHP="$HOME/Library/Application Support/Herd/bin/php"`; Node via `/Users/selase/.nvm/versions/node/v24.16.0/bin`. Run `vendor/bin/pint --dirty` before committing PHP.

## Review Focus

1. **A phone with a wrong clock** — scan time in the future must be clamped to server now (test in Task 2).
2. **A ticket transferred while a phone was offline** — the old QR is still in a stale pack; on reconnect the pack refresh must replace the hash so the old screenshot stops admitting (test in Task 2: pack reflects rotated token).
3. **An event in another timezone** — the day boundary follows `events.timezone`, not the server's UTC (test in Task 1 with `Europe/London` near midnight).
4. **The same link open on two phones** — `client_scan_id`s are UUIDs per scan, so both phones' scans sync independently (test in Task 2).
5. **A cancelled registration admitted offline** — sync reports `refused` and the usher sees it (tests in Tasks 2 and 4).

---

### Task 1: Door scan log and per-day admission

**Files:**
- Create: `database/migrations/landlord/2026_10_04_130000_create_event_door_scans_table.php`
- Create: `app/Models/EventDoorScan.php`
- Modify: `app/Services/Events/DoorCheckIn.php`
- Modify: `app/Http/Controllers/Tenant/EventController.php` (method `door`, counts)
- Modify: `app/Models/Event.php` (relation `doorScans`, helpers `dayFor`, `isMultiDay`, `dayNumber`)
- Test: `tests/Feature/Events/DoorScanDaysTest.php`

**Interfaces:**
- Produces: `DoorCheckIn::checkIn(EventRegistration $registration, ?User $user = null, ?EventStaffLink $staffLink = null, ?CarbonInterface $scannedAt = null, ?string $clientScanId = null, bool $wasOffline = false): array{status:int, body:array<string,mixed>}` — body always includes `outcome` (one of the four constants).
- Produces: `DoorCheckIn::counts(Event $event): array{checked_in:int, expected:int, day:int, is_multi_day:bool}` — `checked_in` is today's.
- Produces: `Event::dayFor(CarbonInterface $at): string` (Y-m-d in event tz), `Event::isMultiDay(): bool`, `Event::dayNumber(string $day): int` (1-based).
- Produces: `EventDoorScan` constants `OUTCOME_ADMITTED|OUTCOME_ALREADY_IN|OUTCOME_DUPLICATE|OUTCOME_REFUSED`, scope `admittedOn(string $day)` (outcome in admitted, duplicate).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\DoorCheckIn;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    refreshTenantDatabases();
    $this->tenant = Tenant::factory()->create(['slug' => 'doordays', 'isolation_mode' => 'shared']);
});

function threeDayEvent(Tenant $tenant, string $timezone = 'Africa/Accra'): Event
{
    return Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'timezone' => $timezone,
        'starts_at' => Carbon::parse('2026-11-02 08:00', $timezone),
        'ends_at' => Carbon::parse('2026-11-04 17:00', $timezone),
    ]);
}

function guestOf(Event $event): EventRegistration
{
    return EventRegistration::factory()->create([
        'tenant_id' => $event->tenant_id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
}

test('a guest is admitted once per day of a multi-day event', function (): void {
    $event = threeDayEvent($this->tenant);
    $guest = guestOf($event);
    $door = app(DoorCheckIn::class);

    $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Accra'));
    expect($door->checkIn($guest)['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ADMITTED);
    expect($door->checkIn($guest->fresh())['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ALREADY_IN);

    $this->travelTo(Carbon::parse('2026-11-03 08:30', 'Africa/Accra'));
    $dayTwo = $door->checkIn($guest->fresh());
    expect($dayTwo['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ADMITTED)
        ->and($dayTwo['body']['message'])->toContain('Day 2');

    // First arrival is kept.
    expect($guest->fresh()->checked_in_at->toDateString())->toBe('2026-11-02');
});

test('headcount is today\'s entries, with legacy check-ins counted on their day', function (): void {
    $event = threeDayEvent($this->tenant);
    $door = app(DoorCheckIn::class);
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Africa/Accra'));
    [$a, $b] = [guestOf($event), guestOf($event)];
    $door->checkIn($a);
    // A check-in made before the scan log existed (no door scan row).
    $b->update(['status' => EventRegistration::STATUS_CHECKED_IN, 'checked_in_at' => now()]);

    expect($door->counts($event))->toMatchArray(['checked_in' => 2, 'expected' => 2, 'day' => 1, 'is_multi_day' => true]);

    $this->travelTo(Carbon::parse('2026-11-03 10:00', 'Africa/Accra'));
    expect($door->counts($event)['checked_in'])->toBe(0);
});

test('the day follows the event timezone, not UTC', function (): void {
    $event = threeDayEvent($this->tenant, 'Pacific/Auckland');
    $guest = guestOf($event);
    $door = app(DoorCheckIn::class);

    // 11:30 UTC on 2 Nov is 00:30 on 3 Nov in Auckland: a new day there.
    $this->travelTo(Carbon::parse('2026-11-02 07:00', 'UTC'));
    $door->checkIn($guest);
    $this->travelTo(Carbon::parse('2026-11-02 11:30', 'UTC'));

    expect($door->checkIn($guest->fresh())['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ADMITTED);
});

test('an offline admission synced after another door admitted the guest is a duplicate, and the earlier time wins', function (): void {
    $event = threeDayEvent($this->tenant);
    $guest = guestOf($event);
    $door = app(DoorCheckIn::class);
    $this->travelTo(Carbon::parse('2026-11-02 09:10', 'Africa/Accra'));
    $door->checkIn($guest);

    $result = $door->checkIn($guest->fresh(), scannedAt: Carbon::parse('2026-11-02 09:02', 'Africa/Accra'), clientScanId: 'scan-1', wasOffline: true);

    expect($result['body']['outcome'])->toBe(EventDoorScan::OUTCOME_DUPLICATE)
        ->and($guest->fresh()->checked_in_at->format('H:i'))->toBe('09:02')
        ->and(EventDoorScan::query()->admittedOn('2026-11-02')->where('registration_id', $guest->id)->count())->toBe(2);
});

test('a client scan id is applied only once', function (): void {
    $event = threeDayEvent($this->tenant);
    $guest = guestOf($event);
    $door = app(DoorCheckIn::class);
    $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Accra'));

    $door->checkIn($guest, scannedAt: now(), clientScanId: 'scan-9', wasOffline: true);
    $again = $door->checkIn($guest->fresh(), scannedAt: now(), clientScanId: 'scan-9', wasOffline: true);

    expect($again['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ADMITTED)
        ->and(EventDoorScan::query()->where('client_scan_id', 'scan-9')->count())->toBe(1);
});

test('a cancelled registration is refused and the refusal is logged', function (): void {
    $event = threeDayEvent($this->tenant);
    $guest = guestOf($event);
    $guest->update(['status' => EventRegistration::STATUS_CANCELLED]);

    $result = app(DoorCheckIn::class)->checkIn($guest->fresh(), clientScanId: 'scan-x', wasOffline: true);

    expect($result['status'])->toBe(422)
        ->and($result['body']['outcome'])->toBe(EventDoorScan::OUTCOME_REFUSED)
        ->and(EventDoorScan::query()->where('client_scan_id', 'scan-x')->value('outcome'))->toBe('refused');
});

test('a single-day event says nothing about days', function (): void {
    $event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(5),
    ]);

    $message = app(DoorCheckIn::class)->checkIn(guestOf($event))['body']['message'];

    expect($message)->not->toContain('Day');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `DB_DATABASE=miconvener_testing "$PHP" artisan test --compact tests/Feature/Events/DoorScanDaysTest.php`
Expected: FAIL — class `App\Models\EventDoorScan` not found.

- [ ] **Step 3: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every door scan, live or synced from a phone that was offline. Entry is per
 * event day, so a multi-day event admits each guest once a day, and a ticket
 * admitted twice in one day can be listed afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_door_scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->foreignUuid('staff_link_id')->nullable()->constrained('event_staff_links')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_scan_id', 64)->nullable()->unique();
            $table->date('event_day');
            $table->timestamp('scanned_at');
            $table->boolean('was_offline')->default(false);
            $table->string('outcome', 16);
            $table->timestamps();

            $table->index(['event_id', 'event_day', 'outcome']);
            $table->index(['registration_id', 'event_day']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_door_scans');
    }
};
```

- [ ] **Step 4: Model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scan at a door: who, which door, when, and what the door decided.
 */
final class EventDoorScan extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const string OUTCOME_ADMITTED = 'admitted';

    public const string OUTCOME_ALREADY_IN = 'already_in';

    public const string OUTCOME_DUPLICATE = 'duplicate';

    public const string OUTCOME_REFUSED = 'refused';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'event_id', 'registration_id', 'staff_link_id', 'user_id',
        'client_scan_id', 'event_day', 'scanned_at', 'was_offline', 'outcome',
    ];

    protected $casts = [
        'event_day' => 'date:Y-m-d',
        'scanned_at' => 'datetime',
        'was_offline' => 'boolean',
    ];

    /**
     * Scans that let someone in on that day: the first admission and any
     * offline admission that turned out to be a second.
     *
     * @param  Builder<EventDoorScan>  $query
     * @return Builder<EventDoorScan>
     */
    public function scopeAdmittedOn(Builder $query, string $day): Builder
    {
        return $query->whereDate('event_day', $day)
            ->whereIn('outcome', [self::OUTCOME_ADMITTED, self::OUTCOME_DUPLICATE]);
    }

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    /** @return BelongsTo<EventStaffLink, $this> */
    public function staffLink(): BelongsTo
    {
        return $this->belongsTo(EventStaffLink::class, 'staff_link_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who let them in, as the door log shows it. */
    public function doorLabel(): string
    {
        return $this->staffLink?->name
            ?? ($this->user ? mb_trim($this->user->first_name.' '.$this->user->last_name) : 'Console');
    }
}
```

- [ ] **Step 5: Event helpers** — add to `app/Models/Event.php`:

```php
    /**
     * @return HasMany<EventDoorScan, $this>
     */
    public function doorScans(): HasMany
    {
        return $this->hasMany(EventDoorScan::class);
    }

    /** The event's own calendar day for a moment, in the event's timezone. */
    public function dayFor(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone($this->timezone ?: 'Africa/Accra')->toDateString();
    }

    public function isMultiDay(): bool
    {
        return $this->dayFor($this->starts_at) !== $this->dayFor($this->ends_at);
    }

    /** 1 for the event's first day, 2 for the next, and so on. */
    public function dayNumber(string $day): int
    {
        $first = Carbon::parse($this->dayFor($this->starts_at));

        return (int) $first->diffInDays(Carbon::parse($day)) + 1;
    }
```

(Import `Carbon\CarbonInterface` and `Illuminate\Support\Carbon` if not already imported.)

- [ ] **Step 6: DoorCheckIn** — replace `checkIn` and `counts` in `app/Services/Events/DoorCheckIn.php`:

```php
    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function checkIn(
        EventRegistration $registration,
        ?User $user = null,
        ?EventStaffLink $staffLink = null,
        ?CarbonInterface $scannedAt = null,
        ?string $clientScanId = null,
        bool $wasOffline = false,
    ): array {
        if ($clientScanId !== null && ($seen = EventDoorScan::query()->where('client_scan_id', $clientScanId)->first())) {
            return $this->replay($registration, $seen);
        }

        $event = $registration->event;
        // A phone's clock can be wrong; nothing is admitted in the future.
        $at = $scannedAt !== null && $scannedAt->lessThan(now()) ? Carbon::instance($scannedAt) : now();
        $day = $event->dayFor($at);
        $log = fn (string $outcome): EventDoorScan => EventDoorScan::query()->create([
            'tenant_id' => $registration->tenant_id,
            'event_id' => $registration->event_id,
            'registration_id' => $registration->id,
            'staff_link_id' => $staffLink?->id,
            'user_id' => $user?->id,
            'client_scan_id' => $clientScanId,
            'event_day' => $day,
            'scanned_at' => $at,
            'was_offline' => $wasOffline,
            'outcome' => $outcome,
        ]);

        if (! $registration->isConfirmed()) {
            $log(EventDoorScan::OUTCOME_REFUSED);

            return ['status' => 422, 'body' => ['message' => 'This registration is not confirmed.', 'outcome' => EventDoorScan::OUTCOME_REFUSED, 'refused' => true]];
        }

        $registration->loadMissing('seatAssignment.room:id,name');
        $earlier = $this->admissionOn($registration, $day);

        if ($earlier !== null) {
            $outcome = $wasOffline ? EventDoorScan::OUTCOME_DUPLICATE : EventDoorScan::OUTCOME_ALREADY_IN;
            $log($outcome);
            $this->keepEarliestArrival($registration, $at);

            return ['status' => 200, 'body' => [
                'message' => "{$registration->full_name} is already in today ({$earlier['time']}, {$earlier['by']}).",
                'outcome' => $outcome,
                'registration' => $this->payload($registration),
                'already_checked_in' => true,
            ]];
        }

        $log(EventDoorScan::OUTCOME_ADMITTED);

        if ($registration->status !== EventRegistration::STATUS_CHECKED_IN) {
            $registration->update([
                'status' => EventRegistration::STATUS_CHECKED_IN,
                'checked_in_at' => $at,
                'checked_in_by' => $user?->id,
                'checked_in_source' => $staffLink !== null ? 'staff_link' : null,
                'checked_in_by_staff_link_id' => $staffLink?->id,
            ]);
            $this->rules->registrationCheckedIn($registration, (string) $registration->checked_in_at?->getTimestamp());
        }

        $prefix = $event->isMultiDay() ? 'Day '.$event->dayNumber($day).' · ' : '';

        return ['status' => 200, 'body' => [
            'message' => "{$prefix}{$registration->full_name} checked in.",
            'outcome' => EventDoorScan::OUTCOME_ADMITTED,
            'registration' => $this->payload($registration),
            'already_checked_in' => false,
        ]];
    }

    /**
     * The admission already recorded for this guest on that day, if any. A
     * check-in made before the door log existed counts on its own day.
     *
     * @return array{time: string, by: string}|null
     */
    private function admissionOn(EventRegistration $registration, string $day): ?array
    {
        $scan = EventDoorScan::query()->admittedOn($day)
            ->where('registration_id', $registration->id)
            ->with(['staffLink:id,name', 'user:id,first_name,last_name'])
            ->orderBy('scanned_at')
            ->first();

        $tz = $registration->event->timezone ?: 'Africa/Accra';

        if ($scan !== null) {
            return ['time' => $scan->scanned_at->setTimezone($tz)->format('H:i'), 'by' => $scan->doorLabel()];
        }

        $legacy = $registration->checked_in_at;
        $hasLog = EventDoorScan::query()->where('registration_id', $registration->id)
            ->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE])->exists();

        if ($registration->status === EventRegistration::STATUS_CHECKED_IN && $legacy !== null && ! $hasLog
            && $registration->event->dayFor($legacy) === $day) {
            return ['time' => $legacy->copy()->setTimezone($tz)->format('H:i'), 'by' => 'earlier check-in'];
        }

        return null;
    }

    /** A late-synced scan from before the recorded arrival becomes the arrival. */
    private function keepEarliestArrival(EventRegistration $registration, CarbonInterface $at): void
    {
        if ($registration->checked_in_at !== null && $at->lessThan($registration->checked_in_at)) {
            $registration->update(['checked_in_at' => $at]);
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function replay(EventRegistration $registration, EventDoorScan $seen): array
    {
        return ['status' => $seen->outcome === EventDoorScan::OUTCOME_REFUSED ? 422 : 200, 'body' => [
            'message' => 'Already received.',
            'outcome' => $seen->outcome,
            'registration' => $this->payload($registration),
        ]];
    }

    /**
     * Today's headcount: anyone admitted today, plus check-ins from before the
     * door log existed that fall on today.
     *
     * @return array{checked_in: int, expected: int, day: int, is_multi_day: bool}
     */
    public function counts(Event $event): array
    {
        $today = $event->dayFor(now());
        $tz = $event->timezone ?: 'Africa/Accra';
        $start = Carbon::parse($today, $tz)->startOfDay()->utc();
        $end = $start->copy()->addDay();

        $scanned = EventDoorScan::query()->where('event_id', $event->id)->admittedOn($today)->distinct()->pluck('registration_id');
        $legacy = $event->registrations()
            ->where('status', EventRegistration::STATUS_CHECKED_IN)
            ->whereBetween('checked_in_at', [$start, $end])
            ->whereDoesntHave('doorScans')
            ->pluck('id');

        return [
            'checked_in' => $scanned->merge($legacy)->unique()->count(),
            'expected' => $event->registrations()
                ->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])
                ->count(),
            'day' => $event->dayNumber($today),
            'is_multi_day' => $event->isMultiDay(),
        ];
    }
```

Imports: `App\Models\EventDoorScan`, `Carbon\CarbonInterface`, `Illuminate\Support\Carbon`. Add to `EventRegistration`:

```php
    /**
     * @return HasMany<EventDoorScan, $this>
     */
    public function doorScans(): HasMany
    {
        return $this->hasMany(EventDoorScan::class, 'registration_id');
    }
```

- [ ] **Step 7: Console door counts** — in `EventController::door`, replace the inline `'counts' => [...]` with `'counts' => app(DoorCheckIn::class)->counts($eventModel),`.

- [ ] **Step 8: Run the new tests and the existing check-in suites**

Run: `DB_DATABASE=miconvener_testing "$PHP" artisan test --compact tests/Feature/Events/DoorScanDaysTest.php tests/Feature/Events/StaffLinksTest.php tests/Feature/Events`
Expected: PASS. Fix any existing test that asserted the old "was already checked in." wording to the new "is already in today" message.

- [ ] **Step 9: Pint, PHPStan, commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G --no-progress
git add database/migrations/landlord/2026_10_04_130000_create_event_door_scans_table.php app/Models/EventDoorScan.php app/Models/Event.php app/Models/EventRegistration.php app/Services/Events/DoorCheckIn.php app/Http/Controllers/Tenant/EventController.php tests/Feature/Events
git commit -m "feat(check-in): door scan log and per-day entry for multi-day events"
```

---

### Task 2: Offline pack and sync endpoints

**Files:**
- Modify: `app/Http/Controllers/Public/StaffLinkController.php` (methods `pack`, `sync`)
- Create: `app/Http/Requests/SyncStaffScansRequest.php`
- Modify: `routes/subdomain.php` (two routes inside the `staff/{token}` group)
- Test: `tests/Feature/Events/StaffLinkOfflineTest.php`

**Interfaces:**
- Consumes: `DoorCheckIn::checkIn(...)` (Task 1), `Event::dayFor()`.
- Produces: `GET /staff/{token}/offline-pack` (`public.staff.pack`) → `{version: string, day: string, timezone: string, ends_at: string, guests: list<{id, name, ticket_type, ticket_code, seat, room, qr_hash, in_today_at}>}`.
- Produces: `POST /staff/{token}/checkin/sync` (`public.staff.sync`), body `{scans: list<{client_scan_id: string, registration_id: string, scanned_at: string}>, since?: string}` → `{results: list<{client_scan_id, outcome, message}>, admitted_since: list<{registration_id, at}>, server_time: string}`.

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\EventRegistration;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use Illuminate\Support\Str;

beforeEach(function (): void {
    refreshTenantDatabases();
    $this->tenant = Tenant::factory()->create(['slug' => 'offlinecrew', 'isolation_mode' => 'shared']);
    $this->host = 'offlinecrew.'.mb_ltrim((string) config('session.domain'), '.');
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(6)]);
    $this->link = EventStaffLink::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $this->base = "http://{$this->host}/staff/{$this->link->token}";
});

function offlineGuest(Event $event, array $attributes = []): EventRegistration
{
    return EventRegistration::factory()->create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED, ...$attributes]);
}

test('the pack lists guests with hashed QR tokens and no contact details', function (): void {
    $guest = offlineGuest($this->event);

    $pack = $this->getJson("{$this->base}/offline-pack", ['HTTP_HOST' => $this->host])->assertOk();

    $row = collect($pack->json('guests'))->firstWhere('id', $guest->id);
    expect($row['qr_hash'])->toBe(hash('sha256', $guest->qr_token))
        ->and($row)->not->toHaveKeys(['email', 'phone', 'qr_token'])
        ->and(json_encode($pack->json()))->not->toContain($guest->qr_token)->not->toContain($guest->email);
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

    expect($first->json('results.0.outcome'))->toBe('admitted')
        ->and(EventDoorScan::query()->where('registration_id', $guest->id)->count())->toBe(1)
        ->and($guest->fresh()->checked_in_at->diffInMinutes(now()))->toBeGreaterThanOrEqual(19)
        ->and($guest->fresh()->checked_in_by_staff_link_id)->toBe($this->link->id);
});

test('a scan time in the future is clamped to now', function (): void {
    $guest = offlineGuest($this->event);

    $this->postJson("{$this->base}/checkin/sync", ['scans' => [[
        'client_scan_id' => (string) Str::uuid(), 'registration_id' => $guest->id, 'scanned_at' => now()->addHours(3)->toIso8601String(),
    ]]], ['HTTP_HOST' => $this->host])->assertOk();

    expect($guest->fresh()->checked_in_at->lessThanOrEqualTo(now()))->toBeTrue();
});

test('two phones on one link sync independently, and the second admission is a duplicate', function (): void {
    $guest = offlineGuest($this->event);
    $send = fn (int $minutesAgo) => $this->postJson("{$this->base}/checkin/sync", ['scans' => [[
        'client_scan_id' => (string) Str::uuid(), 'registration_id' => $guest->id, 'scanned_at' => now()->subMinutes($minutesAgo)->toIso8601String(),
    ]]], ['HTTP_HOST' => $this->host]);

    expect($send(10)->json('results.0.outcome'))->toBe('admitted')
        ->and($send(5)->json('results.0.outcome'))->toBe('duplicate');
});

test('a scan for a cancelled or unknown registration is refused, not dropped', function (): void {
    $cancelled = offlineGuest($this->event, ['status' => EventRegistration::STATUS_CANCELLED]);
    $other = offlineGuest(Event::factory()->published()->create(['tenant_id' => $this->tenant->id]));

    $results = $this->postJson("{$this->base}/checkin/sync", ['scans' => [
        ['client_scan_id' => 'a-1', 'registration_id' => $cancelled->id, 'scanned_at' => now()->toIso8601String()],
        ['client_scan_id' => 'a-2', 'registration_id' => $other->id, 'scanned_at' => now()->toIso8601String()],
    ]], ['HTTP_HOST' => $this->host])->assertOk()->json('results');

    expect(collect($results)->pluck('outcome')->all())->toBe(['refused', 'refused'])
        ->and($other->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('sync returns admissions made at other doors since the last sync', function (): void {
    $since = now()->subMinute()->toIso8601String();
    $guest = offlineGuest($this->event);
    app(\App\Services\Events\DoorCheckIn::class)->checkIn($guest);

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
```

- [ ] **Step 2: Run, expect 404s** — `DB_DATABASE=miconvener_testing "$PHP" artisan test --compact tests/Feature/Events/StaffLinkOfflineTest.php`

- [ ] **Step 3: Form request** `app/Http/Requests/SyncStaffScansRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncStaffScansRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The staff link token is the credential; the controller checks it.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scans' => ['present', 'array', 'max:500'],
            'scans.*.client_scan_id' => ['required', 'string', 'max:64'],
            'scans.*.registration_id' => ['required', 'string', 'max:64'],
            'scans.*.scanned_at' => ['required', 'date'],
            'since' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['scans.max' => 'Send at most 500 scans at a time.'];
    }
}
```

- [ ] **Step 4: Controller methods** in `StaffLinkController`:

```php
    /**
     * Everything the phone needs to keep admitting guests without a
     * connection. QR tokens leave as SHA-256 hashes only: the phone can
     * recognise a scanned code but cannot produce one.
     */
    public function pack(Request $request, string $subdomain, string $token): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'check_in');
        $event = $link->event;
        $today = $event->dayFor(now());
        $tz = $event->timezone ?: 'Africa/Accra';

        $inToday = EventDoorScan::query()->where('event_id', $event->id)->admittedOn($today)
            ->selectRaw('registration_id, min(scanned_at) as first_at')->groupBy('registration_id')
            ->pluck('first_at', 'registration_id');

        $guests = $event->registrations()
            ->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])
            ->with(['ticketType:id,name', 'seatAssignment.room:id,name'])
            ->get(['id', 'full_name', 'ticket_code', 'qr_token', 'ticket_type_id', 'status', 'checked_in_at'])
            ->map(function (EventRegistration $r) use ($inToday, $event, $today, $tz): array {
                $legacyToday = $r->checked_in_at !== null && $event->dayFor($r->checked_in_at) === $today ? $r->checked_in_at : null;
                $in = $inToday[$r->id] ?? $legacyToday;

                return [
                    'id' => $r->id,
                    'name' => $r->full_name,
                    'ticket_type' => $r->ticketType?->name,
                    'ticket_code' => $r->ticket_code,
                    'seat' => $r->seatAssignment?->seat_label,
                    'room' => $r->seatAssignment?->room?->name,
                    'qr_hash' => $r->qr_token !== null ? hash('sha256', $r->qr_token) : null,
                    'in_today_at' => $in !== null ? Carbon::parse($in)->setTimezone($tz)->toIso8601String() : null,
                ];
            });

        return response()->json([
            'version' => now()->toIso8601String(),
            'day' => $today,
            'timezone' => $tz,
            'ends_at' => $event->ends_at->copy()->addHours(EventStaffLink::HOURS_AFTER_EVENT)->toIso8601String(),
            'guests' => $guests,
        ]);
    }

    /**
     * Scans made while the phone was offline. Each is applied once (by its
     * client id), so resending after a dropped answer is harmless.
     */
    public function sync(SyncStaffScansRequest $request, string $subdomain, string $token): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'check_in');
        $results = [];

        foreach ($request->validated('scans') as $scan) {
            $registration = $link->event->registrations()->where('id', $scan['registration_id'])->first();

            if ($registration === null) {
                $results[] = ['client_scan_id' => $scan['client_scan_id'], 'outcome' => EventDoorScan::OUTCOME_REFUSED, 'message' => 'This ticket is not for this event.'];

                continue;
            }

            $result = $this->door->checkIn($registration, staffLink: $link, scannedAt: Carbon::parse($scan['scanned_at']), clientScanId: $scan['client_scan_id'], wasOffline: true);
            $results[] = ['client_scan_id' => $scan['client_scan_id'], 'outcome' => $result['body']['outcome'], 'message' => $result['body']['message']];
        }

        $since = $request->validated('since');

        return response()->json([
            'results' => $results,
            'admitted_since' => $since === null ? [] : EventDoorScan::query()
                ->where('event_id', $link->event_id)
                ->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE])
                ->where('created_at', '>', Carbon::parse($since))
                ->get(['registration_id', 'scanned_at'])
                ->map(fn (EventDoorScan $s): array => ['registration_id' => $s->registration_id, 'at' => $s->scanned_at->toIso8601String()]),
            'server_time' => now()->toIso8601String(),
        ]);
    }
```

Imports: `App\Http\Requests\SyncStaffScansRequest`, `App\Models\EventDoorScan`, `App\Models\EventRegistration`, `Illuminate\Support\Carbon`.

- [ ] **Step 5: Routes** — inside the `staff/{token}` group in `routes/subdomain.php`:

```php
    Route::get('/offline-pack', [App\Http\Controllers\Public\StaffLinkController::class, 'pack'])->name('pack');
    Route::post('/checkin/sync', [App\Http\Controllers\Public\StaffLinkController::class, 'sync'])->name('sync');
```

Place them **before** `Route::post('/checkin/{registration}', ...)` so `sync` is not captured as a registration id.

- [ ] **Step 6: Run** `tests/Feature/Events/StaffLinkOfflineTest.php tests/Feature/Events/StaffLinksTest.php` — expect PASS.

- [ ] **Step 7: Pint, PHPStan, commit** — `feat(staff-links): offline pack and idempotent scan sync`.

---

### Task 3: Console — days and "used twice"

**Files:**
- Create: `app/Http/Controllers/Tenant/EventDoorScanController.php`
- Modify: `routes/subdomain.php` (`events/{event}/data/door-scans`)
- Modify: `app/Http/Controllers/Tenant/EventReportController.php` (`exportCheckins` columns)
- Create: `resources/js/Pages/Tenant/Events/panels/DoorScansPanel.jsx`
- Modify: `resources/js/Pages/Tenant/Events/Show.jsx` (render in the check-in section)
- Test: `tests/Feature/Events/DoorScanSummaryTest.php`

**Interfaces:**
- Consumes: `EventDoorScan::admittedOn()`, `Event::dayNumber()`, `EventCheckInController::PERMISSIONS`.
- Produces: `GET events/{event}/data/door-scans` (`tenant.events.door-scans.index`) → `{days: list<{day, number, admitted}>, used_twice: list<{registration_id, name, ticket_code, day, number, entries: list<{at, by, offline}>}>}`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\DoorCheckIn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['slug' => 'summary', 'isolation_mode' => 'shared']);
    $this->host = 'summary.'.mb_ltrim((string) config('session.domain'), '.');
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->user->assignRole('Event Staff');
    $this->tenant->users()->attach($this->user->id);
});

test('the console shows entries per day and tickets used twice in a day', function (): void {
    $event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'starts_at' => Carbon::parse('2026-11-02 08:00', 'Africa/Accra'),
        'ends_at' => Carbon::parse('2026-11-03 17:00', 'Africa/Accra'),
    ]);
    $gate = EventStaffLink::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $event->id, 'name' => 'Gate A – Kwame']);
    $guest = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $event->id, 'status' => 'confirmed', 'full_name' => 'Ama Owusu']);
    $door = app(DoorCheckIn::class);

    $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Accra'));
    $door->checkIn($guest, staffLink: $gate);
    $door->checkIn($guest->fresh(), scannedAt: now()->subMinutes(5), clientScanId: 'off-1', wasOffline: true);
    $this->travelTo(Carbon::parse('2026-11-03 09:00', 'Africa/Accra'));
    $door->checkIn($guest->fresh(), staffLink: $gate);

    $data = $this->actingAs($this->user)
        ->getJson("http://{$this->host}/events/{$event->id}/data/door-scans", ['HTTP_HOST' => $this->host])
        ->assertOk()->json();

    expect($data['days'])->toBe([
        ['day' => '2026-11-02', 'number' => 1, 'admitted' => 1],
        ['day' => '2026-11-03', 'number' => 2, 'admitted' => 1],
    ])
        ->and($data['used_twice'])->toHaveCount(1)
        ->and($data['used_twice'][0]['name'])->toBe('Ama Owusu')
        ->and($data['used_twice'][0]['entries'])->toHaveCount(2);
});
```

- [ ] **Step 2: Run, expect 404.**

- [ ] **Step 3: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The door log as the console shows it: entries per day, and the tickets
 * admitted twice in one day (only possible while a door was offline).
 */
final class EventDoorScanController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        abort_unless(Gate::any([...EventCheckInController::PERMISSIONS, 'read event']), 403);
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless($tenant instanceof Tenant, 403);
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();
        $tz = $eventModel->timezone ?: 'Africa/Accra';

        $admitting = EventDoorScan::query()
            ->where('event_id', $eventModel->id)
            ->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE])
            ->with(['registration:id,full_name,ticket_code', 'staffLink:id,name', 'user:id,first_name,last_name'])
            ->orderBy('scanned_at')
            ->get();

        $byDay = $admitting->groupBy(fn (EventDoorScan $s): string => $s->event_day->toDateString());

        return response()->json([
            'days' => $byDay->map(fn ($scans, string $day): array => [
                'day' => $day,
                'number' => $eventModel->dayNumber($day),
                'admitted' => $scans->pluck('registration_id')->unique()->count(),
            ])->values(),
            'used_twice' => $byDay->flatMap(fn ($scans, string $day) => $scans->groupBy('registration_id')
                ->filter(fn ($entries): bool => $entries->count() > 1)
                ->map(fn ($entries): array => [
                    'registration_id' => $entries->first()->registration_id,
                    'name' => $entries->first()->registration?->full_name,
                    'ticket_code' => $entries->first()->registration?->ticket_code,
                    'day' => $day,
                    'number' => $eventModel->dayNumber($day),
                    'entries' => $entries->map(fn (EventDoorScan $s): array => [
                        'at' => $s->scanned_at->setTimezone($tz)->format('H:i'),
                        'by' => $s->doorLabel(),
                        'offline' => $s->was_offline,
                    ])->values(),
                ])->values())->values(),
        ]);
    }
}
```

Route (authenticated group, near the check-in routes):

```php
    Route::get('events/{event}/data/door-scans', [App\Http\Controllers\Tenant\EventDoorScanController::class, 'index'])->name('tenant.events.door-scans.index');
```

- [ ] **Step 4: Export columns** — in `exportCheckins`, header becomes `['Name', 'Ticket code', 'Checked in at', 'Checked in by', 'Days attended', 'Used twice on']`; eager-load `doorScans` and add per row:

```php
$admitting = $registration->doorScans->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE]);
$days = $admitting->groupBy(fn (EventDoorScan $s): string => $s->event_day->toDateString());
// ... existing four columns, then:
$days->count() ?: 1,
$days->filter(fn ($scans): bool => $scans->count() > 1)->keys()->implode(' '),
```

Add a test to `DoorScanSummaryTest` asserting the CSV header contains `Used twice on` and the row contains `2026-11-02`.

- [ ] **Step 5: Panel** `resources/js/Pages/Tenant/Events/panels/DoorScansPanel.jsx`

```jsx
import { useEffect, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';

/**
 * Entries per day and tickets admitted twice in one day. A second admission
 * only happens while a door is offline, so the list is normally empty.
 */
export default function DoorScansPanel({ event }) {
    const [data, setData] = useState(null);

    useEffect(() => {
        const load = () =>
            csrfFetch(route('tenant.events.door-scans.index', { event: event.id }))
                .then((r) => (r.ok ? r.json() : null))
                .then(setData);
        load();
        const timer = setInterval(load, 30000);
        return () => clearInterval(timer);
    }, [event.id]);

    if (!data || (data.days.length < 2 && data.used_twice.length === 0)) return null;

    return (
        <section className="space-y-3 border border-border p-4">
            {data.days.length > 1 && (
                <div className="flex flex-wrap gap-4 text-sm">
                    {data.days.map((d) => (
                        <span key={d.day}>
                            <b>Day {d.number}</b>{' '}
                            <span className="num text-ink-secondary">{d.admitted.toLocaleString()} in</span>
                        </span>
                    ))}
                </div>
            )}
            {data.used_twice.length > 0 && (
                <div>
                    <h3 className="text-sm font-semibold text-ink">
                        Used twice in a day ({data.used_twice.length})
                    </h3>
                    <p className="text-xs text-ink-secondary">
                        Both admitted while a door was offline. Shown for your records; the
                        ticket holder is listed.
                    </p>
                    <ul className="mt-2 divide-y divide-border text-sm">
                        {data.used_twice.map((u) => (
                            <li key={`${u.registration_id}-${u.day}`} className="py-2">
                                <b>{u.name}</b>{' '}
                                <span className="font-mono text-xs text-ink-secondary">{u.ticket_code}</span>
                                <span className="text-ink-secondary"> · Day {u.number}: </span>
                                {u.entries.map((e) => `${e.at} ${e.by}${e.offline ? ' (offline)' : ''}`).join(', ')}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
```

In `Show.jsx`, import it and render `<DoorScansPanel event={event} />` in the `'check-in'` section above `<CheckInPanel …/>`.

- [ ] **Step 6: Run tests, lint, commit** — `feat(check-in): per-day entries and used-twice list in the console`.

---

### Task 4: Phone door library (offline decision, queue, sync)

**Files:**
- Create: `resources/js/lib/staffDoor/hash.js`
- Create: `resources/js/lib/staffDoor/store.js`
- Create: `resources/js/lib/staffDoor/door.js`
- Test: `resources/js/test/staff/door.test.js`

**Interfaces:**
- Produces: `sha256Hex(text: string): Promise<string>`.
- Produces: `createMemoryStore()`, `createIdbStore(name: string)` — both `{ get(key), set(key, value), clear() }` returning promises.
- Produces: `createStaffDoor({ urls: {scan, search, checkIn: (id) => string, pack, sync}, store, fetcher, timeoutMs = 3000, now = () => new Date(), newId = () => crypto.randomUUID() })` → `{ scan(token), search(q), checkIn(id), refreshPack(), sync(), status(), subscribe(fn) }`. Results use the same shape as the server (`message`, `registration`, `already_checked_in`) plus `offline: true` and `refused: true` where relevant. `status()` → `{ online, queued, guests, packUpdatedAt, attention: list<string>, closed }`.

- [ ] **Step 1: Failing tests** `resources/js/test/staff/door.test.js`

```js
import { describe, expect, it, vi } from 'vitest';
import { createStaffDoor } from '@/lib/staffDoor/door';
import { createMemoryStore } from '@/lib/staffDoor/store';
import { sha256Hex } from '@/lib/staffDoor/hash';

const urls = {
    scan: '/scan',
    search: '/search',
    checkIn: (id) => `/checkin/${id}`,
    pack: '/pack',
    sync: '/sync',
};

const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const offline = () => Promise.reject(new TypeError('Failed to fetch'));

async function packWith(guests) {
    return {
        version: '2026-11-02T08:00:00Z',
        day: '2026-11-02',
        timezone: 'Africa/Accra',
        ends_at: '2099-01-01T00:00:00Z',
        guests: await Promise.all(
            guests.map(async (g) => ({ in_today_at: null, seat: null, room: null, ...g, qr_hash: await sha256Hex(g.qr) }))
        ),
    };
}

async function doorWith(fetcher, pack) {
    const store = createMemoryStore();
    if (pack) await store.set('pack', pack);
    return createStaffDoor({ urls, store, fetcher, now: () => new Date('2026-11-02T09:00:00Z'), newId: () => 'id-1' });
}

describe('staff door', () => {
    it('uses the server when it answers', async () => {
        const fetcher = vi.fn(async () => json({ message: 'Kofi checked in.', outcome: 'admitted' }));
        const door = await doorWith(fetcher);

        expect((await door.scan('QR-1')).message).toBe('Kofi checked in.');
        expect(door.status().queued).toBe(0);
    });

    it('falls back to the pack when the network fails, and queues the admission', async () => {
        const door = await doorWith(offline, await packWith([{ id: 'r1', name: 'Ama Owusu', ticket_code: 'T1', qr: 'QR-1' }]));

        const result = await door.scan('QR-1');

        expect(result.offline).toBe(true);
        expect(result.registration.full_name).toBe('Ama Owusu');
        expect(door.status().queued).toBe(1);
    });

    it('falls back after the timeout when the server does not answer', async () => {
        vi.useFakeTimers();
        const hang = () => new Promise(() => {});
        const door = await doorWith(hang, await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }]));

        const pending = door.scan('QR-1');
        await vi.advanceTimersByTimeAsync(3000);

        expect((await pending).offline).toBe(true);
        vi.useRealTimers();
    });

    it('refuses its own repeat offline, and an unknown code', async () => {
        const door = await doorWith(offline, await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }]));
        await door.scan('QR-1');

        expect((await door.scan('QR-1')).already_checked_in).toBe(true);
        expect((await door.scan('NOPE')).refused).toBe(true);
    });

    it('treats a guest already in today, per the pack, as already in', async () => {
        const door = await doorWith(
            offline,
            await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1', in_today_at: '2026-11-02T08:30:00Z' }])
        );

        expect((await door.scan('QR-1')).already_checked_in).toBe(true);
    });

    it('searches the pack by name or ticket code when offline', async () => {
        const door = await doorWith(offline, await packWith([{ id: 'r1', name: 'Ama Owusu', ticket_code: 'EVT-77', qr: 'QR-1' }]));

        expect((await door.search('owu')).map((r) => r.id)).toEqual(['r1']);
        expect((await door.search('evt-77')).map((r) => r.id)).toEqual(['r1']);
    });

    it('sends the queue on sync, keeps unacknowledged scans, and surfaces refusals', async () => {
        const pack = await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }]);
        let online = false;
        const fetcher = vi.fn(async (url) => {
            if (!online) throw new TypeError('offline');
            if (url === '/sync') return json({ results: [{ client_scan_id: 'id-1', outcome: 'refused', message: 'This registration is not confirmed.' }], admitted_since: [], server_time: '2026-11-02T09:05:00Z' });
            return json(pack);
        });
        const door = await doorWith(fetcher, pack);
        await door.scan('QR-1');

        await door.sync();
        expect(door.status().queued).toBe(1);

        online = true;
        await door.sync();
        expect(door.status().queued).toBe(0);
        expect(door.status().attention[0]).toContain('not confirmed');
    });

    it('wipes stored data when the server says the link is closed', async () => {
        const store = createMemoryStore();
        await store.set('pack', await packWith([]));
        const door = createStaffDoor({ urls, store, fetcher: async () => json({}, 410), now: () => new Date() });

        await door.refreshPack();

        expect(await store.get('pack')).toBeUndefined();
        expect(door.status().closed).toBe(true);
    });

    it('drops a pack whose event ended more than 12 hours ago', async () => {
        const store = createMemoryStore();
        await store.set('pack', { ...(await packWith([])), ends_at: '2020-01-01T00:00:00Z' });
        const door = createStaffDoor({ urls, store, fetcher: offline, now: () => new Date('2026-11-02T09:00:00Z') });

        await door.ready;

        expect(await store.get('pack')).toBeUndefined();
    });
});
```

- [ ] **Step 2: Run, expect failures** — `PATH=/Users/selase/.nvm/versions/node/v24.16.0/bin:$PATH npx vitest run resources/js/test/staff/door.test.js`

- [ ] **Step 3: `hash.js`**

```js
/** Lowercase hex SHA-256, matching PHP's hash('sha256', ...). */
export async function sha256Hex(text) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}
```

- [ ] **Step 4: `store.js`**

```js
/** In-memory store with the same interface as the IndexedDB one; used in tests. */
export function createMemoryStore() {
    const map = new Map();
    return {
        get: async (key) => map.get(key),
        set: async (key, value) => void map.set(key, value),
        clear: async () => map.clear(),
    };
}

/**
 * One IndexedDB database per staff link. A pack for a large event runs to a
 * megabyte or more, past what localStorage reliably holds on phones.
 */
export function createIdbStore(name) {
    const open = () =>
        new Promise((resolve, reject) => {
            const request = indexedDB.open(name, 1);
            request.onupgradeneeded = () => request.result.createObjectStore('kv');
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

    const run = async (mode, action) => {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('kv', mode);
            const request = action(tx.objectStore('kv'));
            tx.oncomplete = () => resolve(request?.result);
            tx.onerror = () => reject(tx.error);
        });
    };

    return {
        get: (key) => run('readonly', (s) => s.get(key)),
        set: (key, value) => run('readwrite', (s) => s.put(value, key)),
        clear: () => run('readwrite', (s) => s.clear()),
    };
}
```

- [ ] **Step 5: `door.js`**

```js
import csrfFetch from '@/lib/csrfFetch';
import { sha256Hex } from './hash';

class Unreachable extends Error {}

/**
 * The door, as a staff link's phone sees it: the server first, the stored
 * guest list when the server cannot be reached within the timeout. Offline
 * admissions are queued and sent when the connection returns.
 */
export function createStaffDoor({
    urls,
    store,
    fetcher = csrfFetch,
    timeoutMs = 3000,
    now = () => new Date(),
    newId = () => crypto.randomUUID(),
}) {
    let pack = null;
    let queue = [];
    let local = {}; // registration id -> ISO time this phone admitted them, today
    let attention = [];
    let lastSync = null;
    let online = true;
    let closed = false;
    const listeners = new Set();

    const emit = () => listeners.forEach((fn) => fn(status()));
    const status = () => ({
        online,
        closed,
        queued: queue.length,
        guests: pack?.guests?.length ?? 0,
        packUpdatedAt: pack?.version ?? null,
        attention,
    });

    const persist = () =>
        Promise.all([store.set('queue', queue), store.set('local', local), store.set('attention', attention), store.set('lastSync', lastSync)]);

    const wipe = async () => {
        closed = true;
        pack = null;
        queue = [];
        local = {};
        await store.clear();
        emit();
    };

    const ready = (async () => {
        pack = (await store.get('pack')) ?? null;
        queue = (await store.get('queue')) ?? [];
        local = (await store.get('local')) ?? {};
        attention = (await store.get('attention')) ?? [];
        lastSync = (await store.get('lastSync')) ?? null;
        if (pack && new Date(pack.ends_at) < now()) {
            await wipe();
            closed = false;
        }
        emit();
    })();

    const request = async (url, options = {}) => {
        let timer;
        const timeout = new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Unreachable()), timeoutMs);
        });
        try {
            const response = await Promise.race([fetcher(url, options), timeout]);
            online = true;
            if (response.status === 410) {
                await wipe();
                throw new Unreachable();
            }
            return response;
        } catch (error) {
            online = false;
            emit();
            throw error instanceof Unreachable ? error : new Unreachable();
        } finally {
            clearTimeout(timer);
        }
    };

    const today = () =>
        new Intl.DateTimeFormat('en-CA', { timeZone: pack?.timezone ?? 'Africa/Accra' }).format(now());

    const inToday = (guest) => {
        if (local[guest.id]?.slice(0, 10) === today()) return local[guest.id];
        const at = guest.in_today_at;
        return at && new Intl.DateTimeFormat('en-CA', { timeZone: pack.timezone }).format(new Date(at)) === today() ? at : null;
    };

    const asRegistration = (guest) => ({
        id: guest.id,
        full_name: guest.name,
        ticket_code: guest.ticket_code,
        seat_label: guest.seat,
        room_name: guest.room,
    });

    const admitOffline = async (guest) => {
        const earlier = inToday(guest);
        if (earlier) {
            const time = new Date(earlier).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            return { message: `${guest.name} is already in today (${time}).`, already_checked_in: true, offline: true, registration: asRegistration(guest) };
        }
        const at = now().toISOString();
        queue.push({ client_scan_id: newId(), registration_id: guest.id, scanned_at: at });
        local[guest.id] = `${today()}T${at.slice(11)}`;
        await persist();
        emit();
        return { message: `${guest.name} checked in (offline).`, offline: true, registration: asRegistration(guest) };
    };

    const notInList = { message: 'Ticket not recognised.', refused: true, offline: true };

    return {
        ready,
        status,
        subscribe(fn) {
            listeners.add(fn);
            return () => listeners.delete(fn);
        },

        async scan(token) {
            await ready;
            try {
                const response = await request(urls.scan, { method: 'POST', body: JSON.stringify({ token }) });
                return await response.json();
            } catch {
                if (!pack) return { message: 'No connection, and this phone has no guest list yet.', refused: true, offline: true };
                const hash = await sha256Hex(token);
                const guest = pack.guests.find((g) => g.qr_hash === hash);
                return guest ? admitOffline(guest) : notInList;
            }
        },

        async search(query) {
            await ready;
            try {
                const response = await request(`${urls.search}?q=${encodeURIComponent(query)}`);
                return await response.json();
            } catch {
                const q = query.toLowerCase();
                return (pack?.guests ?? [])
                    .filter((g) => g.name.toLowerCase().includes(q) || (g.ticket_code ?? '').toLowerCase().includes(q))
                    .slice(0, 10)
                    .map((g) => ({ id: g.id, full_name: g.name, email: '', ticket_code: g.ticket_code }));
            }
        },

        async checkIn(id) {
            await ready;
            try {
                const response = await request(urls.checkIn(id), { method: 'POST' });
                return await response.json();
            } catch {
                const guest = pack?.guests.find((g) => g.id === id);
                return guest ? admitOffline(guest) : notInList;
            }
        },

        async refreshPack() {
            await ready;
            try {
                const response = await request(urls.pack);
                if (response.ok) {
                    pack = await response.json();
                    await store.set('pack', pack);
                    emit();
                }
            } catch {
                // Offline: keep the pack we have.
            }
        },

        async sync() {
            await ready;
            const sending = [...queue];
            try {
                const response = await request(urls.sync, {
                    method: 'POST',
                    body: JSON.stringify({ scans: sending, since: lastSync }),
                });
                if (!response.ok) return;
                const body = await response.json();
                const acknowledged = new Set(body.results.map((r) => r.client_scan_id));
                queue = queue.filter((s) => !acknowledged.has(s.client_scan_id));
                attention = [
                    ...attention,
                    ...body.results.filter((r) => r.outcome === 'refused').map((r) => r.message),
                ];
                lastSync = body.server_time;
                await persist();
                await this.refreshPack();
            } catch {
                // Still offline; the queue waits.
            }
            emit();
        },
    };
}
```

- [ ] **Step 6: Run the tests; expect PASS. Lint** — `npx eslint resources/js/lib/staffDoor resources/js/test/staff` and `npx prettier --write` the same paths.

- [ ] **Step 7: Commit** — `feat(staff-links): offline-capable door for the phone`.

---

### Task 5: Staff page and scanner use the door; service worker

**Files:**
- Modify: `resources/js/Pages/Tenant/Events/CheckInPanel.jsx` (optional `door` prop; red banner for `refused`; holder name large)
- Modify: `resources/js/Pages/Public/Staff/Show.jsx` (create the door, status line, sync loop, register the worker, requests offline note)
- Create: `public/staff-sw.js`
- Create: `resources/js/lib/staffDoor/registerStaffWorker.js`
- Test: `resources/js/test/staff/checkInPanelDoor.test.jsx`

**Interfaces:**
- Consumes: `createStaffDoor`, `createIdbStore` (Task 4); routes `public.staff.pack`, `public.staff.sync` (Task 2).
- Produces: `CheckInPanel` prop `door?: { scan(token), search(q), checkIn(id) }`. When given, event-mode scanning and manual search go through it; room mode is unchanged.

- [ ] **Step 1: Failing test** `resources/js/test/staff/checkInPanelDoor.test.jsx`

```jsx
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import CheckInPanel from '@/Pages/Tenant/Events/CheckInPanel';

vi.mock('html5-qrcode', () => ({ Html5Qrcode: vi.fn(() => ({ start: () => new Promise(() => {}), stop: async () => {} })) }));

describe('CheckInPanel with a door', () => {
    it('searches through the door and shows the holder name large on admission', async () => {
        const door = {
            scan: vi.fn(),
            search: vi.fn(async () => [{ id: 'r1', full_name: 'Ama Owusu', email: '', ticket_code: 'EVT-1' }]),
            checkIn: vi.fn(async () => ({ message: 'Ama Owusu checked in (offline).', offline: true, registration: { id: 'r1', full_name: 'Ama Owusu' } })),
        };
        render(<CheckInPanel event={{ id: 'e1' }} door={door} scanUrl="" searchUrl="" checkInUrlFor={() => ''} />);

        fireEvent.click(screen.getByText('Manual search'));
        fireEvent.change(screen.getByPlaceholderText(/Search by name/), { target: { value: 'Ama' } });
        fireEvent.click(await screen.findByText('Check in'));

        expect(door.search).toHaveBeenCalledWith('Ama');
        expect(await screen.findByTestId('admitted-name')).toHaveTextContent('Ama Owusu');
    });

    it('shows a refusal in red', async () => {
        const door = { scan: vi.fn(), search: vi.fn(async () => [{ id: 'r9', full_name: 'X', email: '', ticket_code: 'T' }]), checkIn: vi.fn(async () => ({ message: 'Ticket not recognised.', refused: true })) };
        render(<CheckInPanel event={{ id: 'e1' }} door={door} scanUrl="" searchUrl="" checkInUrlFor={() => ''} />);

        fireEvent.click(screen.getByText('Manual search'));
        fireEvent.change(screen.getByPlaceholderText(/Search by name/), { target: { value: 'X' } });
        fireEvent.click(await screen.findByText('Check in'));

        expect((await screen.findByText('Ticket not recognised.')).closest('div').className).toContain('danger');
    });
});
```

(If the existing test setup lacks a `useToast` provider or Ziggy `route`, mirror what `resources/js/test/console/*.test.jsx` do in their setup.)

- [ ] **Step 2: CheckInPanel changes**
  - `CheckInResultBanner`: treat `result.refused` like `is_room_full` (danger styling). When the result is an admission (no `already_checked_in`, `refused`, `error`) and has `registration.full_name`, render it first as `<div data-testid="admitted-name" className="text-2xl font-semibold">{result.registration.full_name}</div>`, then the message.
  - `ScanMode`: accept `door`; in the decoded callback use `const json = door ? await door.scan(decodedText) : await (await csrfFetch(...)).json();`.
  - `ManualMode`: accept `door`; search with `door ? await door.search(query) : await (await csrfFetch(...)).json()`; check-in with `door ? await door.checkIn(id) : ...`.
  - `CheckInPanel`: accept `door = null` and pass it to `ScanMode`/`ManualMode` only when `targetMode === 'event'`.
  - Debounce stays at 250 ms. While the debounced search is pending, show nothing new — no other behaviour change for the console.

- [ ] **Step 3: Worker** `public/staff-sw.js`

```js
// Keeps a staff link openable with no signal: the page itself (network first,
// cached copy when offline) and the built assets (cache first). API calls are
// never cached -- the door library decides what to do without a connection.
const CACHE = 'miconvener-staff-v1';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('miconvener-staff-') && k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate' && url.pathname.startsWith('/staff/')) {
        const key = url.origin + url.pathname;
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) caches.open(CACHE).then((c) => c.put(key, response.clone()));
                    return response;
                })
                .catch(() => caches.match(key))
        );
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then(
                (hit) =>
                    hit ||
                    fetch(request).then((response) => {
                        if (response.ok) caches.open(CACHE).then((c) => c.put(request, response.clone()));
                        return response;
                    })
            )
        );
    }
});
```

`registerStaffWorker.js`:

```js
export function registerStaffWorker() {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register('/staff-sw.js', { scope: '/staff/' }).catch(() => {
        // Without the worker the page still works online, and offline while open.
    });
}
```

- [ ] **Step 4: Staff page** — in `Ready` of `Public/Staff/Show.jsx`:

```jsx
const door = useMemo(
    () =>
        createStaffDoor({
            urls: {
                scan: route('public.staff.checkin.scan', { token }),
                search: route('public.staff.checkin.search', { token }),
                checkIn: (id) => route('public.staff.checkin', { token, registration: id }),
                pack: route('public.staff.pack', { token }),
                sync: route('public.staff.sync', { token }),
            },
            store: createIdbStore(`miconvener-staff-${token.slice(0, 12)}`),
        }),
    [token]
);
const [doorStatus, setDoorStatus] = useState(door.status());

useEffect(() => {
    if (!link.can_check_in) return undefined;
    registerStaffWorker();
    const off = door.subscribe(setDoorStatus);
    door.refreshPack();
    const tick = setInterval(() => door.sync(), 30000);
    const onOnline = () => door.sync();
    window.addEventListener('online', onOnline);
    return () => {
        off();
        clearInterval(tick);
        window.removeEventListener('online', onOnline);
    };
}, [door, link.can_check_in]);
```

Pass `door={door}` to `<CheckInPanel>`. Under the header, render the status line:

```jsx
{link.can_check_in && (
    <div className={`px-4 py-1.5 text-xs ${doorStatus.online ? 'text-ink-secondary' : 'bg-warning-bg text-warning-fg'}`}>
        {doorStatus.closed
            ? 'This link has been switched off.'
            : doorStatus.online
              ? `Live · ready offline with ${doorStatus.guests.toLocaleString()} guests`
              : `Offline · ${doorStatus.queued} waiting to send · ${doorStatus.guests.toLocaleString()} guests stored`}
        {doorStatus.packUpdatedAt && Date.now() - new Date(doorStatus.packUpdatedAt) > 12 * 3600 * 1000 && (
            <span className="ml-1 text-warning-fg">
                · guest list from {new Date(doorStatus.packUpdatedAt).toLocaleString()}, connect to refresh
            </span>
        )}
        {doorStatus.attention.length > 0 && (
            <div className="mt-1 text-danger-fg">Needs attention: {doorStatus.attention.join(' ')}</div>
        )}
    </div>
)}
```

In `Requests`, when the fetch fails, show "Requests need a connection. They will appear when you are back online." instead of the empty list. After each live check-in (`door.status().online`), counts refresh via the existing 30 s poll — unchanged.

- [ ] **Step 5: Run Vitest (whole suite), ESLint, Prettier, a production build** — `npx vitest run`, `npx eslint resources/js/Pages/Public/Staff resources/js/Pages/Tenant/Events/CheckInPanel.jsx resources/js/lib/staffDoor`, `node node_modules/vite/bin/vite.js build`. Then `git checkout -- public/build && git clean -fq -- public/build` (Cloud builds assets itself).

- [ ] **Step 6: Commit** — `feat(staff-links): scan and search offline, with a status line and an offline page`.

---

### Task 6: Verify end to end, guide, deploy

**Files:**
- Modify: `docs/demo/DEMO-GUIDE.md` (git-ignored; section E and "Not in the product yet")

- [ ] **Step 1: Full PHP suite in two halves and Vitest** (each half under 10 minutes; see memory `postgres_only_and_ci`).
- [ ] **Step 2: PHPStan clean; Pint clean.**
- [ ] **Step 3: Push to main; wait for the Cloud deployment to succeed; migrations run on deploy.**
- [ ] **Step 4: Re-seed the live demo** (`ProbeWorkspaceSeeder`).
- [ ] **Step 5: Playwright on the live demo, phone-sized:** open Floor – Efua's link; wait for "Live · ready offline with N guests"; `context.setOffline(true)`; reload — the page still opens; manual search finds a not-yet-arrived guest and checks them in "(offline)"; the status shows "1 waiting to send"; `setOffline(false)`; within 30 s the status returns to Live with 0 waiting; verify in the database the registration is checked in, credited to Efua, with the offline scan time.
- [ ] **Step 6: Re-seed the live demo again** so it starts clean.
- [ ] **Step 7: Update the demo guide** — section E gains "Offline: switch the phone to airplane mode, scan or search, then reconnect; the console shows the entry credited to the usher"; remove "Scanning without a connection" from "Not in the product yet".
