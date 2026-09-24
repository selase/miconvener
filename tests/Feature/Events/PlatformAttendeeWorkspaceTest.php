<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventTicketTransferCode;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Events\PlatformAttendeeWorkspaceAuthorizer;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function platformDomainHost(): string
{
    return app(TenantHostMatcher::class)->baseDomain();
}

function verifiedSessionFor(string $email): array
{
    return [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => PlatformAttendeeVerification::normalise($email),
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ];
}

test('unauthenticated workspace access without checkout grant redirects to verification', function (): void {
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $response = $this->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/my?return_to=');
});

test('verified platform attendee can access their event workspace', function (): void {
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    $session = verifiedSessionFor('attendee@example.com');

    $this->withSession($session)
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/Portal')
            ->where('registration.id', $registration->id)
            ->where('registration.ticket_code', $registration->ticket_code)
            ->where('event.slug', $event->slug));
});

test('verified attendee cannot access a registration belonging to another email', function (): void {
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'other@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $session = verifiedSessionFor('attacker@example.com');

    $this->withSession($session)
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('a 30-minute checkout grant authorizes single-registration workspace view without email verification', function (): void {
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'payer@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    $checkoutGrantSession = [
        PlatformAttendeeWorkspaceAuthorizer::CHECKOUT_GRANTS_SESSION_KEY => [
            $registration->id => now()->addMinutes(30)->getTimestamp(),
        ],
    ];

    $this->withSession($checkoutGrantSession)
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/Portal')
            ->where('registration.id', $registration->id)
            ->where('is_checkout_grant', true));
});

test('an expired checkout grant fails closed and redirects to verification', function (): void {
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'payer@example.com',
    ]);

    $expiredGrantSession = [
        PlatformAttendeeWorkspaceAuthorizer::CHECKOUT_GRANTS_SESSION_KEY => [
            $registration->id => now()->subMinute()->getTimestamp(),
        ],
    ];

    $this->withSession($expiredGrantSession)
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertRedirect();
});

test('a checkout grant for one registration cannot authorize a different registration', function (): void {
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $regA = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $regB = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $grantSession = [
        PlatformAttendeeWorkspaceAuthorizer::CHECKOUT_GRANTS_SESSION_KEY => [
            $regA->id => now()->addMinutes(30)->getTimestamp(),
        ],
    ];

    // regA is authorized
    $this->withSession($grantSession)
        ->get("http://{$host}/my/events/{$regA->id}", ['HTTP_HOST' => $host])
        ->assertOk();

    // regB is not authorized
    $this->withSession($grantSession)
        ->get("http://{$host}/my/events/{$regB->id}", ['HTTP_HOST' => $host])
        ->assertRedirect();
});

test('bounded status polling returns 200 for authorized caller and 401 when unauthorized', function (): void {
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    // Unauthorized request
    $this->getJson("http://{$host}/my/events/{$registration->id}/status", ['HTTP_HOST' => $host])
        ->assertUnauthorized();

    // Authorized request
    $session = verifiedSessionFor('attendee@example.com');
    $this->withSession($session)
        ->getJson("http://{$host}/my/events/{$registration->id}/status", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson([
            'id' => $registration->id,
            'status' => 'confirmed',
            'is_confirmed' => true,
            'ticket_code' => $registration->ticket_code,
            'payment_confirmed' => true,
        ]);
});

test('pdf ticket download generates pdf for confirmed registration and rejects unconfirmed or unauthorized', function (): void {
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $confirmedReg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $confirmedReg->issueTicket();
    $confirmedReg->save();

    $unconfirmedReg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    $session = verifiedSessionFor('attendee@example.com');

    // Unauthorized
    $this->get("http://{$host}/my/events/{$confirmedReg->id}/ticket", ['HTTP_HOST' => $host])
        ->assertUnauthorized();

    // Authorized confirmed -> PDF download
    $response = $this->withSession($session)
        ->get("http://{$host}/my/events/{$confirmedReg->id}/ticket", ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');

    // Authorized unconfirmed -> 403 Forbidden
    $this->withSession($session)
        ->get("http://{$host}/my/events/{$unconfirmedReg->id}/ticket", ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('ticket transfer rotates credentials atomically and immediately revokes previous holders access', function (): void {
    Mail::fake();
    $host = platformDomainHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Original Holder',
        'email' => 'original@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    $originalTicketCode = $registration->ticket_code;
    $originalQrToken = $registration->qr_token;

    $originalSession = verifiedSessionFor('original@example.com');

    // 1. Stage transfer
    $stageRes = $this->withSession($originalSession)->postJson(
        "http://{$host}/my/events/{$registration->id}/transfer",
        ['full_name' => 'New Recipient', 'email' => 'recipient@example.com'],
        ['HTTP_HOST' => $host],
    );
    $stageRes->assertOk();
    Mail::assertQueued(EventTicketTransferCode::class);

    // Grab the generated code
    $transfer = \App\Models\EventRegistrationTransfer::where('registration_id', $registration->id)->latest()->first();

    // 2. Confirm transfer
    $confirmRes = $this->withSession($originalSession)->postJson(
        "http://{$host}/my/events/{$registration->id}/transfer/confirm",
        ['code' => 'TEST12'], // Code doesn't match yet
        ['HTTP_HOST' => $host],
    );
    $confirmRes->assertStatus(422);

    // Now confirm with matching code
    $plainCode = 'ABC123';
    $transfer->update(['code_hash' => \Illuminate\Support\Facades\Hash::make($plainCode)]);

    $confirmRes = $this->withSession($originalSession)->postJson(
        "http://{$host}/my/events/{$registration->id}/transfer/confirm",
        ['code' => $plainCode],
        ['HTTP_HOST' => $host],
    );
    $confirmRes->assertOk();

    $registration->refresh();

    // Assert holder changed
    expect($registration->email)->toBe('recipient@example.com')
        ->and($registration->full_name)->toBe('New Recipient');

    // Assert credentials rotated
    expect($registration->ticket_code)->not->toBe($originalTicketCode)
        ->and($registration->qr_token)->not->toBe($originalQrToken);

    // Assert original holder CANNOT access workspace anymore
    $this->withSession($originalSession)
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertForbidden();

    // Assert new holder CAN access workspace
    $recipientSession = verifiedSessionFor('recipient@example.com');
    $this->withSession($recipientSession)
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk();
});

test('checkout points Paystack at the workspace, and grants access only to a browser with a claim', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 5000,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    $baseDomain = app(TenantHostMatcher::class)->baseDomain();
    $subdomainHost = "acme.{$baseDomain}";

    $expectedWorkspaceUrl = "http://{$baseDomain}/my/events/{$registration->id}";

    config(['services.settlement.paystack.secret_key' => 'sk_test_123']);

    \Illuminate\Support\Facades\Http::fake([
        'https://api.paystack.co/customer' => \Illuminate\Support\Facades\Http::response(['data' => ['customer_code' => 'CUST_123']]),
        'https://api.paystack.co/customer/*' => \Illuminate\Support\Facades\Http::response(['data' => ['email' => $registration->email]]),
        'https://api.paystack.co/transaction/initialize' => function (\Illuminate\Http\Client\Request $request) use ($expectedWorkspaceUrl) {
            expect($request['callback_url'])->toBe($expectedWorkspaceUrl);

            return \Illuminate\Support\Facades\Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/access_code_123']]);
        },
    ]);

    // A stranger who merely knows the UUID may still pay -- a colleague settling
    // an invoice is a real case -- but takes away no access.
    $this->get("http://{$subdomainHost}/e/{$event->slug}/checkout/{$registration->id}", [
        'HTTP_HOST' => $subdomainHost,
    ]);

    expect(session(PlatformAttendeeWorkspaceAuthorizer::CHECKOUT_GRANTS_SESSION_KEY, []))
        ->not->toHaveKey($registration->id);

    // The person whose address it is does get the grant, so returning from
    // Paystack lands them on their own ticket.
    $this->withSession(verifiedSessionFor($registration->email))
        ->get("http://{$subdomainHost}/e/{$event->slug}/checkout/{$registration->id}", [
            'HTTP_HOST' => $subdomainHost,
        ]);

    $grants = session(PlatformAttendeeWorkspaceAuthorizer::CHECKOUT_GRANTS_SESSION_KEY, []);
    expect($grants)->toHaveKey($registration->id)
        ->and($grants[$registration->id])->toBeGreaterThan(now()->getTimestamp());
});
