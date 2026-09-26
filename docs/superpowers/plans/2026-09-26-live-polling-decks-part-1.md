# Live Polling Decks — Part 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** turn MiConvener's single live poll into a presenter-paced deck that a registered room answers from the portal they already use.

**Architecture:** a `poll_decks` table orders existing `event_polls` and holds a pointer to the current question; the presenter moves that pointer and every surface follows it. Voting stays where it already works — the attendee workspace's Live poll tab — gated on a confirmed, checked-in registration. Broadcasts are coalesced to one per poll per second and counts move to SQL, so a thousand answers in ten seconds cost one broadcast a second rather than a thousand synchronous ones.

**Tech Stack:** Laravel 13 (Laravel 10 file layout), PHP 8.5, PostgreSQL, Pest 5, Inertia 3 + React 19, Reverb, Tailwind 4.

**Spec:** `docs/superpowers/specs/2026-09-26-live-polling-decks-design.md`

## Global Constraints

- **Only registered, present people vote.** A vote requires a confirmed registration for that event and a non-null `checked_in_at`. Support staff vote by holding a comped registration.
- **The wall and its broadcast carry counts, never people.** No respondent token, no registration id, no name, no attributable answer. Open text carries a display name only where the question asked for one and only once approved.
- **Voting is limited per registration, never per IP address.** Per-address limits punish a WiFi crowd.
- **A poll that is not the deck's `current_poll_id` is refused at the endpoint**, not merely hidden.
- **`Event::uploadDisk()`** is the only way to name a storage disk. Never hardcode a disk.
- **No `env()` outside `config/`.** Read configuration through `config()`.
- **Every model relation this plan touches gets generics** (`@return BelongsTo<Related, $this>`), because untyped relations were the source of five prior PHPStan baseline entries.
- **Commands:** `php artisan test --compact --filter=<name>`, `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`. PHP is Herd's: prefix with `PATH="$HOME/Library/Application Support/Herd/bin:$PATH"`.
- **Never add a PHPStan baseline entry.** Fix the underlying type.

## Review Focus

Five things the spec implies, that no task's happy path exercises, most likely to bite first. Each has a test added to the task that owns the code.

1. **A registration that is confirmed but never checked in tries to vote** — must be refused with a message naming *which* condition failed, not a blank 403. (Task 3)
2. **A presenter advances while someone is mid-answer** — the in-flight vote lands on the previous question and must be refused, not silently recorded against the new one. (Task 5)
3. **Two devices, one registration, same question** — the second must be refused; today's uniqueness is on `respondent_token`, which a registration-derived token must preserve. (Task 3)
4. **A deck whose event has no checked-in attendees at all** — the wall must say so rather than rendering a chart of zeroes that looks like unanimous silence. (Task 7)
5. **A speaker row with no email** — must not crash recognition in `/my`, and must be visibly flagged to the organiser rather than silently unreachable. (Task 2)

---

## File Structure

**Created:**
- `database/migrations/landlord/*_add_self_check_in_to_event_registrations_table.php` — `checked_in_source`
- `database/migrations/landlord/*_add_email_to_speakers_table.php` — `email`
- `database/migrations/landlord/*_create_poll_decks_table.php`
- `database/migrations/landlord/*_add_deck_to_event_polls_table.php` — `deck_id`, `position`
- `database/migrations/landlord/*_index_event_poll_responses_poll_option.php`
- `app/Models/PollDeck.php`
- `app/Services/Events/SelfCheckIn.php` — one place that decides whether self check-in is allowed and performs it
- `app/Services/Events/DeckPresenter.php` — advance, close, reveal, end; the only writer of `current_poll_id`
- `app/Services/Events/PollBroadcastCoalescer.php` — at most one broadcast per poll per second
- `app/Http/Controllers/Tenant/EventPollDeckController.php` — organiser CRUD and presenter controls
- Tests mirroring each of the above under `tests/Feature/Events/`

**Modified:**
- `app/Models/EventRegistration.php` — `checked_in_source` fillable, `isPresent()`
- `app/Models/Speaker.php` — `email` fillable
- `app/Models/EventPoll.php` — `deck()` relation, `isCurrent()`
- `app/Services/Events/PollResults.php` — SQL counting
- `app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php` — vote gating, deck-aware poll payload, self check-in endpoint
- `app/Http/Controllers/Public/PollPresentationController.php` — follow the deck pointer
- `routes/subdomain.php`, `routes/attendee.php`
- `resources/js/Pages/Public/Events/AttendeePortal/panels/PollPanel.jsx`
- `resources/js/Pages/Public/Events/PollPresentation.jsx`

---

## Task 1: Self check-in

Unblocks everything else: the voting gate in Task 3 requires presence, and today a virtual event can mark nobody present.

**Files:**
- Create: `database/migrations/landlord/2026_09_26_150000_add_self_check_in_to_event_registrations_table.php`
- Create: `app/Services/Events/SelfCheckIn.php`
- Modify: `app/Models/EventRegistration.php`
- Modify: `app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php`
- Modify: `routes/attendee.php`
- Test: `tests/Feature/Events/SelfCheckInTest.php`

**Interfaces:**
- Produces: `EventRegistration::isPresent(): bool`; `SelfCheckIn::isAvailableFor(EventRegistration $r): bool`; `SelfCheckIn::perform(EventRegistration $r): bool`; route `attendee.my.events.check-in` (POST `/my/events/{registration}/check-in`)

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Events/SelfCheckInTest.php
test('an attendee at a virtual event can mark themselves present', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin');
    $host = app(TenantHostMatcher::class)->baseDomain();

    expect($registration->checked_in_at)->toBeNull();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('checked_in', true);

    $registration->refresh();

    expect($registration->checked_in_at)->not->toBeNull()
        ->and($registration->checked_in_source)->toBe('self')
        ->and($registration->checked_in_by)->toBeNull()
        ->and($registration->status)->toBe(EventRegistration::STATUS_CHECKED_IN);
});

test('self check-in is closed before the event and after it ends', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin-window');
    $host = app(TenantHostMatcher::class)->baseDomain();

    $event->update(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($registration->fresh()->checked_in_at)->toBeNull();
});

test('an in-person event does not offer self check-in unless the organiser turns it on', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin-inperson');
    $event->update(['location_type' => Event::LOCATION_IN_PERSON]);
    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    $event->update(['allows_self_check_in' => true]);

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/check-in", [], ['HTTP_HOST' => $host])
        ->assertOk();
});

test('a door scan is still distinguishable from a self report', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('self-checkin-source');

    app(SelfCheckIn::class)->perform($registration);

    expect($registration->fresh()->checked_in_source)->toBe('self');
});
```

Helpers at the top of the file:

```php
function virtualEventScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'location_type' => Event::LOCATION_VIRTUAL,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(4),
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'present@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    return [$tenant, $event, $registration];
}

function proofFor(string $email): array
{
    return [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ];
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/SelfCheckInTest.php`
Expected: FAIL — route `/my/events/{id}/check-in` does not exist (404).

- [ ] **Step 3: Migration**

```php
// database/migrations/landlord/2026_09_26_150000_add_self_check_in_to_event_registrations_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            // A door scan and a self report are both presence, and an organiser
            // reconciling a room needs to tell them apart.
            $table->string('checked_in_source', 16)->nullable()->after('checked_in_by');
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->boolean('allows_self_check_in')->default(false)->after('location_type');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn('checked_in_source');
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn('allows_self_check_in');
        });
    }
};
```

Add `'checked_in_source'` to `EventRegistration::$fillable` and `'allows_self_check_in'` to `Event::$fillable`, with `'allows_self_check_in' => 'boolean'` in `Event::casts()`.

- [ ] **Step 4: `isPresent()` on the registration**

```php
// app/Models/EventRegistration.php
/**
 * Present means someone recorded this person as being here -- scanned at a
 * door, or self-reported at a virtual event. Confirmed is not present: a
 * ticket bought in March says nothing about the room in September.
 */
public function isPresent(): bool
{
    return $this->checked_in_at !== null;
}
```

- [ ] **Step 5: The service**

```php
// app/Services/Events/SelfCheckIn.php
final class SelfCheckIn
{
    public const string SOURCE = 'self';

    /**
     * Offered while the event is running, to a confirmed registration, at a
     * virtual event or an in-person one whose organiser has asked for it.
     */
    public function isAvailableFor(EventRegistration $registration): bool
    {
        $event = $registration->event;

        if ($event === null || ! $registration->isConfirmed()) {
            return false;
        }

        if (! $event->starts_at->isPast() || ! $event->ends_at->isFuture()) {
            return false;
        }

        return $event->location_type === Event::LOCATION_VIRTUAL
            || (bool) $event->allows_self_check_in;
    }

    /**
     * Records presence the same way a door scan does, but leaves checked_in_by
     * null -- nobody checked this person in but themselves.
     */
    public function perform(EventRegistration $registration): bool
    {
        if ($registration->isPresent()) {
            return true;
        }

        if (! $this->isAvailableFor($registration)) {
            return false;
        }

        $registration->update([
            'status' => EventRegistration::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
            'checked_in_by' => null,
            'checked_in_source' => self::SOURCE,
        ]);

        return true;
    }
}
```

- [ ] **Step 6: Endpoint and route**

```php
// app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php
public function checkIn(Request $request, string $registration): JsonResponse
{
    $registrationModel = EventRegistration::withoutGlobalScopes()
        ->where('id', $registration)
        ->with(['event' => fn ($q) => $q->withoutGlobalScopes()])
        ->first();

    if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }

    if (! app(SelfCheckIn::class)->perform($registrationModel)) {
        return response()->json([
            'message' => 'Check-in is open while the event is running.',
        ], 422);
    }

    return response()->json(['checked_in' => true]);
}
```

```php
// routes/attendee.php, inside the events/{registration} prefix group
Route::post('/check-in', [PlatformAttendeeWorkspaceController::class, 'checkIn'])->name('attendee.my.events.check-in');
```

Add `'self_check_in_available' => app(SelfCheckIn::class)->isAvailableFor($registrationModel)` to the `show()` Inertia payload beside `checked_in`.

- [ ] **Step 7: Run the tests**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/SelfCheckInTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Commit**

```bash
php vendor/bin/pint --dirty
git add database/migrations app/Models/EventRegistration.php app/Models/Event.php app/Services/Events/SelfCheckIn.php app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php routes/attendee.php tests/Feature/Events/SelfCheckInTest.php
git commit -m "feat(events): let people at a virtual event say they are here

Check-in was a door operation: staff scanned or searched, and nothing on the
attendee side ever wrote it. A virtual event could therefore mark nobody
present and produced no attendance record at all, which matters for
certificates claiming hours.

Self check-in records presence the same way a scan does, noting the source so
an organiser reconciling a room can tell the two apart. Offered while the
event runs, at virtual events always and in-person ones by setting."
```

---

## Task 2: Speakers have an email, and are recognised

**Files:**
- Create: `database/migrations/landlord/2026_09_26_151000_add_email_to_speakers_table.php`
- Modify: `app/Models/Speaker.php`
- Modify: `app/Http/Controllers/Tenant/EventSpeakerController.php`
- Test: `tests/Feature/Events/SpeakerEmailTest.php`

**Interfaces:**
- Produces: `speakers.email` (nullable, required by the form); `Speaker::forEmail(string $email)` scope

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Events/SpeakerEmailTest.php
test('a speaker cannot be created without an address', function (): void {
    [$tenant, $user] = eventHost('speaker-email');
    $host = eventSubdomainHost('speaker-email');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/speakers", [
            'name' => 'Dr Ama Serwaa',
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('a speaker is found by address, however it was typed', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'speaker-match', 'isolation_mode' => 'shared']);
    $speaker = Speaker::create([
        'tenant_id' => $tenant->id,
        'name' => 'Dr Ama Serwaa',
        'email' => 'Ama.Serwaa@Example.com',
    ]);

    expect(Speaker::forEmail('  ama.serwaa@example.com ')->first()?->id)->toBe($speaker->id);
});

test('a legacy speaker with no address neither breaks nor hides', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'speaker-legacy', 'isolation_mode' => 'shared']);
    $speaker = Speaker::create(['tenant_id' => $tenant->id, 'name' => 'Nameless Legacy']);

    // Recognition must not explode on a null, and the row must stay visible so
    // an organiser can fix it rather than discover it at an event.
    expect(Speaker::forEmail('anything@example.com')->count())->toBe(0)
        ->and($speaker->fresh()->email)->toBeNull()
        ->and($speaker->needsEmail())->toBeTrue();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/SpeakerEmailTest.php`
Expected: FAIL — `email` column does not exist.

- [ ] **Step 3: Migration**

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('speakers', function (Blueprint $table): void {
            // Nullable because rows already exist without one; the form requires
            // it, so no new speaker can be created without an address. The column
            // becomes non-nullable once the backfill is done.
            $table->string('email')->nullable()->after('name');
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('speakers', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'email']);
            $table->dropColumn('email');
        });
    }
};
```

- [ ] **Step 4: Model**

```php
// app/Models/Speaker.php — add 'email' to $fillable, then:

/**
 * Addresses are compared without regard to case or stray spacing, the same
 * way the attendee portal compares them, so a speaker typed in by an
 * organiser still matches the address they verify with.
 *
 * @param  Builder<Speaker>  $query
 * @return Builder<Speaker>
 */
public function scopeForEmail(Builder $query, string $email): Builder
{
    return $query->whereRaw(
        'lower('.$query->qualifyColumn('email').') = ?',
        [mb_strtolower(mb_trim($email))]
    );
}

/** A speaker without an address cannot reach their portal. */
public function needsEmail(): bool
{
    return blank($this->email);
}
```

- [ ] **Step 5: Require it on the form**

In `EventSpeakerController`'s store and update validation, add:

```php
'email' => ['required', 'email', 'max:255'],
```

- [ ] **Step 6: Recognise a speaker in their own workspace**

The spec's Part 1 asks for recognition, not just the column. Add to the test file:

```php
test('a speaker sees that they are speaking, from the ticket they already hold', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'speaker-portal', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'ama@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $speaker = Speaker::create([
        'tenant_id' => $tenant->id,
        'name' => 'Dr Ama Serwaa',
        'email' => 'Ama@Example.com',
        'title' => 'Head of Digital Health',
    ]);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
    ]);

    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor('ama@example.com'))
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('speaker.name', 'Dr Ama Serwaa')
            ->where('speaker.title', 'Head of Digital Health'));
});

test('an attendee who is not speaking is told nothing about speakers', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('speaker-not');

    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('speaker', null));
});
```

In `PlatformAttendeeWorkspaceController::show()`, add to the props:

```php
// A speaker is an attendee who also speaks: same ticket, same portal, one
// extra section. Matched on the address they verified with, so nothing new
// has to be proven.
'speaker' => $this->speakerFor($event, $registrationModel),
```

```php
/**
 * @return array<string, mixed>|null
 */
private function speakerFor(Event $event, EventRegistration $registration): ?array
{
    $speaker = Speaker::withoutGlobalScopes()
        ->forEmail($registration->email)
        ->where('tenant_id', $registration->tenant_id)
        ->whereHas('eventSpeakers', fn (Builder $q) => $q->withoutGlobalScopes()->where('event_id', $event->id))
        ->first();

    if (! $speaker instanceof Speaker) {
        return null;
    }

    return [
        'name' => $speaker->name,
        'title' => $speaker->title,
        'organization' => $speaker->organization,
    ];
}
```

If `Speaker` has no `eventSpeakers` relation, add one:

```php
/** @return HasMany<EventSpeaker, $this> */
public function eventSpeakers(): HasMany
{
    return $this->hasMany(EventSpeaker::class);
}
```

- [ ] **Step 7: Run the tests**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/SpeakerEmailTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Commit**

```bash
php vendor/bin/pint --dirty
git add database/migrations app/Models/Speaker.php app/Http/Controllers tests/Feature/Events/SpeakerEmailTest.php
git commit -m "feat(speakers): give a speaker an address to be known by

Speakers held name, title, organisation, bio and photo -- no email at all,
which is why their portal is reached by a forwardable token and why nothing
has ever been able to email them a deadline.

The column is nullable because rows exist without one, and the form requires
it so none can be created that way again. needsEmail() marks the rows still
to be filled in: a speaker without an address cannot reach their portal, and
that should be visible rather than discovered at an event."
```

---

## Task 3: Voting requires a present registration

**Files:**
- Modify: `app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php:593` (`respondPoll`)
- Modify: `app/Http/Controllers/Public/PublicPollController.php`
- Test: `tests/Feature/Events/PollVotingGateTest.php`

**Interfaces:**
- Consumes: `EventRegistration::isPresent()` from Task 1
- Produces: the respondent token derivation stays `hash_hmac('sha256', "poll:{$registration->id}:{$poll->id}", config('app.key'))`, unchanged, so one registration is one vote across devices

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Events/PollVotingGateTest.php
test('a confirmed attendee who has not checked in is told which condition failed', function (): void {
    [$tenant, $event, $poll, $option, $registration] = votingScenario('vote-not-present');
    $host = app(TenantHostMatcher::class)->baseDomain();

    $response = $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(403);

    // Not a blank refusal: a person standing in a room needs to know it is the
    // check-in desk they want, not the help desk.
    expect($response->json('message'))->toContain('checked in');
});

test('a present attendee votes', function (): void {
    [$tenant, $event, $poll, $option, $registration] = votingScenario('vote-present');
    $registration->update(['checked_in_at' => now(), 'status' => EventRegistration::STATUS_CHECKED_IN]);
    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($poll->responses()->count())->toBe(1);
});

test('a second device cannot vote again for the same registration', function (): void {
    [$tenant, $event, $poll, $option, $registration] = votingScenario('vote-twice');
    $registration->update(['checked_in_at' => now(), 'status' => EventRegistration::STATUS_CHECKED_IN]);
    $host = app(TenantHostMatcher::class)->baseDomain();

    $vote = fn () => $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host]);

    $vote()->assertOk();

    // A fresh session stands in for a second phone: the token is derived from
    // the registration, so clearing storage earns nothing.
    $vote()->assertStatus(422);

    expect($poll->responses()->count())->toBe(1);
});
```

Helper:

```php
function votingScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(3),
    ]);
    $poll = EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'Are you with us?',
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_LIVE,
        'went_live_at' => now()->subMinutes(2),
    ]);
    $option = EventPollOption::create([
        'tenant_id' => $tenant->id,
        'poll_id' => $poll->id,
        'label' => 'Yes',
        'sort_order' => 0,
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'voter@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    return [$tenant, $event, $poll, $option, $registration];
}
```

Reuse `proofFor()` by importing it — Pest shares top-level functions across the suite, so define it once in Task 1's file and do not redeclare it here.

- [ ] **Step 2: Run it and watch it fail**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollVotingGateTest.php`
Expected: FAIL — the first test gets 200, because an unchecked-in attendee can vote today.

- [ ] **Step 3: Gate the workspace vote**

In `respondPoll`, immediately after the `canAccess` check:

```php
if (! $registrationModel->isPresent()) {
    return response()->json([
        'message' => 'You need to be checked in to answer. Check in from your ticket, or ask at the desk.',
    ], 403);
}
```

- [ ] **Step 4: Close the public vote route**

`PublicPollController::respond` accepts a caller-supplied `respondent_token` and takes anonymous votes. Decision 2 ends that. Replace its body's opening with a refusal that points at the portal:

```php
// A poll is answered from the portal now, where the voter is a known,
// checked-in registration. This route stays so an old QR or a bookmarked page
// says something useful rather than 404ing in someone's hand.
return response()->json([
    'message' => 'Open your ticket in the MiConvener portal to answer.',
    'portal_url' => url('/my'),
], 410);
```

- [ ] **Step 5: Run the tests**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollVotingGateTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Repair the suites this breaks**

`PollPresentationTest` has a test named *"voting moves the screen without anyone reloading it"* which posts to the now-closed public route. Rewrite it to vote through the workspace with a present registration. Run the whole Events directory and fix every failure the gate causes — they are all the same shape.

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
php vendor/bin/pint --dirty
git add app/Http/Controllers/Public tests/Feature/Events
git commit -m "feat(polls): a vote comes from someone who is registered and here

Anyone who could reach the page could answer, with a respondent token they
supplied themselves. A poll asked in a room is now answered by a confirmed
registration that has been checked in, so one person is one vote and an answer
can count toward attendance.

The refusal names which of the two conditions failed. Someone standing in a
hall needs to know it is the check-in desk they want."
```

---

## Task 4: The deck

**Files:**
- Create: `database/migrations/landlord/2026_09_26_152000_create_poll_decks_table.php`
- Create: `database/migrations/landlord/2026_09_26_152100_add_deck_to_event_polls_table.php`
- Create: `app/Models/PollDeck.php`
- Modify: `app/Models/EventPoll.php`
- Test: `tests/Feature/Events/PollDeckTest.php`

**Interfaces:**
- Produces: `PollDeck` with `$fillable = ['tenant_id','event_id','session_id','title','join_code','status','current_poll_id','present_token']`; constants `STATUS_DRAFT='draft'`, `STATUS_LIVE='live'`, `STATUS_ENDED='ended'`; `PollDeck::polls(): HasMany` ordered by `position`; `PollDeck::currentPoll(): BelongsTo`; `PollDeck::generateJoinCode(): string`; `EventPoll::deck(): BelongsTo`; `EventPoll::isCurrent(): bool`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Events/PollDeckTest.php
test('a deck orders its questions and knows where the presenter is standing', function (): void {
    [$tenant, $event] = deckScenario('deck-order');

    $deck = PollDeck::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Opening plenary',
        'join_code' => PollDeck::generateJoinCode(),
        'status' => PollDeck::STATUS_DRAFT,
    ]);

    $second = deckPoll($tenant, $event, $deck, 'Second question', 1);
    $first = deckPoll($tenant, $event, $deck, 'First question', 0);

    expect($deck->polls()->pluck('question')->all())
        ->toBe(['First question', 'Second question']);

    $deck->update(['current_poll_id' => $first->id]);

    expect($first->fresh()->isCurrent())->toBeTrue()
        ->and($second->fresh()->isCurrent())->toBeFalse();
});

test('a join code avoids the characters people misread aloud', function (): void {
    foreach (range(1, 50) as $ignored) {
        expect(PollDeck::generateJoinCode())
            ->not->toContain('O')->not->toContain('0')
            ->not->toContain('I')->not->toContain('1');
    }
});

test('a poll with no deck behaves exactly as it did', function (): void {
    [$tenant, $event] = deckScenario('deck-none');

    $poll = EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'Standalone',
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_LIVE,
        'went_live_at' => now(),
    ]);

    expect($poll->deck_id)->toBeNull()
        ->and($poll->isCurrent())->toBeTrue();
});
```

Helpers:

```php
function deckScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $event];
}

function deckPoll(Tenant $tenant, Event $event, PollDeck $deck, string $question, int $position): EventPoll
{
    return EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'deck_id' => $deck->id,
        'position' => $position,
        'question' => $question,
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_DRAFT,
    ]);
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollDeckTest.php`
Expected: FAIL — class `PollDeck` not found.

- [ ] **Step 3: Migrations**

```php
// create_poll_decks_table
Schema::connection('landlord')->create('poll_decks', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
    $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
    $table->foreignUuid('session_id')->nullable()->constrained('event_sessions')->nullOnDelete();
    $table->string('title');
    $table->string('join_code', 8)->nullable()->unique();
    $table->string('status', 16)->default('draft');
    $table->uuid('current_poll_id')->nullable();
    $table->string('present_token', 64)->nullable()->unique();
    $table->timestamps();

    $table->index(['event_id', 'status']);
});
```

```php
// add_deck_to_event_polls_table
Schema::connection('landlord')->table('event_polls', function (Blueprint $table): void {
    $table->foreignUuid('deck_id')->nullable()->after('event_id')->constrained('poll_decks')->cascadeOnDelete();
    $table->unsignedInteger('position')->default(0)->after('deck_id');
    $table->index(['deck_id', 'position']);
});
```

- [ ] **Step 4: The model**

```php
// app/Models/PollDeck.php
final class PollDeck extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_LIVE = 'live';
    public const string STATUS_ENDED = 'ended';

    /**
     * Read aloud across a room and typed on a phone, so the alphabet leaves out
     * the pairs people confuse: O and zero, I and one.
     */
    private const string CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'event_id', 'session_id', 'title',
        'join_code', 'status', 'current_poll_id', 'present_token',
    ];

    public static function generateJoinCode(): string
    {
        $code = '';
        $alphabet = self::CODE_ALPHABET;

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, mb_strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** @return HasMany<EventPoll, $this> */
    public function polls(): HasMany
    {
        return $this->hasMany(EventPoll::class, 'deck_id')->orderBy('position');
    }

    /** @return BelongsTo<EventPoll, $this> */
    public function currentPoll(): BelongsTo
    {
        return $this->belongsTo(EventPoll::class, 'current_poll_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
```

On `EventPoll`, add `'deck_id'` and `'position'` to `$fillable`, plus:

```php
/** @return BelongsTo<PollDeck, $this> */
public function deck(): BelongsTo
{
    return $this->belongsTo(PollDeck::class, 'deck_id');
}

/**
 * A poll outside a deck is always its own current question; inside one, only
 * the deck's pointer decides.
 */
public function isCurrent(): bool
{
    if ($this->deck_id === null) {
        return true;
    }

    return $this->deck?->current_poll_id === $this->id;
}
```

- [ ] **Step 5: Run the tests**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollDeckTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
php vendor/bin/pint --dirty
git add database/migrations app/Models tests/Feature/Events/PollDeckTest.php
git commit -m "feat(polls): a deck, and a pointer to the question being asked

An organiser set each poll live by hand, one at a time. A deck orders them and
holds current_poll_id -- where the presenter is standing -- which every surface
can follow rather than each deciding for itself what is live.

A poll with no deck is unchanged and is always its own current question, so
the existing single-poll flow keeps working untouched."
```

---

## Task 5: Presenter controls

**Files:**
- Create: `app/Services/Events/DeckPresenter.php`
- Create: `app/Http/Controllers/Tenant/EventPollDeckController.php`
- Modify: `routes/subdomain.php`
- Test: `tests/Feature/Events/DeckPresenterTest.php`

**Interfaces:**
- Consumes: `PollDeck`, `EventPoll::isCurrent()` from Task 4
- Produces: `DeckPresenter::start(PollDeck $d): void`, `advance(PollDeck $d): ?EventPoll`, `previous(PollDeck $d): ?EventPoll`, `closeCurrent(PollDeck $d): void`, `end(PollDeck $d): void`; routes `tenant.events.decks.{start,advance,previous,close,end}`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Events/DeckPresenterTest.php
test('advancing closes the question behind it and opens the next', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('presenter-advance');

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);

    expect($deck->fresh()->current_poll_id)->toBe($first->id)
        ->and($first->fresh()->status)->toBe(EventPoll::STATUS_LIVE);

    $presenter->advance($deck->fresh());

    expect($deck->fresh()->current_poll_id)->toBe($second->id)
        ->and($first->fresh()->status)->toBe(EventPoll::STATUS_CLOSED)
        ->and($second->fresh()->status)->toBe(EventPoll::STATUS_LIVE);
});

test('a vote arriving after the presenter moved on is refused, not recorded', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('presenter-stale');
    $option = EventPollOption::create([
        'tenant_id' => $tenant->id, 'poll_id' => $first->id, 'label' => 'Yes', 'sort_order' => 0,
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'late@example.com',
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now(),
    ]);

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->advance($deck->fresh());

    $host = app(TenantHostMatcher::class)->baseDomain();

    // Someone answering as the slide changed. Their answer belongs to a
    // question the room has left, and counting it would put it on the wrong bar.
    $this->withSession(proofFor($registration->email))
        ->postJson("http://{$host}/my/events/{$registration->id}/poll/{$first->id}/respond", [
            'option_id' => $option->id,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($first->responses()->count())->toBe(0);
});

test('ending a deck closes whatever was open', function (): void {
    [$tenant, $event, $deck, $first] = presenterScenario('presenter-end');

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->end($deck->fresh());

    expect($deck->fresh()->status)->toBe(PollDeck::STATUS_ENDED)
        ->and($deck->fresh()->current_poll_id)->toBeNull()
        ->and($first->fresh()->status)->toBe(EventPoll::STATUS_CLOSED);
});

test('advancing past the last question does not wrap around', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('presenter-last');

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->advance($deck->fresh());

    expect($presenter->advance($deck->fresh()))->toBeNull()
        ->and($deck->fresh()->current_poll_id)->toBe($second->id);
});
```

Helper:

```php
function presenterScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(3),
    ]);
    $deck = PollDeck::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Plenary',
        'join_code' => PollDeck::generateJoinCode(),
        'status' => PollDeck::STATUS_DRAFT,
    ]);
    $first = deckPoll($tenant, $event, $deck, 'First', 0);
    $second = deckPoll($tenant, $event, $deck, 'Second', 1);

    return [$tenant, $event, $deck, $first, $second];
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/DeckPresenterTest.php`
Expected: FAIL — class `DeckPresenter` not found.

- [ ] **Step 3: The service**

```php
// app/Services/Events/DeckPresenter.php
final class DeckPresenter
{
    /**
     * The only writer of current_poll_id. Moving the pointer and changing the
     * two polls' statuses happen together or not at all: a room that saw the
     * question change must never be voting on one that is still open behind it.
     */
    public function start(PollDeck $deck): void
    {
        $first = $deck->polls()->first();

        if ($first === null) {
            return;
        }

        DB::transaction(function () use ($deck, $first): void {
            $deck->update(['status' => PollDeck::STATUS_LIVE, 'current_poll_id' => $first->id]);
            $first->update(['status' => EventPoll::STATUS_LIVE, 'went_live_at' => now()]);
        });

        PollResultsUpdated::dispatch($first->fresh(['options', 'responses']));
    }

    public function advance(PollDeck $deck): ?EventPoll
    {
        return $this->moveTo($deck, $this->neighbour($deck, forward: true));
    }

    public function previous(PollDeck $deck): ?EventPoll
    {
        return $this->moveTo($deck, $this->neighbour($deck, forward: false));
    }

    public function closeCurrent(PollDeck $deck): void
    {
        $deck->currentPoll?->update(['status' => EventPoll::STATUS_CLOSED]);

        if ($deck->currentPoll !== null) {
            PollResultsUpdated::dispatch($deck->currentPoll->fresh(['options', 'responses']));
        }
    }

    public function end(PollDeck $deck): void
    {
        DB::transaction(function () use ($deck): void {
            $deck->currentPoll?->update(['status' => EventPoll::STATUS_CLOSED]);
            $deck->update(['status' => PollDeck::STATUS_ENDED, 'current_poll_id' => null]);
        });
    }

    private function neighbour(PollDeck $deck, bool $forward): ?EventPoll
    {
        $current = $deck->currentPoll;

        if ($current === null) {
            return $forward ? $deck->polls()->first() : null;
        }

        return $deck->polls()
            ->where('position', $forward ? '>' : '<', $current->position)
            ->orderBy('position', $forward ? 'asc' : 'desc')
            ->first();
    }

    private function moveTo(PollDeck $deck, ?EventPoll $next): ?EventPoll
    {
        if ($next === null) {
            return null;
        }

        $outgoing = $deck->currentPoll;

        DB::transaction(function () use ($deck, $next, $outgoing): void {
            $outgoing?->update(['status' => EventPoll::STATUS_CLOSED]);
            $next->update(['status' => EventPoll::STATUS_LIVE, 'went_live_at' => now()]);
            $deck->update(['current_poll_id' => $next->id]);
        });

        PollResultsUpdated::dispatch($next->fresh(['options', 'responses']));

        return $next;
    }
}
```

- [ ] **Step 4: Refuse stale votes**

In `respondPoll`, after the presence check from Task 3, replace the poll lookup so a deck's pointer is honoured:

```php
$pollModel = $event->polls()->where('id', $poll)->with('options')->firstOrFail();

if (! $pollModel->isCurrent() || $pollModel->status !== EventPoll::STATUS_LIVE) {
    return response()->json([
        'message' => 'That question has closed.',
    ], 422);
}
```

This replaces the existing `->live()` scope call, which cannot see a deck.

- [ ] **Step 5: Controller and routes**

```php
// app/Http/Controllers/Tenant/EventPollDeckController.php
final class EventPollDeckController extends Controller
{
    public function __construct(private readonly DeckPresenter $presenter) {}

    public function start(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->start($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    public function advance(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->advance($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    public function previous(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->previous($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    public function close(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->closeCurrent($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    public function end(string $subdomain, string $event, string $deck): JsonResponse
    {
        $this->presenter->end($this->findDeck($event, $deck));

        return $this->payload($event, $deck);
    }

    private function findDeck(string $event, string $deck): PollDeck
    {
        $this->authorize('update event');

        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        return PollDeck::where('tenant_id', $tenant->id)
            ->where('event_id', $event)
            ->where('id', $deck)
            ->with(['polls', 'currentPoll'])
            ->firstOrFail();
    }

    private function payload(string $event, string $deck): JsonResponse
    {
        $fresh = $this->findDeck($event, $deck);

        return response()->json([
            'id' => $fresh->id,
            'status' => $fresh->status,
            'join_code' => $fresh->join_code,
            'current_poll_id' => $fresh->current_poll_id,
            'position' => $fresh->currentPoll?->position,
            'total' => $fresh->polls->count(),
        ]);
    }
}
```

```php
// routes/subdomain.php, in the authenticated tenant group beside the poll routes
Route::post('events/{event}/decks/{deck}/start', [EventPollDeckController::class, 'start'])->name('tenant.events.decks.start');
Route::post('events/{event}/decks/{deck}/advance', [EventPollDeckController::class, 'advance'])->name('tenant.events.decks.advance');
Route::post('events/{event}/decks/{deck}/previous', [EventPollDeckController::class, 'previous'])->name('tenant.events.decks.previous');
Route::post('events/{event}/decks/{deck}/close', [EventPollDeckController::class, 'close'])->name('tenant.events.decks.close');
Route::post('events/{event}/decks/{deck}/end', [EventPollDeckController::class, 'end'])->name('tenant.events.decks.end');
```

- [ ] **Step 6: Run the tests**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/DeckPresenterTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Commit**

```bash
php vendor/bin/pint --dirty
git add app/Services/Events/DeckPresenter.php app/Http/Controllers/Tenant/EventPollDeckController.php routes/subdomain.php app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php tests/Feature/Events/DeckPresenterTest.php
git commit -m "feat(polls): let the presenter hold the pace

Next, previous, close, end -- one service, the only writer of the deck's
pointer. Moving it and changing the two polls' statuses happen in one
transaction, because a room that has seen the question change must never be
voting on the one still open behind it.

A vote for a question the deck has left is refused outright rather than
counted. Someone answering as the slide changes would otherwise have their
answer land on the wrong bar."
```

---

## Task 6: Counting in SQL, broadcasting once a second

**Files:**
- Create: `database/migrations/landlord/2026_09_26_153000_index_event_poll_responses_poll_option.php`
- Create: `app/Services/Events/PollBroadcastCoalescer.php`
- Modify: `app/Services/Events/PollResults.php`
- Modify: `app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php`
- Test: `tests/Feature/Events/PollScaleTest.php`

**Interfaces:**
- Produces: `PollBroadcastCoalescer::schedule(EventPoll $poll): bool` — true when it broadcast, false when one was already pending

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Events/PollScaleTest.php
test('a hundred votes do not cause a hundred broadcasts', function (): void {
    EventFacade::fake([PollResultsUpdated::class]);

    [$tenant, $event, $poll, $option] = scaleScenario('scale-coalesce');
    $coalescer = app(PollBroadcastCoalescer::class);

    for ($i = 0; $i < 100; $i++) {
        $coalescer->schedule($poll);
    }

    // The number is the point: a wall updating once a second reads as live to a
    // room, and costs a hundredth of what updating per vote costs.
    EventFacade::assertDispatchedTimes(PollResultsUpdated::class, 1);
});

test('counting a thousand answers does not load a thousand rows', function (): void {
    [$tenant, $event, $poll, $option] = scaleScenario('scale-counting');

    foreach (range(1, 1000) as $i) {
        EventPollResponse::create([
            'tenant_id' => $tenant->id,
            'poll_id' => $poll->id,
            'option_id' => $option->id,
            'respondent_token' => "voter-{$i}",
            'is_approved' => true,
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void { $queries++; });

    $results = app(PollResults::class)->forDisplay($poll->fresh());

    expect($results['total_responses'])->toBe(1000)
        ->and($results['options'][0]['count'])->toBe(1000)
        ->and($results['options'][0]['percentage'])->toBe(100)
        // Aggregates, not a thousand hydrated models.
        ->and($queries)->toBeLessThan(6);
});
```

Helper:

```php
function scaleScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'Scale',
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
        'status' => EventPoll::STATUS_LIVE,
        'went_live_at' => now(),
    ]);
    $option = EventPollOption::create([
        'tenant_id' => $tenant->id, 'poll_id' => $poll->id, 'label' => 'One', 'sort_order' => 0,
    ]);

    return [$tenant, $event, $poll, $option];
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollScaleTest.php`
Expected: FAIL — `PollBroadcastCoalescer` not found; the counting test fails on query count.

- [ ] **Step 3: The index**

```php
Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
    $table->index(['poll_id', 'option_id']);
});
```

- [ ] **Step 4: The coalescer**

```php
// app/Services/Events/PollBroadcastCoalescer.php
final class PollBroadcastCoalescer
{
    private const int WINDOW_SECONDS = 1;

    /**
     * At most one broadcast per poll per second.
     *
     * Broadcasting per vote means a thousand synchronous calls to Reverb in the
     * ten seconds after a question opens, with each voter waiting on one. A
     * wall that updates once a second is indistinguishable to a room and costs
     * a hundredth as much.
     */
    public function schedule(EventPoll $poll): bool
    {
        $key = "poll:broadcast:{$poll->id}";

        if (! Cache::add($key, true, self::WINDOW_SECONDS)) {
            return false;
        }

        PollResultsUpdated::dispatch($poll);

        return true;
    }
}
```

In `respondPoll`, replace the direct `PollResultsUpdated::dispatch(...)` with `app(PollBroadcastCoalescer::class)->schedule($pollModel->fresh(['options', 'responses']));`

- [ ] **Step 5: SQL counting**

Replace the body of `PollResults::forDisplay` so the option counts come from one grouped query rather than a hydrated collection:

```php
$counts = EventPollResponse::query()
    ->where('poll_id', $poll->id)
    ->where('is_approved', true)
    ->selectRaw('option_id, count(*) as total')
    ->groupBy('option_id')
    ->pluck('total', 'option_id');

$total = (int) $counts->sum();
```

Options then read `(int) ($counts[$option->id] ?? 0)`. Open text keeps its existing capped fetch of the most recent approved answers — it is bounded at 30 and does not need aggregating.

- [ ] **Step 6: Run the tests**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollScaleTest.php tests/Feature/Events/PollPresentationTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
php vendor/bin/pint --dirty
git add database/migrations app/Services/Events tests/Feature/Events/PollScaleTest.php app/Http/Controllers/Public/PlatformAttendeeWorkspaceController.php
git commit -m "perf(polls): count in SQL, and broadcast once a second

Every vote fired a broadcast, synchronously, inside the request, and every
broadcast recomputed the results by loading each response into memory. A
thousand people answering in ten seconds meant a thousand synchronous calls to
Reverb, each voter waiting on one, each recomputing from a thousand rows.

Counts come from one grouped query now, and broadcasts are coalesced to one
per poll per second. A wall updating once a second reads the same to a room."
```

---

## Task 7: The wall and the portal follow the deck

**Files:**
- Modify: `app/Http/Controllers/Public/PollPresentationController.php`
- Modify: `resources/js/Pages/Public/Events/PollPresentation.jsx`
- Modify: `resources/js/Pages/Public/Events/AttendeePortal/panels/PollPanel.jsx`
- Test: `tests/Feature/Events/PollPresentationTest.php`

**Interfaces:**
- Consumes: `PollDeck`, `DeckPresenter` from Tasks 4 and 5

- [ ] **Step 1: Write the failing test**

```php
// append to tests/Feature/Events/PollPresentationTest.php
test('the wall shows the deck question, not whatever went live last', function (): void {
    [$tenant, $event, $deck, $first, $second] = presenterScenario('wall-follows');
    $event->update(['present_token' => Str::random(40)]);
    $host = eventSubdomainHost('wall-follows');

    $presenter = app(DeckPresenter::class);
    $presenter->start($deck);
    $presenter->advance($deck->fresh());

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    expect($props['poll']['question'])->toBe('Second');
});

test('a room where nobody has checked in says so rather than showing silence as a result', function (): void {
    [$tenant, $event, $deck, $first] = presenterScenario('wall-empty');
    $event->update(['present_token' => Str::random(40)]);
    $host = eventSubdomainHost('wall-empty');

    app(DeckPresenter::class)->start($deck);

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    // Zero bars and "nobody can answer yet" look identical on a wall, and the
    // second is the one an organiser can act on.
    expect($props['poll']['total_responses'])->toBe(0)
        ->and($props['eligibleVoters'])->toBe(0);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollPresentationTest.php`
Expected: FAIL — the wall shows "First", and `eligibleVoters` is absent.

- [ ] **Step 3: Follow the deck**

In `PollPresentationController::livePayload`, prefer a live deck's current poll before falling back to the existing loose-poll query:

```php
$deck = PollDeck::query()
    ->where('event_id', $event->id)
    ->where('status', PollDeck::STATUS_LIVE)
    ->with(['currentPoll.options', 'currentPoll.responses'])
    ->first();

if ($deck?->currentPoll !== null) {
    return $this->results->forDisplay($deck->currentPoll);
}
```

Add to the `show()` props:

```php
'eligibleVoters' => $event->registrations()
    ->whereNotNull('checked_in_at')
    ->count(),
```

- [ ] **Step 4: The wall reports an empty room**

In `PollPresentation.jsx`, accept `eligibleVoters` as a prop and, inside the `poll &&` branch, render this instead of the bars when nobody can answer:

```jsx
{poll.total_responses === 0 && eligibleVoters === 0 ? (
    <div className="mt-[6vh]">
        <p className="text-[2.2vw] text-white/70">Nobody has checked in yet</p>
        <p className="mt-[1vh] text-[1.4vw] text-white/40">
            Delegates answer from their ticket once they are checked in.
        </p>
    </div>
) : poll.type === 'open' ? (
    <OpenWall responses={poll.open_responses} />
) : (
    <ol className="mt-[4vh] space-y-[2.5vh]">
        {poll.options.map((option) => (
            <Bar key={option.id} option={option} closed={isClosed} />
        ))}
    </ol>
)}
```

- [ ] **Step 5: The portal follows the presenter**

In `PollPanel.jsx`, add an interval that re-fetches while a question is open and swaps it when the presenter moves on:

```jsx
// The presenter holds the pace, so the phone follows rather than decides.
// Five seconds, because this is a fallback for a room with no socket and a
// question is open for a minute or two at most.
useEffect(() => {
    const load = () => {
        csrfFetch(route('attendee.my.events.poll.show', { registration: registration.id }))
            .then((r) => r.json())
            .then((data) => {
                setPoll((current) =>
                    current?.id === data.poll?.id ? current : data.poll
                );
                if (data.poll?.id !== poll?.id) {
                    setAnswered(false);
                }
            })
            .catch(() => {});
    };

    const interval = setInterval(load, 5000);

    return () => clearInterval(interval);
}, [registration.id, poll?.id]);
```

And when `poll` is null, render `<p className="text-sm text-ink-secondary">Waiting for the next question.</p>` rather than hiding the tab.

- [ ] **Step 6: Run the tests and the frontend gates**

```bash
PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollPresentationTest.php
npm run lint && npm test && npm run build
```
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
php vendor/bin/pint --dirty
git add app/Http/Controllers/Public/PollPresentationController.php resources/js public/build tests/Feature/Events/PollPresentationTest.php
git commit -m "feat(polls): the wall and the portal follow the presenter

Both decided for themselves what was live. With a deck the presenter decides,
and every surface reads the same pointer.

The wall also says when nobody has checked in, because a chart of zeroes and a
room that cannot answer look identical from the back, and only one of them is
something an organiser can do anything about."
```

---

## Task 8: The QR deep-links into the portal

**Files:**
- Modify: `app/Http/Controllers/Public/PollPresentationController.php`
- Test: `tests/Feature/Events/PollPresentationTest.php`

- [ ] **Step 1: Write the failing test**

```php
test('the code on the wall leads somewhere a person can vote', function (): void {
    [$tenant, $event, $deck] = presenterScenario('wall-qr');
    $event->update(['present_token' => Str::random(40)]);
    $host = eventSubdomainHost('wall-qr');

    $props = $this->get("http://{$host}/e/{$event->slug}/present/{$event->present_token}", ['HTTP_HOST' => $host])
        ->viewData('page')['props'];

    // It pointed at /e/{event}/poll, which returns JSON. Someone in a room
    // scanned it and got a blob.
    $join = $props['join']['url'];

    expect($join)->toContain('/my');

    $this->get($join, ['HTTP_HOST' => app(TenantHostMatcher::class)->baseDomain()])
        ->assertOk()
        ->assertSee('MiConvener', false);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact --filter="leads somewhere a person can vote"`
Expected: FAIL — the join URL contains `/poll` and returns JSON.

- [ ] **Step 3: Point it at the portal**

```php
$joinPage = url('/my');
```

- [ ] **Step 4: Run the tests**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PollPresentationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php vendor/bin/pint --dirty
git add app/Http/Controllers/Public/PollPresentationController.php tests/Feature/Events/PollPresentationTest.php
git commit -m "fix(polls): the QR on the wall opened raw JSON

It pointed at /e/{event}/poll, which is the data endpoint the public event
page fetches, not a page. Anyone in a room who scanned it got a blob.

The tests asserted the URL contained '/poll' and never that it was somewhere a
person could vote, which is why it shipped green. The new test follows the
link and requires a page at the end of it."
```

---

## Task 9: A presentation in progress is not interrupted

**Files:**
- Modify: `app/Services/Events/PlatformAttendeeVerification.php`
- Modify: `app/Http/Controllers/Tenant/EventPollDeckController.php`
- Test: `tests/Feature/Events/PresenterHoldTest.php`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Events/PresenterHoldTest.php
test('a presenter is not signed out mid-deck', function (): void {
    [$tenant, $event, $deck] = presenterScenario('presenter-hold');
    $event->update(['ends_at' => now()->addHours(20)]);

    app(DeckPresenter::class)->start($deck);

    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, [
        'email' => 'speaker@example.com',
        'verified_at' => now()->getTimestamp(),
        'expires_at' => now()->addHours(12)->getTimestamp(),
    ]);

    // Twelve hours is enough for an event day and is deliberately fixed. It
    // must not expire in front of a room.
    $this->travel(13)->hours();

    expect(app(PlatformAttendeeVerification::class)->verifiedEmail($session, $deck->fresh()))
        ->toBe('speaker@example.com');
});

test('the hold ends when the deck does', function (): void {
    [$tenant, $event, $deck] = presenterScenario('presenter-hold-ends');

    app(DeckPresenter::class)->start($deck);
    app(DeckPresenter::class)->end($deck->fresh());

    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, [
        'email' => 'speaker@example.com',
        'verified_at' => now()->getTimestamp(),
        'expires_at' => now()->addHours(12)->getTimestamp(),
    ]);

    $this->travel(13)->hours();

    expect(app(PlatformAttendeeVerification::class)->verifiedEmail($session, $deck->fresh()))
        ->toBeNull();
});
```

- [ ] **Step 2: Run it and watch it fail**

Expected: FAIL — `verifiedEmail()` takes one argument.

- [ ] **Step 3: The exception**

Give `verifiedEmail()` an optional second parameter:

```php
/**
 * The address proven platform-wide, or null if expired or missing.
 *
 * Proof is fixed at twelve hours and does not slide. The single exception is
 * a deck being presented: a speaker who verified at eight in the morning to
 * rehearse must not be asked for a code at eight in the evening, mid-question,
 * in front of a room. The hold ends when the deck does.
 */
public function verifiedEmail(Session $session, ?PollDeck $presentingDeck = null): ?string
{
    $marker = $session->get(self::SESSION_KEY);

    if (! is_array($marker) || ! isset($marker['email'], $marker['expires_at'])) {
        return null;
    }

    $held = $presentingDeck !== null && $presentingDeck->status === PollDeck::STATUS_LIVE;

    if (! $held && $marker['expires_at'] <= now()->getTimestamp()) {
        $session->forget(self::SESSION_KEY);

        return null;
    }

    return (string) $marker['email'];
}
```

- [ ] **Step 4: Run the tests**

Run: `PATH="$HOME/Library/Application Support/Herd/bin:$PATH" php artisan test --compact tests/Feature/Events/PresenterHoldTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Run everything**

```bash
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --memory-limit=2G
php artisan test --compact
npm run lint && npm test && npm run build
```
Expected: all five green.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Events/PlatformAttendeeVerification.php tests/Feature/Events/PresenterHoldTest.php public/build
git commit -m "fix(polls): do not sign a presenter out mid-deck

Proof lasts twelve hours and is deliberately fixed rather than sliding, so a
speaker who verified at eight in the morning to rehearse would be asked for a
code at eight in the evening -- mid-question, in front of a room.

A live deck holds its presenter's proof until the deck ends. Nothing else
extends, and the fixed window is unchanged everywhere it is not a disaster."
```
