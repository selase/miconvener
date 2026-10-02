<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\Speaker;
use App\Models\User;
use App\Services\Events\RecurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('event can be created with recurrence configuration and automatically generates initial occurrences', function (): void {
    [$tenant, $user] = eventHost('church-accra');
    $host = eventSubdomainHost('church-accra');

    $startsAt = CarbonImmutable::now()->startOfWeek()->addDays(6)->setTime(9, 0)->toIso8601String();
    $endsAt = CarbonImmutable::now()->startOfWeek()->addDays(6)->setTime(12, 0)->toIso8601String();

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Harvest Chapel Weekly Fellowship',
        'status' => Event::STATUS_PUBLISHED,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Harvest Chapel Sanctuary, Spintex Road, Accra',
        'capacity' => 500,
        'ticket_price' => 0,
        'currency' => 'GHS',
        'is_recurring' => true,
        'recurrence_pattern' => 'weekly',
        'recurrence_days' => ['sunday', 'wednesday'],
        'recurrence_time_start' => '09:00',
        'recurrence_time_end' => '11:30',
        'recurrence_interval' => 1,
        'recurrence_auto_generate_weeks' => 4,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $event = Event::where('name', 'Harvest Chapel Weekly Fellowship')->first();
    expect($event)->not->toBeNull();
    expect($event->is_recurring)->toBeTrue();
    expect($event->recurrence_pattern)->toBe('weekly');
    expect($event->recurrenceDays())->toBe(['sunday', 'wednesday']);
    expect($event->recurrence_time_start)->toBe('09:00');
    expect($event->recurrence_time_end)->toBe('11:30');

    $occurrences = $event->recurringSessions()->get();
    expect($occurrences->count())->toBeGreaterThanOrEqual(4);

    $sundayOccurrence = $occurrences->first(fn ($s) => CarbonImmutable::parse($s->occurrence_date)->isSunday());
    expect($sundayOccurrence)->not->toBeNull();
    expect($sundayOccurrence->is_occurrence)->toBeTrue();
    expect($sundayOccurrence->type)->toBe(EventSession::TYPE_SERVICE);
    expect($sundayOccurrence->starts_at->format('H:i'))->toBe('09:00');
    expect($sundayOccurrence->ends_at->format('H:i'))->toBe('11:30');

    $wednesdayOccurrence = $occurrences->first(fn ($s) => CarbonImmutable::parse($s->occurrence_date)->isWednesday());
    expect($wednesdayOccurrence)->not->toBeNull();
    expect($wednesdayOccurrence->is_occurrence)->toBeTrue();
    expect($wednesdayOccurrence->type)->toBe(EventSession::TYPE_BIBLE_STUDY);
});

test('recurrence service idempotently generates future occurrences without duplicating dates', function (): void {
    [$tenant] = eventHost('academic-cs');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'CS 301: Algorithms & Data Structures',
        'is_recurring' => true,
        'recurrence_pattern' => 'weekly',
        'recurrence_days' => ['monday', 'thursday'],
        'recurrence_time_start' => '10:00',
        'recurrence_time_end' => '12:00',
        'recurrence_auto_generate_weeks' => 3,
    ]);

    /** @var RecurrenceService $service */
    $service = app(RecurrenceService::class);

    $service->generateOccurrences($event);
    $initialCount = $event->recurringSessions()->count();
    expect($initialCount)->toBeGreaterThanOrEqual(4);

    // Second run should be idempotent: no duplicate sessions created
    $service->generateOccurrences($event);
    expect($event->recurringSessions()->count())->toBe($initialCount);

    $dates = $event->recurringSessions()->pluck('occurrence_date')->toArray();
    expect(count($dates))->toBe(count(array_unique($dates)));
});

test('tenant can generate next N weeks of occurrences on demand via endpoint', function (): void {
    [$tenant, $user] = eventHost('church-legon');
    $host = eventSubdomainHost('church-legon');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Legon Interdenominational Church',
        'is_recurring' => true,
        'recurrence_pattern' => 'weekly',
        'recurrence_days' => ['sunday'],
        'recurrence_time_start' => '08:30',
        'recurrence_time_end' => '10:30',
        'recurrence_auto_generate_weeks' => 2,
    ]);

    app(RecurrenceService::class)->generateOccurrences($event);
    $countBefore = $event->recurringSessions()->count();

    $generateUrl = "http://{$host}/events/{$event->id}/recurrence/generate";
    $response = $this->actingAs($user)->postJson($generateUrl, [
        'weeks' => 6,
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $countAfter = $event->recurringSessions()->count();
    expect($countAfter)->toBeGreaterThan($countBefore);
});

test('tenant can update occurrence details including sermon notes, presentation url, and status', function (): void {
    [$tenant, $user] = eventHost('grace-fellowship');
    $host = eventSubdomainHost('grace-fellowship');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Grace Community Assembly',
        'is_recurring' => true,
    ]);

    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Sunday Worship & Word',
        'is_occurrence' => true,
        'occurrence_date' => CarbonImmutable::now()->addDays(2)->toDateString(),
        'starts_at' => CarbonImmutable::now()->addDays(2)->setTime(9, 0),
        'ends_at' => CarbonImmutable::now()->addDays(2)->setTime(11, 0),
        'occurrence_status' => EventSession::STATUS_SCHEDULED,
    ]);

    $speaker = Speaker::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Pastor Mensa Otabil',
    ]);

    $updateUrl = "http://{$host}/events/{$event->id}/sessions/{$session->id}/occurrence";
    $response = $this->actingAs($user)->patchJson($updateUrl, [
        'title' => 'Sunday Service: Unshakable Faith in Tough Times',
        'notes' => "Theme: Unshakable Faith\nScripture: Hebrews 11:1-6\nKey Points:\n1. Faith sees the invisible\n2. Faith believes the incredible\n3. Faith receives the impossible",
        'presentation_url' => 'https://docs.google.com/presentation/d/example-slides-id/edit',
        'occurrence_status' => EventSession::STATUS_COMPLETED,
        'speaker_ids' => [$speaker->id],
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $session->refresh();

    expect($session->title)->toBe('Sunday Service: Unshakable Faith in Tough Times');
    expect($session->notes)->toContain('Hebrews 11:1-6');
    expect($session->presentation_url)->toBe('https://docs.google.com/presentation/d/example-slides-id/edit');
    expect($session->occurrence_status)->toBe(EventSession::STATUS_COMPLETED);
    expect($session->speakers->pluck('id')->all())->toContain($speaker->id);
});

test('artisan command events:generate-recurring-sessions extends horizon for published recurring events', function (): void {
    [$tenant] = eventHost('campus-ministry');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Campus Friday Prayer Gathering',
        'is_recurring' => true,
        'recurrence_pattern' => 'weekly',
        'recurrence_days' => ['friday'],
        'recurrence_time_start' => '18:00',
        'recurrence_time_end' => '20:00',
        'recurrence_auto_generate_weeks' => 4,
    ]);

    $exitCode = Artisan::call('events:generate-recurring-sessions');
    expect($exitCode)->toBe(0);

    $occurrences = $event->recurringSessions()->get();
    expect($occurrences->count())->toBeGreaterThanOrEqual(2);
    expect($occurrences->first()->type)->toBe(EventSession::TYPE_PRAYER);
});

test('public event payload exposes recurrence summary and upcoming occurrences with notes and presentation url', function (): void {
    [$tenant] = eventHost('public-church');
    $host = eventSubdomainHost('public-church');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Central Chapel Sunday Services',
        'is_recurring' => true,
        'recurrence_pattern' => 'weekly',
        'recurrence_days' => ['sunday'],
        'recurrence_time_start' => '09:00',
        'recurrence_time_end' => '11:00',
    ]);

    $upcomingDate = CarbonImmutable::now()->addDays(3)->toDateString();
    EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Sunday Divine Service',
        'is_occurrence' => true,
        'occurrence_date' => $upcomingDate,
        'starts_at' => CarbonImmutable::parse($upcomingDate)->setTime(9, 0),
        'ends_at' => CarbonImmutable::parse($upcomingDate)->setTime(11, 0),
        'notes' => 'Scripture reading: Psalm 23:1-6. Hymn: The Lord is my Shepherd.',
        'presentation_url' => 'https://canva.com/design/example-church-deck',
        'occurrence_status' => EventSession::STATUS_SCHEDULED,
    ]);

    $publicUrl = "http://{$host}/e/{$event->slug}";
    $response = $this->get($publicUrl, ['HTTP_HOST' => $host]);

    $response->assertOk();
    $page = $response->viewData('page');
    $eventPayload = $page['props']['event'];

    expect($eventPayload['is_recurring'])->toBeTrue();
    expect($eventPayload['recurrence_summary'])->toContain('Sunday');
    expect($eventPayload['upcoming_occurrences'])->not->toBeEmpty();

    $firstUpcoming = $eventPayload['upcoming_occurrences'][0];
    expect($firstUpcoming['notes'])->toBe('Scripture reading: Psalm 23:1-6. Hymn: The Lord is my Shepherd.');
    expect($firstUpcoming['presentation_url'])->toBe('https://canva.com/design/example-church-deck');
});

test('attendee can be checked into a recurring occurrence and dwell duration is tracked', function (): void {
    [$tenant, $user] = eventHost('church-checkin');
    $host = eventSubdomainHost('church-checkin');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Weekly Sunday Celebration',
        'is_recurring' => true,
    ]);

    $occurrence = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Sunday 1st Service',
        'is_occurrence' => true,
        'occurrence_date' => CarbonImmutable::now()->toDateString(),
        'starts_at' => CarbonImmutable::now()->setTime(8, 0),
        'ends_at' => CarbonImmutable::now()->setTime(10, 0),
        'capacity' => 200,
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'full_name' => 'Kwame Mensah',
        'email' => 'kwame.mensah@example.com',
        'qr_token' => 'QR-KWAME-RECURRING-PASS-1',
    ]);

    $scanUrl = "http://{$host}/events/{$event->id}/sessions/{$occurrence->id}/scan";

    $scanInResponse = $this->actingAs($user)->postJson($scanUrl, [
        'token' => 'QR-KWAME-RECURRING-PASS-1',
        'action' => 'check_in',
    ], ['HTTP_HOST' => $host]);

    $scanInResponse->assertOk();
    expect($scanInResponse->json('message'))->toContain('Kwame Mensah checked in');
    expect($occurrence->liveHeadcount())->toBe(1);

    $dupResponse = $this->actingAs($user)->postJson($scanUrl, [
        'token' => 'QR-KWAME-RECURRING-PASS-1',
        'action' => 'check_in',
    ], ['HTTP_HOST' => $host]);

    $dupResponse->assertOk();
    expect($dupResponse->json('already_checked_in'))->toBeTrue();

    $scanOutResponse = $this->actingAs($user)->postJson($scanUrl, [
        'token' => 'QR-KWAME-RECURRING-PASS-1',
        'action' => 'check_out',
    ], ['HTTP_HOST' => $host]);

    $scanOutResponse->assertOk();
    expect($scanOutResponse->json('message'))->toContain('checked out');
    expect($occurrence->liveHeadcount())->toBe(0);

    $attendance = EventSessionAttendance::where('session_id', $occurrence->id)
        ->where('registration_id', $registration->id)
        ->first();
    expect($attendance)->not->toBeNull();
    expect($attendance->checked_out_at)->not->toBeNull();
});

test('unauthorized users cannot update occurrence details or generate recurrence', function (): void {
    [$tenant] = eventHost('auth-check-rec');
    $host = eventSubdomainHost('auth-check-rec');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'is_recurring' => true,
    ]);

    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'is_occurrence' => true,
    ]);

    $response = $this->patchJson("http://{$host}/events/{$event->id}/sessions/{$session->id}/occurrence", [
        'title' => 'Hacked title',
    ], ['HTTP_HOST' => $host]);

    $response->assertUnauthorized();

    $randomUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($randomUser->id);

    $forbiddenResponse = $this->actingAs($randomUser)
        ->patchJson("http://{$host}/events/{$event->id}/sessions/{$session->id}/occurrence", [
            'title' => 'Hacked title',
        ], ['HTTP_HOST' => $host]);

    $forbiddenResponse->assertForbidden();
});

test('cross-tenant isolation prevents tenant B from generating or modifying tenant A occurrences', function (): void {
    [$tenantA, $userA] = eventHost('tenant-a-rec');
    $hostA = eventSubdomainHost('tenant-a-rec');

    [$tenantB, $userB] = eventHost('tenant-b-rec');
    $hostB = eventSubdomainHost('tenant-b-rec');

    $eventA = Event::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Service',
        'is_recurring' => true,
    ]);

    $sessionA = EventSession::factory()->create([
        'tenant_id' => $tenantA->id,
        'event_id' => $eventA->id,
        'is_occurrence' => true,
    ]);

    // 1. Tenant B accessing Tenant A event via Tenant B subdomain -> 404 Not Found
    $responseSubdomain = $this->actingAs($userB)->postJson(
        "http://{$hostB}/events/{$eventA->id}/recurrence/generate",
        ['weeks' => 4],
        ['HTTP_HOST' => $hostB]
    );
    $responseSubdomain->assertNotFound();

    $responsePatchSubdomain = $this->actingAs($userB)->patchJson(
        "http://{$hostB}/events/{$eventA->id}/sessions/{$sessionA->id}/occurrence",
        ['title' => 'Cross-tenant breach'],
        ['HTTP_HOST' => $hostB]
    );
    $responsePatchSubdomain->assertNotFound();

    // 2. Tenant B accessing Tenant A event on Tenant A subdomain -> 403 Forbidden
    $responseCrossHost = $this->actingAs($userB)->postJson(
        "http://{$hostA}/events/{$eventA->id}/recurrence/generate",
        ['weeks' => 4],
        ['HTTP_HOST' => $hostA]
    );
    $responseCrossHost->assertForbidden();

    $responseCrossHostPatch = $this->actingAs($userB)->patchJson(
        "http://{$hostA}/events/{$eventA->id}/sessions/{$sessionA->id}/occurrence",
        ['title' => 'Cross-tenant breach'],
        ['HTTP_HOST' => $hostA]
    );
    $responseCrossHostPatch->assertForbidden();
});
