<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationOfflineProofSubmitted;
use App\Mail\Events\EventRegistrationOfflineRejected;
use App\Mail\Events\EventRegistrationPendingVerification;
use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\PlatformAttendeeWorkspaceAuthorizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function offlinePaymentHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme-corp', 'isolation_mode' => 'shared', 'email' => 'finance@acme.test']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function offlineSubdomainHost(string $subdomain = 'acme-corp'): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$subdomain}.{$baseDomain}";
}

test('organizer can configure offline payment settings on an event', function () {
    [$tenant, $user] = offlinePaymentHost();
    $host = offlineSubdomainHost($tenant->slug);

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'African Tech Summit',
        'event_category' => 'conference',
        'starts_at' => now()->addDays(7)->toDateTimeLocalString(),
        'ends_at' => now()->addDays(8)->toDateTimeLocalString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Accra International Conference Centre',
        'ticket_price' => 50000, // 500.00 GHS in pesewas
        'currency' => 'GHS',
        'fee_bearer' => 'organizer',
        'status' => 'published',
        'allow_offline_payments' => true,
        'offline_payment_bank_name' => 'Ecobank Ghana',
        'offline_payment_account_name' => 'Acme Technologies Ltd',
        'offline_payment_account_number' => '14410012345678',
        'offline_payment_momo_network' => 'MTN MoMo',
        'offline_payment_momo_number' => '0244123456',
        'offline_payment_instructions' => 'Please include your full name in the bank transfer memo.',
    ]);

    $response->assertRedirect();

    $event = Event::where('tenant_id', $tenant->id)->firstOrFail();
    expect($event->allowsOfflinePayments())->toBeTrue()
        ->and($event->offline_payment_bank_name)->toBe('Ecobank Ghana')
        ->and($event->offline_payment_account_number)->toBe('14410012345678')
        ->and($event->offline_payment_momo_number)->toBe('0244123456');
});

test('public checkout renders the checkout view when event allows offline payments', function () {
    [$tenant] = offlinePaymentHost();
    $host = offlineSubdomainHost($tenant->slug);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_offline_payments' => true,
        'offline_payment_bank_name' => 'Standard Chartered',
        'offline_payment_account_name' => 'Acme Events',
        'offline_payment_account_number' => '0100123456700',
    ]);

    $ticket = EventTicketType::factory()->create([
        'event_id' => $event->id,
        'name' => 'VIP Pass',
        'price' => 15000, // 150 GHS
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'ticket_type_id' => $ticket->id,
        'amount' => 15000,
        'charged_amount' => 15000,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    // Give checkout session permission
    $response = $this->withSession([
        PlatformAttendeeWorkspaceAuthorizer::REGISTERED_SESSION_KEY => [$registration->id => now()->getTimestamp()],
    ])->get("http://{$host}/e/{$event->slug}/checkout/{$registration->id}");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Events/Checkout')
            ->has('event')
            ->where('event.allow_offline_payments', true)
            ->where('event.offline_payment_bank_name', 'Standard Chartered')
            ->has('registration')
            ->where('registration.id', $registration->id)
            ->where('registration.amount', 15000)
        );
});

test('attendee can submit offline payment proof with slip upload', function () {
    Mail::fake();
    Storage::fake(Event::uploadDisk());

    [$tenant] = offlinePaymentHost();
    $host = offlineSubdomainHost($tenant->slug);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_offline_payments' => true,
        'offline_payment_bank_name' => 'Fidelity Bank',
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 20000,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
        'email' => 'attendee@example.com',
    ]);

    $file = UploadedFile::fake()->create('bank_receipt.pdf', 500, 'application/pdf');

    $response = $this->withSession([
        PlatformAttendeeWorkspaceAuthorizer::REGISTERED_SESSION_KEY => [$registration->id => now()->getTimestamp()],
    ])->post("http://{$host}/e/{$event->slug}/checkout/{$registration->id}/offline-proof", [
        'proof_file' => $file,
        'payment_method' => EventRegistration::PAYMENT_METHOD_OFFLINE_BANK,
        'offline_payment_reference' => 'TXN-987654321',
        'offline_payment_notes' => 'Transferred from Corporate Barclays account',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $registration->refresh();
    expect($registration->payment_method)->toBe(EventRegistration::PAYMENT_METHOD_OFFLINE_BANK)
        ->and($registration->offline_payment_status)->toBe(EventRegistration::OFFLINE_STATUS_PENDING_VERIFICATION)
        ->and($registration->offline_payment_reference)->toBe('TXN-987654321')
        ->and($registration->offline_payment_notes)->toBe('Transferred from Corporate Barclays account')
        ->and($registration->offline_payment_proof_path)->not->toBeNull()
        ->and($registration->offline_payment_submitted_at)->not->toBeNull();

    // Verify files on storage
    Storage::disk(Event::uploadDisk())->assertExists($registration->offline_payment_proof_path);

    // Verify notifications queued
    Mail::assertQueued(EventRegistrationPendingVerification::class, function ($mail) use ($registration) {
        return $mail->hasTo('attendee@example.com') && $mail->registration->id === $registration->id;
    });

    Mail::assertQueued(EventRegistrationOfflineProofSubmitted::class, function ($mail) use ($tenant) {
        return $mail->hasTo($tenant->email);
    });
});

test('submitting proof rejects invalid files or over 10MB', function () {
    [$tenant] = offlinePaymentHost();
    $host = offlineSubdomainHost($tenant->slug);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_offline_payments' => true,
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
        'charged_amount' => 10000,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    $invalidFile = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');

    $response = $this->withSession([
        PlatformAttendeeWorkspaceAuthorizer::REGISTERED_SESSION_KEY => [$registration->id => now()->getTimestamp()],
    ])->post("http://{$host}/e/{$event->slug}/checkout/{$registration->id}/offline-proof", [
        'proof_file' => $invalidFile,
        'payment_method' => EventRegistration::PAYMENT_METHOD_OFFLINE_BANK,
    ]);

    $response->assertSessionHasErrors(['proof_file']);
});

test('organizer can approve offline payment, confirming registration and debiting commission from payable', function () {
    Mail::fake();
    [$tenant, $user] = offlinePaymentHost();
    $host = offlineSubdomainHost($tenant->slug);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_offline_payments' => true,
        'fee_bearer' => 'organizer',
        'platform_fee_percentage' => '5.00', // 5% fee override for explicit commission testing
    ]);

    $ticket = EventTicketType::factory()->create([
        'event_id' => $event->id,
        'price' => 10000, // 100.00 GHS in pesewas
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'ticket_type_id' => $ticket->id,
        'amount' => 10000,
        'charged_amount' => 10000,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
        'payment_method' => EventRegistration::PAYMENT_METHOD_OFFLINE_MOMO,
        'offline_payment_status' => EventRegistration::OFFLINE_STATUS_PENDING_VERIFICATION,
        'offline_payment_reference' => 'MOMO-789012',
    ]);

    $response = $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/registrations/{$registration->id}/approve-offline");

    $response->assertOk()
        ->assertJson([
            'message' => 'Offline payment verified and registration confirmed.',
        ]);

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED)
        ->and($registration->offline_payment_status)->toBe(EventRegistration::OFFLINE_STATUS_APPROVED)
        ->and($registration->ticket_code)->not->toBeNull()
        ->and($registration->qr_token)->not->toBeNull()
        ->and((string) $registration->offline_payment_verified_by)->toBe((string) $user->id)
        ->and($registration->offline_payment_verified_at)->not->toBeNull();

    // Verify confirmation email dispatched
    Mail::assertQueued(EventRegistrationConfirmed::class, function ($mail) use ($registration) {
        return $mail->registration->id === $registration->id;
    });

    // Verify Double-Entry Ledger parity
    $expectedReference = 'OFFLINE-'.$registration->id;
    $ledgerTransaction = LedgerTransaction::where('tenant_id', $tenant->id)
        ->where('reference', $expectedReference)
        ->first();

    // Check flat event ledger entry
    $flatEntry = EventLedgerEntry::where('event_id', $event->id)
        ->where('provider_reference', $expectedReference)
        ->first();

    expect($flatEntry)->not->toBeNull()
        ->and($flatEntry->gross_amount)->toBe(10000)
        ->and($flatEntry->gateway_fee_amount)->toBe(0)
        ->and($flatEntry->commission_amount)->toBe(500) // 5% of 10000
        ->and($flatEntry->net_amount)->toBe(-500);

    // Verify double-entry transaction and trial balance
    expect($ledgerTransaction)->not->toBeNull();

    $entries = LedgerEntry::where('transaction_id', $ledgerTransaction->id)->get();
    expect($entries)->toHaveCount(2);

    $debitEntry = $entries->firstWhere('direction', LedgerEntry::DIRECTION_DEBIT);
    $creditEntry = $entries->firstWhere('direction', LedgerEntry::DIRECTION_CREDIT);

    expect($debitEntry->account->code)->toBe(LedgerAccount::CODE_ORGANIZER_PAYABLE)
        ->and($creditEntry->account->code)->toBe(LedgerAccount::CODE_PLATFORM_REVENUE)
        ->and($debitEntry->amount)->toBe(500)
        ->and($creditEntry->amount)->toBe(500);
});

test('approving already confirmed registration returns 422', function () {
    [$tenant, $user] = offlinePaymentHost();
    $host = offlineSubdomainHost($tenant->slug);

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'offline_payment_status' => EventRegistration::OFFLINE_STATUS_APPROVED,
    ]);

    $response = $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/registrations/{$registration->id}/approve-offline");

    $response->assertStatus(422);
});

test('organizer can reject offline payment and provide a note for the attendee', function () {
    Mail::fake();
    [$tenant, $user] = offlinePaymentHost();
    $host = offlineSubdomainHost($tenant->slug);

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
        'offline_payment_status' => EventRegistration::OFFLINE_STATUS_PENDING_VERIFICATION,
        'email' => 'attendee@example.com',
    ]);

    $response = $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/registrations/{$registration->id}/reject-offline", [
            'note' => 'Transaction ID was not found on our statement. Please check and re-upload.',
        ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Offline payment rejected and attendee notified.',
        ]);

    $registration->refresh();
    expect($registration->offline_payment_status)->toBe(EventRegistration::OFFLINE_STATUS_REJECTED)
        ->and($registration->offline_payment_notes)->toBe('Transaction ID was not found on our statement. Please check and re-upload.')
        ->and($registration->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);

    // Verify rejection notification queued
    Mail::assertQueued(EventRegistrationOfflineRejected::class, function ($mail) use ($registration) {
        return $mail->hasTo('attendee@example.com') && $mail->registration->id === $registration->id;
    });
});

test('cross-tenant user cannot approve or reject another tenants offline payment', function () {
    [$tenantA] = offlinePaymentHost();
    $tenantB = Tenant::factory()->create(['slug' => 'tenant-b', 'isolation_mode' => 'shared']);
    $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
    setPermissionsTeamId($tenantB->id);
    $userB->assignRole('Org Superadmin');
    $tenantB->users()->attach($userB->id);

    $hostB = offlineSubdomainHost($tenantB->slug);

    $eventA = Event::factory()->published()->create(['tenant_id' => $tenantA->id]);
    $registrationA = EventRegistration::factory()->create([
        'tenant_id' => $tenantA->id,
        'event_id' => $eventA->id,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
        'offline_payment_status' => EventRegistration::OFFLINE_STATUS_PENDING_VERIFICATION,
    ]);

    // User B attempts to approve registration from Tenant A
    $response = $this->actingAs($userB)
        ->post("http://{$hostB}/events/{$eventA->id}/registrations/{$registrationA->id}/approve-offline");

    $response->assertStatus(404);
});
