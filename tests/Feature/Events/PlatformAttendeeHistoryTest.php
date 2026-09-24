<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enum\TenantStatusEnum;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeHistory;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function platformDomain(): string
{
    return mb_ltrim((string) config('session.domain'), '.');
}

test('getHistory returns cross-tenant registrations grouped by urgency and organiser', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'attendee@example.com';

    // Organiser A (active)
    $orgA = Tenant::factory()->create([
        'name' => 'African Health Alliance',
        'slug' => 'aha',
        'status' => TenantStatusEnum::ACTIVE,
        'isolation_mode' => 'shared',
    ]);
    // Upcoming event for Org A
    $eventA = Event::factory()->published()->create([
        'tenant_id' => $orgA->id,
        'name' => 'Health Summit 2026',
        'starts_at' => now()->addDays(10),
        'ends_at' => now()->addDays(12),
    ]);
    $regA = EventRegistration::factory()->create([
        'tenant_id' => $orgA->id,
        'event_id' => $eventA->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Organiser B (deactivated - plan lapsed, but attendee history must still show!)
    $orgB = Tenant::factory()->create([
        'name' => 'Tech Connect Ghana',
        'slug' => 'tcg',
        'status' => TenantStatusEnum::DEACTIVATED,
        'isolation_mode' => 'shared',
    ]);
    // Past event for Org B
    $eventB = Event::factory()->published()->create([
        'tenant_id' => $orgB->id,
        'name' => 'DevFest 2025',
        'starts_at' => now()->subMonths(3),
        'ends_at' => now()->subMonths(3)->addDays(2),
    ]);
    $regB = EventRegistration::factory()->create([
        'tenant_id' => $orgB->id,
        'event_id' => $eventB->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Organiser C (banned - must be EXCLUDED!)
    $orgC = Tenant::factory()->create([
        'name' => 'Suspicious Corp',
        'slug' => 'banned-org',
        'status' => TenantStatusEnum::BANNED,
        'isolation_mode' => 'shared',
    ]);
    $eventC = Event::factory()->published()->create([
        'tenant_id' => $orgC->id,
        'name' => 'Illegal Gala',
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(6),
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $orgC->id,
        'event_id' => $eventC->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $history = $service->getHistory($email);

    expect($history['organisers'])->toHaveCount(2);

    $orgSlugs = collect($history['organisers'])->pluck('slug')->all();
    expect($orgSlugs)->toContain('aha')
        ->and($orgSlugs)->toContain('tcg')
        ->and($orgSlugs)->not->toContain('banned-org');

    // Check upcoming and past classification
    $ahaEntry = collect($history['organisers'])->firstWhere('slug', 'aha');
    expect($ahaEntry['upcoming'])->toHaveCount(1)
        ->and($ahaEntry['upcoming'][0]['registration_id'])->toBe($regA->id)
        ->and($ahaEntry['past'])->toHaveCount(0);

    $tcgEntry = collect($history['organisers'])->firstWhere('slug', 'tcg');
    expect($tcgEntry['past'])->toHaveCount(1)
        ->and($tcgEntry['past'][0]['registration_id'])->toBe($regB->id)
        ->and($tcgEntry['upcoming'])->toHaveCount(0);
});

test('getHistory asks for payment but never for a transfer the recipient cannot complete', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'urgent@example.com';

    $tenant = Tenant::factory()->create(['slug' => 'urgent-org', 'status' => TenantStatusEnum::ACTIVE, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(6),
    ]);

    // Registration with pending payment
    $pendingPaymentReg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    // Another registration transferred to this email
    $transferSourceReg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'original-holder@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    EventRegistrationTransfer::create([
        'tenant_id' => $tenant->id,
        'registration_id' => $transferSourceReg->id,
        'to_email' => $email,
        'to_full_name' => 'Urgent Attendee',
        'code_hash' => Hash::make('123456'),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(15),
        'consumed_at' => null,
    ]);

    $history = $service->getHistory($email);

    // The payment is the recipient's to make, so it is offered. The transfer is
    // not: its code was sent to the current holder, and the workspace behind
    // any such card would refuse this address until the ticket is theirs.
    expect($history['needs_attention'])->toHaveCount(1);

    $reasons = collect($history['needs_attention'])->pluck('reason')->all();
    expect($reasons)->toContain('Payment required to secure your ticket')
        ->and($reasons)->not->toContain('Ticket transfer awaiting your confirmation');

    // Every action offered must open for the person it is offered to.
    foreach ($history['needs_attention'] as $item) {
        $registration = EventRegistration::withoutGlobalScopes()->find($item['registration_id']);
        expect(mb_strtolower($registration->email))->toBe($email);
    }
});

test('getHistory classifies live_now for running events or checked-in attendees', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'lively@example.com';

    $tenant = Tenant::factory()->create(['slug' => 'live-org', 'status' => TenantStatusEnum::ACTIVE, 'isolation_mode' => 'shared']);

    // Event 1: Currently running
    $liveEvent = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Currently Live Conference',
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addHours(6),
    ]);
    $liveReg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $liveEvent->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Event 2: Future event, but attendee pre-checked-in at the desk
    $futureEvent = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Tomorrow Summit',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
    $checkedInReg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $futureEvent->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now()->subMinutes(10),
    ]);

    $history = $service->getHistory($email);

    expect($history['live_now'])->toHaveCount(2);

    $regIds = collect($history['live_now'])->pluck('registration_id')->all();
    expect($regIds)->toContain($liveReg->id)
        ->and($regIds)->toContain($checkedInReg->id);
});

test('getCertificates, getAbstracts, and getAttendance return multi-tenant records', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'scholar@example.com';

    $tenant = Tenant::factory()->create(['name' => 'Research Org', 'slug' => 'research', 'status' => TenantStatusEnum::ACTIVE, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Certificate
    EventCertificate::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'recipient_name' => 'Dr. Scholar',
        'recipient_email' => $email,
        'role' => 'Speaker',
        'cpd_hours' => 4.5,
        'issued_at' => now(),
    ]);

    // Abstract & Author
    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Advances in Genomic Epidemiology',
        'code' => 'ABS-001',
        'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
        'presentation_preference' => EventAbstract::PREFERENCE_ORAL,
    ]);
    EventAbstractAuthor::create([
        'abstract_id' => $abstract->id,
        'first_name' => 'Ama',
        'last_name' => 'Scholar',
        'email' => $email,
        'affiliation' => 'University of Science & Tech',
        'is_presenting' => true,
        'is_corresponding' => true,
    ]);

    // Session & Attendance
    $session = EventSession::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Keynote: AI in Medicine',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addHours(1),
    ]);
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'registration_id' => $registration->id,
        'checked_in_at' => now()->subDay(),
    ]);

    $certs = $service->getCertificates($email);
    expect($certs)->toHaveCount(1)
        ->and($certs[0]['role'])->toBe('Speaker')
        ->and($certs[0]['cpd_hours'])->toBe(4.5);

    $abstracts = $service->getAbstracts($email);
    expect($abstracts)->toHaveCount(1)
        ->and($abstracts[0]['title'])->toBe('Advances in Genomic Epidemiology')
        ->and($abstracts[0]['status'])->toBe('accepted_oral');

    $attendance = $service->getAttendance($email);
    expect($attendance)->toHaveCount(1)
        ->and($attendance[0]['session_title'])->toBe('Keynote: AI in Medicine');
});

test('authenticated history endpoints require platform verification', function (): void {
    $host = platformDomain();

    // Unauthenticated requests are rejected with 401
    $this->getJson("http://{$host}/my/events", ['HTTP_HOST' => $host])->assertUnauthorized();
    $this->getJson("http://{$host}/my/certificates", ['HTTP_HOST' => $host])->assertUnauthorized();
    $this->getJson("http://{$host}/my/abstracts", ['HTTP_HOST' => $host])->assertUnauthorized();
    $this->getJson("http://{$host}/my/attendance", ['HTTP_HOST' => $host])->assertUnauthorized();

    // Authenticated requests succeed with 200
    $verifiedSession = [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'attendee@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ];

    $this->withSession($verifiedSession)->getJson("http://{$host}/my/events", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonStructure(['needs_attention', 'live_now', 'organisers']);

    $this->withSession($verifiedSession)->getJson("http://{$host}/my/certificates", ['HTTP_HOST' => $host])
        ->assertOk();

    $this->withSession($verifiedSession)->getJson("http://{$host}/my/abstracts", ['HTTP_HOST' => $host])
        ->assertOk();

    $this->withSession($verifiedSession)->getJson("http://{$host}/my/attendance", ['HTTP_HOST' => $host])
        ->assertOk();
});
