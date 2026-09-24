<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enum\TenantStatusEnum;
use App\Models\Event;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeHistory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A registration carries one email at a time, and a transfer replaces it. The
 * portal identifies people by address, so anything reached through the
 * registration alone follows the ticket to its new holder -- including the
 * previous holder's certificate and the sessions they personally attended.
 * A two-day conference where a colleague takes over on day two is the ordinary
 * case, not a contrived one.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function transferredSetup(): array
{
    $tenant = Tenant::factory()->create([
        'slug' => 'handover',
        'status' => TenantStatusEnum::ACTIVE,
        'isolation_mode' => 'shared',
    ]);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDay(),
    ]);

    // The ticket now belongs to the second holder.
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'second@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    EventRegistrationTransfer::create([
        'tenant_id' => $tenant->id,
        'registration_id' => $registration->id,
        'to_full_name' => 'Second Holder',
        'to_email' => 'second@example.com',
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->subDays(2)->addMinutes(15),
        'consumed_at' => now()->subDays(2),
    ]);

    return [$tenant, $event, $registration];
}

test('a certificate issued to the previous holder does not follow the ticket', function (): void {
    [$tenant, $event, $registration] = transferredSetup();

    EventCertificate::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'recipient_name' => 'First Holder',
        'recipient_email' => 'first@example.com',
        'role' => 'Delegate',
        'cpd_hours' => 6.0,
        'issued_at' => now()->subDays(3),
    ]);

    $service = app(PlatformAttendeeHistory::class);

    expect($service->getCertificates('second@example.com'))->toBeEmpty();

    // Positive control: it still belongs to the person it names.
    expect($service->getCertificates('first@example.com'))->toHaveCount(1);
});

test('sessions attended before the handover stay with the person who attended them', function (): void {
    [$tenant, $event, $registration] = transferredSetup();

    $session = EventSession::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Day one keynote',
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(3)->addHour(),
    ]);
    $laterSession = EventSession::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Day two workshop',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addHour(),
    ]);

    // Day one: attended by the first holder, before the handover.
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'registration_id' => $registration->id,
        'checked_in_at' => now()->subDays(3),
    ]);

    // Day two: attended by the new holder, after it.
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $laterSession->id,
        'registration_id' => $registration->id,
        'checked_in_at' => now()->subDay(),
    ]);

    $attendance = app(PlatformAttendeeHistory::class)->getAttendance('second@example.com');

    expect($attendance)->toHaveCount(1)
        ->and($attendance[0]['session_title'])->toBe('Day two workshop');
});

test('an untransferred ticket keeps every record it always had', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'no-handover',
        'status' => TenantStatusEnum::ACTIVE,
        'isolation_mode' => 'shared',
    ]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'sole@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Issued to the address as it was spelt then. The organiser later corrected
    // the registration; with no handover in between, the ticket still reaches
    // the certificate.
    EventCertificate::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'recipient_name' => 'Sole Holder',
        'recipient_email' => 'sole@exmaple.com',
        'role' => 'Delegate',
        'cpd_hours' => 3.0,
        'issued_at' => now(),
    ]);

    $session = EventSession::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Opening',
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHours(2),
    ]);
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'registration_id' => $registration->id,
        'checked_in_at' => now()->subHours(3),
    ]);

    $service = app(PlatformAttendeeHistory::class);

    expect($service->getCertificates('sole@example.com'))->toHaveCount(1)
        ->and($service->getAttendance('sole@example.com'))->toHaveCount(1);
});
