<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\Speaker;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('host can view certificate management dashboard with auto-seeded templates', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'science-accreditation-2026',
    ]);

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/certificates", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonStructure([
        'templates',
        'certificates',
        'stats' => [
            'total_issued',
            'delegates',
            'speakers',
            'presenters',
            'volunteers',
            'total_downloads',
        ],
        'eligible',
    ]);

    expect($event->certificateTemplates()->count())->toBe(4);
});

test('organizer can customize certificate templates with placeholders', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'med-summit-2026',
    ]);

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/certificates/templates", [
            'role' => 'delegate',
            'title' => 'Certificate of Clinical Excellence & CPD Accreditation',
            'body_template' => 'This confirms that {name} actively attended {event_name} on {date} and earned {hours} CME units.',
            'issuer_name' => 'Prof. Kwame Asare',
            'issuer_title' => 'President, Medical Council',
            'show_qr' => true,
            'show_cpd_hours' => true,
            'default_cpd_hours' => 12.5,
        ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $template = $event->certificateTemplates()->where('role', 'delegate')->firstOrFail();

    expect($template->title)->toBe('Certificate of Clinical Excellence & CPD Accreditation')
        ->and($template->default_cpd_hours)->toBe(12.5)
        ->and($template->issuer_name)->toBe('Prof. Kwame Asare');
});

test('organizer can bulk issue certificates to checked-in attendees', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'conf-attendees-2026',
    ]);

    // Checked-in attendee
    $reg1 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'email' => 'alice@hospital.org',
        'status' => EventRegistration::STATUS_CHECKED_IN,
    ]);

    // Confirmed but not checked-in attendee
    $reg2 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Bob',
        'last_name' => 'Jones',
        'email' => 'bob@hospital.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/certificates/issue", [
            'target_group' => 'checked_in_delegates',
            'role' => 'delegate',
            'cpd_hours' => 8.0,
        ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('issued_count', 1);

    expect($event->certificates()->where('recipient_email', 'alice@hospital.org')->exists())->toBeTrue()
        ->and($event->certificates()->where('recipient_email', 'bob@hospital.org')->exists())->toBeFalse();

    $cert = $event->certificates()->where('recipient_email', 'alice@hospital.org')->first();
    expect($cert->cpd_hours)->toBe(8.0)
        ->and($cert->verification_code)->toStartWith('MC-')
        ->and($cert->uuid)->not->toBeEmpty();
});

test('organizer can issue certificates to distinguished speakers and presenters', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'speakers-symposium-2026',
    ]);

    // Create speaker
    $speaker = Speaker::create([
        'tenant_id' => $tenant->id,
        'name' => 'Dr. Elena Rostova',
        'title' => 'Lead Neurosurgeon',
        'bio' => 'Lead neurosurgeon.',
    ]);

    $event->speakers()->syncWithoutDetaching([
        $speaker->id => [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenant->id,
            'role' => 'keynote_speaker',
            'portal_token' => \Illuminate\Support\Str::random(40),
        ],
    ]);

    // Issue to speakers
    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/certificates/issue", [
            'target_group' => 'speakers',
            'role' => 'speaker',
            'cpd_hours' => 10.0,
        ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('issued_count', 1);

    $speakerCert = $event->certificates()->where('role', 'speaker')->first();
    expect($speakerCert)->not->toBeNull()
        ->and($speakerCert->role)->toBe('speaker')
        ->and($speakerCert->recipient_name)->toBe('Dr. Elena Rostova');
});

test('organizer can download certificate as PDF', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'download-cert-2026',
    ]);

    $cert = EventCertificate::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'recipient_name' => 'Dr. John Doe',
        'recipient_email' => 'john.doe@med.org',
        'role' => 'delegate',
        'cpd_hours' => 5.5,
    ]);

    $response = $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}/certificates/{$cert->id}/download", ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($cert->fresh()->download_count)->toBe(1);
});

test('public user can verify credential and download authenticated certificate', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'accredited-event-2026',
        'name' => 'West African Medical Congress 2026',
    ]);

    $cert = EventCertificate::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'recipient_name' => 'Prof. Kwame Mensah',
        'recipient_email' => 'kwame.mensah@ug.edu.gh',
        'role' => 'speaker',
        'cpd_hours' => 15.0,
    ]);

    // 1. Valid verification
    $verifyRes = $this->get("http://{$host}/verify/cert/{$cert->uuid}", ['HTTP_HOST' => $host]);
    $verifyRes->assertOk();
    $verifyRes->assertInertia(fn ($page) => $page
        ->component('Public/Certificates/Verify')
        ->where('isValid', true)
        ->where('certificate.recipient_name', 'Prof. Kwame Mensah')
        ->where('certificate.verification_code', $cert->verification_code)
    );

    // 2. Invalid verification with bogus UUID
    $bogusRes = $this->get("http://{$host}/verify/cert/non-existent-uuid-999", ['HTTP_HOST' => $host]);
    $bogusRes->assertOk();
    $bogusRes->assertInertia(fn ($page) => $page
        ->component('Public/Certificates/Verify')
        ->where('isValid', false)
    );

    // 3. Public download
    $publicDownload = $this->get("http://{$host}/verify/cert/{$cert->uuid}/download", ['HTTP_HOST' => $host]);
    $publicDownload->assertOk();
    expect($publicDownload->headers->get('content-type'))->toBe('application/pdf');
});
