<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\OccurrenceReminderMail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\User;
use App\Services\Tenancy\FeatureMeteringService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('public ics calendar feed generates valid RFC 5545 feed for recurring event', function (): void {
    [$tenant] = eventHost('calendar-tenant');
    $host = eventSubdomainHost('calendar-tenant');

    $startsAt = CarbonImmutable::now()->addDays(2)->setTime(9, 0);
    $endsAt = CarbonImmutable::now()->addDays(2)->setTime(11, 0);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Grace Assembly Sunday Celebration',
        'description' => 'Weekly fellowship and worship.',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'is_recurring' => true,
        'recurrence_pattern' => 'weekly',
        'recurrence_days' => ['sunday'],
        'recurrence_time_start' => '09:00',
        'recurrence_time_end' => '11:00',
    ]);

    $occurrence = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Sunday Worship & Word',
        'is_occurrence' => true,
        'occurrence_date' => $startsAt->toDateString(),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'occurrence_status' => EventSession::STATUS_SCHEDULED,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/calendar.ics", ['HTTP_HOST' => $host]);

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

    $content = $response->getContent();
    expect($content)->toContain('BEGIN:VCALENDAR');
    expect($content)->toContain('VERSION:2.0');
    expect($content)->toContain('BEGIN:VEVENT');
    expect($content)->toContain('SUMMARY:Grace Assembly Sunday Celebration: Sunday Worship & Word');
    expect($content)->toContain('END:VEVENT');
    expect($content)->toContain('END:VCALENDAR');
});

test('public event payload includes calendar feed url when event is recurring and published', function (): void {
    [$tenant] = eventHost('cal-url-tenant');
    $host = eventSubdomainHost('cal-url-tenant');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Monthly Leadership Summit',
        'is_recurring' => true,
        'recurrence_pattern' => 'monthly',
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host]);
    $response->assertSuccessful();

    $response->assertInertia(fn ($page) => $page
        ->component('Public/Events/Show')
        ->has('event.calendar_feed_url')
        ->where('event.calendar_feed_url', url("/e/{$event->slug}/calendar.ics"))
    );
});

test('private recurring event withholds calendar feed url and returns 404 on calendar ics route', function (): void {
    [$tenant] = eventHost('priv-cal-tenant');
    $host = eventSubdomainHost('priv-cal-tenant');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Private Executive Session',
        'visibility' => Event::VISIBILITY_PRIVATE,
        'is_recurring' => true,
    ]);

    $pageResponse = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host]);
    $pageResponse->assertSuccessful();
    $pageResponse->assertInertia(fn ($page) => $page
        ->where('event.calendar_feed_url', null)
    );

    $icsResponse = $this->get("http://{$host}/e/{$event->slug}/calendar.ics", ['HTTP_HOST' => $host]);
    $icsResponse->assertNotFound();
});

test('tenant admin can batch cancel occurrences with blackout reason', function (): void {
    [$tenant, $user] = eventHost('batch-cancel-tenant');
    $host = eventSubdomainHost('batch-cancel-tenant');

    $baseDate = CarbonImmutable::now()->addDays(5)->startOfDay();

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Weekly Masterclass Series',
        'is_recurring' => true,
        'recurrence_pattern' => 'weekly',
    ]);

    $occ1 = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'is_occurrence' => true,
        'occurrence_date' => $baseDate->toDateString(),
        'starts_at' => $baseDate->setTime(10, 0),
        'ends_at' => $baseDate->setTime(12, 0),
        'occurrence_status' => EventSession::STATUS_SCHEDULED,
    ]);

    $occ2 = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'is_occurrence' => true,
        'occurrence_date' => $baseDate->addDays(7)->toDateString(),
        'starts_at' => $baseDate->addDays(7)->setTime(10, 0),
        'ends_at' => $baseDate->addDays(7)->setTime(12, 0),
        'occurrence_status' => EventSession::STATUS_SCHEDULED,
    ]);

    $occOther = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'is_occurrence' => true,
        'occurrence_date' => $baseDate->addDays(28)->toDateString(),
        'starts_at' => $baseDate->addDays(28)->setTime(10, 0),
        'ends_at' => $baseDate->addDays(28)->setTime(12, 0),
        'occurrence_status' => EventSession::STATUS_SCHEDULED,
    ]);

    $response = $this->actingAs($user)->postJson(
        "http://{$host}/events/{$event->id}/occurrences/batch-cancel",
        [
            'start_date' => $baseDate->toDateString(),
            'end_date' => $baseDate->addDays(10)->toDateString(),
            'reason' => 'National Public Holiday Blackout',
        ],
        ['HTTP_HOST' => $host]
    );

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['cancelled_count'])->toBe(2);

    $occ1->refresh();
    $occ2->refresh();
    $occOther->refresh();

    expect($occ1->occurrence_status)->toBe(EventSession::STATUS_CANCELLED);
    expect($occ1->cancellation_reason)->toBe('National Public Holiday Blackout');

    expect($occ2->occurrence_status)->toBe(EventSession::STATUS_CANCELLED);
    expect($occ2->cancellation_reason)->toBe('National Public Holiday Blackout');

    expect($occOther->occurrence_status)->toBe(EventSession::STATUS_SCHEDULED);
    expect($occOther->cancellation_reason)->toBeNull();
});

test('batch cancel validates inverted date ranges', function (): void {
    [$tenant, $user] = eventHost('cancel-val-tenant');
    $host = eventSubdomainHost('cancel-val-tenant');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'is_recurring' => true,
    ]);

    $response = $this->actingAs($user)->postJson(
        "http://{$host}/events/{$event->id}/occurrences/batch-cancel",
        [
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-10',
            'reason' => 'Invalid range',
        ],
        ['HTTP_HOST' => $host]
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['end_date']);
});

test('tenant admin can batch reschedule future occurrences', function (): void {
    [$tenant, $user] = eventHost('batch-resched-tenant');
    $host = eventSubdomainHost('batch-resched-tenant');

    $baseDate = CarbonImmutable::now()->addDays(3)->startOfDay();

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Tech Talks Series',
        'is_recurring' => true,
        'recurrence_time_start' => '09:00',
        'recurrence_time_end' => '10:30',
    ]);

    $futureOcc = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'is_occurrence' => true,
        'occurrence_date' => $baseDate->toDateString(),
        'starts_at' => $baseDate->setTime(9, 0),
        'ends_at' => $baseDate->setTime(10, 30),
        'occurrence_status' => EventSession::STATUS_SCHEDULED,
    ]);

    $pastOcc = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'is_occurrence' => true,
        'occurrence_date' => CarbonImmutable::now()->subDays(5)->toDateString(),
        'starts_at' => CarbonImmutable::now()->subDays(5)->setTime(9, 0),
        'ends_at' => CarbonImmutable::now()->subDays(5)->setTime(10, 30),
        'occurrence_status' => EventSession::STATUS_COMPLETED,
    ]);

    $response = $this->actingAs($user)->postJson(
        "http://{$host}/events/{$event->id}/occurrences/batch-reschedule",
        [
            'new_start_time' => '10:00',
            'new_end_time' => '12:00',
            'from_date' => CarbonImmutable::now()->toDateString(),
            'update_default_schedule' => true,
        ],
        ['HTTP_HOST' => $host]
    );

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['updated_count'])->toBe(1);

    $futureOcc->refresh();
    $pastOcc->refresh();
    $event->refresh();

    expect($futureOcc->starts_at->format('H:i'))->toBe('10:00');
    expect($futureOcc->ends_at->format('H:i'))->toBe('12:00');

    expect($pastOcc->starts_at->format('H:i'))->toBe('09:00');

    expect($event->recurrence_time_start)->toBe('10:00');
    expect($event->recurrence_time_end)->toBe('12:00');
});

test('analytics endpoint returns series attendance rollup and retention metrics with canonical and frontend aliased keys', function (): void {
    [$tenant, $user] = eventHost('analytics-tenant');
    $host = eventSubdomainHost('analytics-tenant');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Campus Ministry Midweek Gathering',
        'is_recurring' => true,
    ]);

    $occ1 = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Week 1 Gathering',
        'is_occurrence' => true,
        'occurrence_date' => CarbonImmutable::now()->subWeeks(2)->toDateString(),
        'occurrence_status' => EventSession::STATUS_COMPLETED,
    ]);

    $occ2 = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Week 2 Gathering',
        'is_occurrence' => true,
        'occurrence_date' => CarbonImmutable::now()->subWeeks(1)->toDateString(),
        'occurrence_status' => EventSession::STATUS_COMPLETED,
    ]);

    $reg1 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $reg2 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // reg1 attended both occ1 and occ2
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $occ1->id,
        'registration_id' => $reg1->id,
        'checked_in_at' => CarbonImmutable::now()->subWeeks(2)->setTime(10, 5),
    ]);
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $occ2->id,
        'registration_id' => $reg1->id,
        'checked_in_at' => CarbonImmutable::now()->subWeeks(1)->setTime(10, 2),
    ]);

    // reg2 only attended occ1
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $occ1->id,
        'registration_id' => $reg2->id,
        'checked_in_at' => CarbonImmutable::now()->subWeeks(2)->setTime(10, 15),
    ]);

    $response = $this->actingAs($user)->getJson(
        "http://{$host}/events/{$event->id}/occurrences/analytics",
        ['HTTP_HOST' => $host]
    );

    $response->assertSuccessful();
    $data = $response->json();

    expect($data['total_occurrences'])->toBe(2);
    expect($data['completed_occurrences'])->toBe(2);
    expect($data['total_unique_attendees'])->toBe(2);
    expect($data['unique_attendees'])->toBe(2);
    expect($data['avg_attendance_per_occurrence'])->toEqual(1.5);
    expect($data['average_attendance'])->toEqual(1.5);
    // 1 out of 2 attendees attended 2+ sessions -> 50% retention
    expect($data['retention_rate_pct'])->toEqual(50);
    expect($data['retention_rate'])->toEqual(50);
    expect($data['retained_attendees'])->toBe(1);
    expect($data['occurrence_history'])->toHaveCount(2);
    expect($data['recent_trend'])->toHaveCount(2);
});

test('send occurrence reminders command notifies attendees and meters email credits idempotently', function (): void {
    Mail::fake();

    [$tenant] = eventHost('reminder-tenant');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Community Prayer Night',
        'is_recurring' => true,
    ]);

    // Occurrence starting in 6 hours (within default 24h threshold)
    $startsAt = CarbonImmutable::now()->addHours(6);
    $occurrence = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Midweek Prayer Watch',
        'is_occurrence' => true,
        'occurrence_date' => $startsAt->toDateString(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHours(2),
        'occurrence_status' => EventSession::STATUS_SCHEDULED,
        'reminder_sent_at' => null,
    ]);

    $attendee = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Kofi Mensah',
        'email' => 'kofi@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $initialCreditsUsed = app(FeatureMeteringService::class)->getUsage($tenant, 'email_credits')['used'];

    // First run of command
    Artisan::call('events:send-occurrence-reminders', ['--hours' => 24, '--event' => $event->id]);

    Mail::assertQueued(OccurrenceReminderMail::class, function (OccurrenceReminderMail $mail) use ($attendee) {
        return $mail->hasTo($attendee->email);
    });

    $occurrence->refresh();
    expect($occurrence->reminder_sent_at)->not->toBeNull();
    expect($occurrence->hasReminderBeenSent())->toBeTrue();

    // Check metering
    $newCreditsUsed = app(FeatureMeteringService::class)->getUsage($tenant, 'email_credits')['used'];
    expect($newCreditsUsed)->toBe($initialCreditsUsed + 1);

    // Second run should be idempotent: no new reminder sent because reminder_sent_at is set
    Mail::fake();
    Artisan::call('events:send-occurrence-reminders', ['--hours' => 24, '--event' => $event->id]);
    Mail::assertNothingQueued();
});

test('unauthorized users cannot batch cancel or batch reschedule occurrences', function (): void {
    [$tenant] = eventHost('unauth-series-tenant');
    $host = eventSubdomainHost('unauth-series-tenant');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'is_recurring' => true,
    ]);

    // Guest request
    $guestCancel = $this->postJson(
        "http://{$host}/events/{$event->id}/occurrences/batch-cancel",
        ['start_date' => '2026-10-10', 'end_date' => '2026-10-12', 'reason' => 'Test'],
        ['HTTP_HOST' => $host]
    );
    $guestCancel->assertUnauthorized();

    $guestResched = $this->postJson(
        "http://{$host}/events/{$event->id}/occurrences/batch-reschedule",
        ['new_start_time' => '10:00', 'new_end_time' => '12:00'],
        ['HTTP_HOST' => $host]
    );
    $guestResched->assertUnauthorized();

    $guestAnalytics = $this->getJson(
        "http://{$host}/events/{$event->id}/occurrences/analytics",
        ['HTTP_HOST' => $host]
    );
    $guestAnalytics->assertUnauthorized();

    // User without permission
    $randomUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($randomUser->id);

    $userCancel = $this->actingAs($randomUser)->postJson(
        "http://{$host}/events/{$event->id}/occurrences/batch-cancel",
        ['start_date' => '2026-10-10', 'end_date' => '2026-10-12', 'reason' => 'Test'],
        ['HTTP_HOST' => $host]
    );
    $userCancel->assertForbidden();

    $userResched = $this->actingAs($randomUser)->postJson(
        "http://{$host}/events/{$event->id}/occurrences/batch-reschedule",
        ['new_start_time' => '10:00', 'new_end_time' => '12:00'],
        ['HTTP_HOST' => $host]
    );
    $userResched->assertForbidden();

    $userAnalytics = $this->actingAs($randomUser)->getJson(
        "http://{$host}/events/{$event->id}/occurrences/analytics",
        ['HTTP_HOST' => $host]
    );
    $userAnalytics->assertForbidden();
});

test('cross tenant boundaries prevent modifying other tenants event occurrences', function (): void {
    [$tenantA] = eventHost('tenant-a-series');
    $hostA = eventSubdomainHost('tenant-a-series');

    [$tenantB, $userB] = eventHost('tenant-b-series');
    $hostB = eventSubdomainHost('tenant-b-series');

    $eventA = Event::factory()->published()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Gathering',
        'is_recurring' => true,
    ]);

    // Tenant B attempts batch cancel on Tenant A via Tenant B host -> 404
    $subdomainBreach = $this->actingAs($userB)->postJson(
        "http://{$hostB}/events/{$eventA->id}/occurrences/batch-cancel",
        ['start_date' => '2026-10-10', 'end_date' => '2026-10-12', 'reason' => 'Cross breach'],
        ['HTTP_HOST' => $hostB]
    );
    $subdomainBreach->assertNotFound();

    // Tenant B attempts batch cancel on Tenant A via Tenant A host -> 403
    $hostBreach = $this->actingAs($userB)->postJson(
        "http://{$hostA}/events/{$eventA->id}/occurrences/batch-cancel",
        ['start_date' => '2026-10-10', 'end_date' => '2026-10-12', 'reason' => 'Cross breach'],
        ['HTTP_HOST' => $hostA]
    );
    $hostBreach->assertForbidden();

    // Tenant B attempts analytics on Tenant A via Tenant B host -> 404
    $subdomainAnalytics = $this->actingAs($userB)->getJson(
        "http://{$hostB}/events/{$eventA->id}/occurrences/analytics",
        ['HTTP_HOST' => $hostB]
    );
    $subdomainAnalytics->assertNotFound();
});
