<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationNeedsApproval;
use App\Mail\Events\EventRegistrationOfflineProofSubmitted;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\PlatformAttendeeWorkspaceAuthorizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Mail that asks the organizer to act -- approve a registration, check a
 * payment slip -- reaches the people who run the organization, not only the
 * organization's email address, which many organizations never set.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function organizerWith(?string $orgEmail, string $role = 'Org Superadmin', string $userEmail = 'owner@grace.test'): array
{
    $tenant = Tenant::factory()->create(['slug' => 'grace', 'isolation_mode' => 'shared', 'email' => $orgEmail]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => $userEmail]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('the organizer recipients are the organization email plus its admins, once each', function (): void {
    [$tenant] = organizerWith('Office@Grace.test');
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'office@grace.test']);
    $admin->assignRole('Org Admin');
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'usher@grace.test']);
    $staff->assignRole('Event Staff');

    expect($tenant->organizerNotificationEmails())->toEqualCanonicalizing(['office@grace.test', 'owner@grace.test']);
});

test('an organization without an email still reaches its owner', function (): void {
    [$tenant] = organizerWith(null);

    expect($tenant->organizerNotificationEmails())->toBe(['owner@grace.test']);
});

test('admins of another organization are never included', function (): void {
    [$tenant] = organizerWith(null);
    $other = Tenant::factory()->create(['slug' => 'other', 'isolation_mode' => 'shared']);
    $outsider = User::factory()->create(['tenant_id' => $other->id, 'email' => 'outsider@other.test']);
    setPermissionsTeamId($other->id);
    $outsider->assignRole('Org Superadmin');

    expect($tenant->organizerNotificationEmails())->toBe(['owner@grace.test']);
});

test('a payment slip reaches the owner of an organization with no email', function (): void {
    Mail::fake();
    Storage::fake(Event::uploadDisk());
    [$tenant] = organizerWith(null);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'allow_offline_payments' => true]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 5000,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);
    $host = eventSubdomainHost('grace');

    $this->withSession([
        PlatformAttendeeWorkspaceAuthorizer::REGISTERED_SESSION_KEY => [$registration->id => now()->getTimestamp()],
    ])->post("http://{$host}/e/{$event->slug}/checkout/{$registration->id}/offline-proof", [
        'proof_file' => UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf'),
        'payment_method' => EventRegistration::PAYMENT_METHOD_OFFLINE_BANK,
    ])->assertRedirect();

    Mail::assertQueued(EventRegistrationOfflineProofSubmitted::class, fn ($mail): bool => $mail->hasTo('owner@grace.test'));
});

test('a registration needing approval reaches the owner of an organization with no email', function (): void {
    Mail::fake();
    [$tenant] = organizerWith(null);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'requires_approval' => true, 'ticket_price' => 0]);
    $host = eventSubdomainHost('grace');

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Mensah',
        'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host])->assertRedirect();

    expect(EventRegistration::where('email', 'ama@example.com')->value('status'))->toBe(EventRegistration::STATUS_PENDING_APPROVAL);

    Mail::assertQueued(EventRegistrationNeedsApproval::class, fn ($mail): bool => $mail->hasTo('owner@grace.test'));
});
