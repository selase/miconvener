<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enum\TenantStatusEnum;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeHistory;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function recordsPlatformHost(): string
{
    return app(TenantHostMatcher::class)->baseDomain();
}

function recordsAttendeeSession(string $email): array
{
    return [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => PlatformAttendeeVerification::normalise($email),
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ];
}

test('getCertificates collapses duplicates matching both registration and recipient_email', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'doctor@example.com';

    $tenant = Tenant::factory()->create([
        'name' => 'Medical Association',
        'slug' => 'med-assoc',
        'status' => TenantStatusEnum::ACTIVE,
        'isolation_mode' => 'shared',
    ]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Annual Medical Congress 2026',
    ]);

    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'full_name' => 'Dr. Kwame Mensah',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Certificate matches via BOTH registration_id and recipient_email
    $cert = EventCertificate::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $reg->id,
        'recipient_email' => $email,
        'recipient_name' => 'Dr. Kwame Mensah',
        'role' => 'Attendee',
        'cpd_hours' => 6.0,
        'issued_at' => now(),
    ]);

    $certificates = $service->getCertificates($email);

    expect($certificates)->toHaveCount(1)
        ->and($certificates[0]['uuid'])->toBe($cert->uuid)
        ->and($certificates[0]['recipient_name'])->toBe('Dr. Kwame Mensah')
        ->and($certificates[0]['cpd_hours'])->toBe(6.0)
        ->and($certificates[0]['verification_url'])->toBe($cert->verificationUrl())
        ->and($certificates[0]['download_url'])->toContain("/verify/cert/{$cert->uuid}/download");
});

test('getCertificates includes speaker or volunteer certificates without registration', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'keynote@example.com';

    $tenant = Tenant::factory()->create([
        'name' => 'African Science Forum',
        'slug' => 'asf',
        'status' => TenantStatusEnum::ACTIVE,
        'isolation_mode' => 'shared',
    ]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Science Summit 2026',
    ]);

    // Certificate issued directly to recipient_email without an EventRegistration
    $cert = EventCertificate::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => null,
        'recipient_email' => $email,
        'recipient_name' => 'Prof. Ama Serwaa',
        'role' => 'Distinguished Speaker',
        'cpd_hours' => 10.0,
        'issued_at' => now(),
    ]);

    $certificates = $service->getCertificates($email);

    expect($certificates)->toHaveCount(1)
        ->and($certificates[0]['uuid'])->toBe($cert->uuid)
        ->and($certificates[0]['role'])->toBe('Distinguished Speaker')
        ->and($certificates[0]['cpd_hours'])->toBe(10.0)
        ->and($certificates[0]['organiser_name'])->toBe('African Science Forum');
});

test('getCertificates excludes certificates from banned tenants', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'scholar@example.com';

    $bannedTenant = Tenant::factory()->create([
        'status' => TenantStatusEnum::BANNED,
        'isolation_mode' => 'shared',
    ]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $bannedTenant->id,
    ]);

    EventCertificate::create([
        'tenant_id' => $bannedTenant->id,
        'event_id' => $event->id,
        'recipient_email' => $email,
        'recipient_name' => 'Scholar Person',
        'role' => 'Attendee',
        'issued_at' => now(),
    ]);

    expect($service->getCertificates($email))->toBeEmpty();
});

test('getAbstracts collapses duplicates across multiple co-author entries for same abstract', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'researcher@example.com';

    $tenant = Tenant::factory()->create([
        'name' => 'Research Institute',
        'slug' => 'res-inst',
        'status' => TenantStatusEnum::ACTIVE,
        'isolation_mode' => 'shared',
    ]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Genomics Conference 2026',
    ]);

    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-8092',
        'title' => 'Single Cell Transcriptomics of Malaria Parasites',
        'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
        'presentation_preference' => EventAbstract::PREFERENCE_ORAL,
    ]);

    // Two author entries for the same email on the same abstract (e.g. primary + corresponding)
    EventAbstractAuthor::create([
        'abstract_id' => $abstract->id,
        'first_name' => 'Dr.',
        'last_name' => 'Researcher',
        'email' => $email,
        'affiliation' => 'Genomics Lab',
        'is_presenting' => true,
        'is_corresponding' => true,
    ]);

    EventAbstractAuthor::create([
        'abstract_id' => $abstract->id,
        'first_name' => 'Dr.',
        'last_name' => 'Researcher (Duplicate)',
        'email' => $email,
        'affiliation' => 'Genomics Lab',
        'is_presenting' => false,
        'is_corresponding' => false,
    ]);

    $abstracts = $service->getAbstracts($email);

    // Collapsed to exactly one abstract entry
    expect($abstracts)->toHaveCount(1)
        ->and($abstracts[0]['id'])->toBe($abstract->id)
        ->and($abstracts[0]['code'])->toBe('ABS-8092')
        ->and($abstracts[0]['title'])->toBe('Single Cell Transcriptomics of Malaria Parasites')
        ->and($abstracts[0]['status'])->toBe('accepted_oral')
        ->and($abstracts[0]['presentation_preference'])->toBe('oral')
        ->and($abstracts[0]['event_name'])->toBe('Genomics Conference 2026');
});

test('getAbstracts includes abstracts for co-author without event registration', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'coauthor@external-uni.edu';

    $tenant = Tenant::factory()->create([
        'name' => 'Public Health Forum',
        'slug' => 'phf',
        'status' => TenantStatusEnum::ACTIVE,
        'isolation_mode' => 'shared',
    ]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Public Health Summit 2026',
    ]);

    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-4410',
        'title' => 'Epidemiological Surveillance in Rural Clinics',
        'status' => EventAbstract::STATUS_UNDER_REVIEW,
        'presentation_preference' => EventAbstract::PREFERENCE_POSTER,
    ]);

    // Author created without any EventRegistration in the database
    EventAbstractAuthor::create([
        'abstract_id' => $abstract->id,
        'first_name' => 'External',
        'last_name' => 'Co-Author',
        'email' => $email,
        'affiliation' => 'External University',
        'is_presenting' => false,
    ]);

    $abstracts = $service->getAbstracts($email);

    expect($abstracts)->toHaveCount(1)
        ->and($abstracts[0]['code'])->toBe('ABS-4410')
        ->and($abstracts[0]['status'])->toBe('under_review')
        ->and($abstracts[0]['presentation_preference'])->toBe('poster');
});

test('getAttendance returns session check-ins across multiple tenants excluding banned tenants', function (): void {
    $service = app(PlatformAttendeeHistory::class);
    $email = 'attendee@sessions.com';

    // Active Organiser A
    $orgA = Tenant::factory()->create(['name' => 'Tech Alliance', 'slug' => 'tech-alliance', 'status' => TenantStatusEnum::ACTIVE, 'isolation_mode' => 'shared']);
    $eventA = Event::factory()->published()->create(['tenant_id' => $orgA->id, 'name' => 'Cloud Con 2026']);
    $regA = EventRegistration::factory()->create(['tenant_id' => $orgA->id, 'event_id' => $eventA->id, 'email' => $email]);
    $sessionA = EventSession::create([
        'tenant_id' => $orgA->id,
        'event_id' => $eventA->id,
        'title' => 'Serverless Architectures',
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHours(2),
    ]);
    EventSessionAttendance::create([
        'tenant_id' => $orgA->id,
        'event_id' => $eventA->id,
        'session_id' => $sessionA->id,
        'registration_id' => $regA->id,
        'checked_in_at' => now()->subHours(3),
        'checked_out_at' => now()->subHours(2),
    ]);

    // Banned Organiser B
    $bannedOrg = Tenant::factory()->create(['status' => TenantStatusEnum::BANNED, 'isolation_mode' => 'shared']);
    $eventB = Event::factory()->published()->create(['tenant_id' => $bannedOrg->id]);
    $regB = EventRegistration::factory()->create(['tenant_id' => $bannedOrg->id, 'event_id' => $eventB->id, 'email' => $email]);
    $sessionB = EventSession::create([
        'tenant_id' => $bannedOrg->id,
        'event_id' => $eventB->id,
        'title' => 'Banned Session',
        'starts_at' => now()->subHours(5),
        'ends_at' => now()->subHours(4),
    ]);
    EventSessionAttendance::create([
        'tenant_id' => $bannedOrg->id,
        'event_id' => $eventB->id,
        'session_id' => $sessionB->id,
        'registration_id' => $regB->id,
        'checked_in_at' => now()->subHours(5),
    ]);

    $attendance = $service->getAttendance($email);

    // Only active session attendance is returned; banned tenant is strictly excluded
    expect($attendance)->toHaveCount(1)
        ->and($attendance[0]['session_title'])->toBe('Serverless Architectures')
        ->and($attendance[0]['event_name'])->toBe('Cloud Con 2026')
        ->and($attendance[0]['organiser_name'])->toBe('Tech Alliance');
});

test('central /my page preloads initialCertificates, initialAbstracts, and initialAttendance for verified attendees', function (): void {
    $host = recordsPlatformHost();
    $email = 'verified@records.com';

    $tenant = Tenant::factory()->create(['name' => 'Global Forum', 'slug' => 'global-forum', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'Leadership Summit 2026']);
    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Seed certificate
    EventCertificate::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $reg->id,
        'recipient_email' => $email,
        'recipient_name' => 'Verified Attendee',
        'role' => 'Attendee',
        'cpd_hours' => 4.0,
        'issued_at' => now(),
    ]);

    // Seed abstract
    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-1010',
        'title' => 'Transformative Leadership in African Governance',
        'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
        'presentation_preference' => EventAbstract::PREFERENCE_ORAL,
    ]);
    EventAbstractAuthor::create([
        'abstract_id' => $abstract->id,
        'first_name' => 'Verified',
        'last_name' => 'Attendee',
        'email' => $email,
        'affiliation' => 'African Governance Institute',
    ]);

    // Seed session attendance
    $session = EventSession::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Opening Plenary',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addHour(),
    ]);
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'registration_id' => $reg->id,
        'checked_in_at' => now()->subDay(),
    ]);

    $response = $this->withSession(recordsAttendeeSession($email))
        ->get("http://{$host}/my", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Public/Events/AttendeePortal/MyPortal')
        ->has('initialHistory')
        ->has('initialCertificates', 1)
        ->where('initialCertificates.0.cpd_hours', fn ($val): bool => (float) $val === 4.0)
        ->has('initialAbstracts', 1)
        ->where('initialAbstracts.0.code', 'ABS-1010')
        ->has('initialAttendance', 1)
        ->where('initialAttendance.0.session_title', 'Opening Plenary')
    );
});

test('central /my page passes null initial record props for unverified requests', function (): void {
    $host = recordsPlatformHost();

    $response = $this->get("http://{$host}/my", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Public/Events/AttendeePortal/MyPortal')
        ->where('verifiedEmail', null)
        ->where('initialHistory', null)
        ->where('initialCertificates', null)
        ->where('initialAbstracts', null)
        ->where('initialAttendance', null)
    );
});

test('authenticated JSON endpoints return records and support organiser filter', function (): void {
    $host = recordsPlatformHost();
    $email = 'multi@records.com';

    $org1 = Tenant::factory()->create(['name' => 'Org One', 'slug' => 'org-one', 'isolation_mode' => 'shared']);
    $event1 = Event::factory()->published()->create(['tenant_id' => $org1->id]);
    $reg1 = EventRegistration::factory()->create(['tenant_id' => $org1->id, 'event_id' => $event1->id, 'email' => $email]);
    EventCertificate::create([
        'tenant_id' => $org1->id,
        'event_id' => $event1->id,
        'registration_id' => $reg1->id,
        'recipient_email' => $email,
        'recipient_name' => 'Multi Attendee',
        'role' => 'Participant',
        'issued_at' => now(),
    ]);

    $org2 = Tenant::factory()->create(['name' => 'Org Two', 'slug' => 'org-two', 'isolation_mode' => 'shared']);
    $event2 = Event::factory()->published()->create(['tenant_id' => $org2->id]);
    $reg2 = EventRegistration::factory()->create(['tenant_id' => $org2->id, 'event_id' => $event2->id, 'email' => $email]);
    EventCertificate::create([
        'tenant_id' => $org2->id,
        'event_id' => $event2->id,
        'registration_id' => $reg2->id,
        'recipient_email' => $email,
        'recipient_name' => 'Multi Attendee',
        'role' => 'Participant',
        'issued_at' => now(),
    ]);

    $session = recordsAttendeeSession($email);

    // Unfiltered returns certificates from both organisers
    $unfilteredRes = $this->withSession($session)
        ->getJson("http://{$host}/my/certificates", ['HTTP_HOST' => $host]);
    $unfilteredRes->assertOk()->assertJsonCount(2);

    // Filtered by organiser org-one returns only 1 certificate
    $filteredRes = $this->withSession($session)
        ->getJson("http://{$host}/my/certificates?organiser=org-one", ['HTTP_HOST' => $host]);
    $filteredRes->assertOk()->assertJsonCount(1);
    expect($filteredRes->json('0.organiser_name'))->toBe('Org One');
});

test('event workspace endpoint returns registration certificate and attendance records', function (): void {
    $host = recordsPlatformHost();
    $email = 'workspace-attendee@example.com';

    $tenant = Tenant::factory()->create(['name' => 'Workspace Org', 'slug' => 'workspace-org', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'DevOps Days 2026']);
    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $cert = EventCertificate::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $reg->id,
        'recipient_email' => $email,
        'recipient_name' => 'Jane Dev',
        'role' => 'Speaker',
        'cpd_hours' => 3.5,
        'issued_at' => now(),
    ]);

    $session = EventSession::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Kubernetes in Production',
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
    ]);

    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'registration_id' => $reg->id,
        'checked_in_at' => now()->subHours(2),
        'checked_out_at' => now()->subHour(),
    ]);

    $response = $this->withSession(recordsAttendeeSession($email))
        ->get("http://{$host}/my/events/{$reg->id}", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Public/Events/AttendeePortal/Portal')
        ->where('certificate.uuid', $cert->uuid)
        ->where('certificate.role', 'Speaker')
        ->where('certificate.cpd_hours', 3.5)
        ->has('attendance', 1)
        ->where('attendance.0.session_title', 'Kubernetes in Production')
    );
});
