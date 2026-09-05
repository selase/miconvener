<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSeatAssignment;
use App\Models\EventVenueRoom;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function eventSubdomainHost(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

function eventHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('host can create an event', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Annual Conference',
        'description' => 'A great event',
        'status' => 'published',
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => '123 Main St',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect(Event::where('tenant_id', $tenant->id)->where('name', 'Annual Conference')->exists())->toBeTrue();
});

test('public free registration confirms instantly and emails a ticket', function () {
    Mail::fake();

    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $response = $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+233201234567',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'jane@example.com')->first();

    expect($registration)->not->toBeNull();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    expect($registration->ticket_code)->not->toBeNull();

    $response->assertRedirect(route('public.events.confirmation', [
        'subdomain' => $tenant->slug,
        'event' => $event->slug,
        'registration' => $registration->id,
    ]));

    Mail::assertQueued(EventRegistrationConfirmed::class);
});

test('a registrant can optionally record dietary requirements and accessibility needs', function () {
    Mail::fake();

    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Serwaa',
        'email' => 'ama@example.com',
        'dietary_requirements' => 'Vegetarian',
        'accessibility_needs' => 'Step-free access',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'ama@example.com')->firstOrFail();
    expect($registration->dietary_requirements)->toBe('Vegetarian');
    expect($registration->accessibility_needs)->toBe('Step-free access');
});

test('public paid registration redirects to Paystack checkout', function () {
    \Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/customer/*' => \Illuminate\Support\Facades\Http::response([
            'data' => ['customer_code' => 'CUS_123', 'email' => 'guest@example.com'],
        ]),
        'api.paystack.co/customer' => \Illuminate\Support\Facades\Http::response([
            'data' => ['customer_code' => 'CUS_123'],
        ]),
        'api.paystack.co/transaction/initialize' => \Illuminate\Support\Facades\Http::response([
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123'],
        ]),
    ]);

    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);

    $response = $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Paying Guest',
        'email' => 'guest@example.com',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'guest@example.com')->first();
    expect($registration->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);

    $response->assertRedirect(route('public.events.checkout', [
        'subdomain' => $tenant->slug,
        'event' => $event->slug,
        'registration' => $registration->id,
    ]));

    $checkoutResponse = $this->get($response->headers->get('Location'), ['HTTP_HOST' => $host]);
    $checkoutResponse->assertRedirect('https://checkout.paystack.com/abc123');
});

test('paystack webhook confirms the matching registration exactly once', function () {
    Mail::fake();

    [$tenant] = eventHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_abc123',
            'amount' => 5000,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $secret);

    $webhook = fn () => $this->call(
        'POST',
        "/webhooks/merchant/paystack/{$tenant->id}",
        [],
        [],
        [],
        ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body
    );

    $webhook()->assertOk();
    $webhook()->assertOk(); // delivered twice — must stay idempotent

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    expect($registration->ticket_code)->not->toBeNull();
    expect($registration->payment_reference)->toBe('ref_abc123');

    Mail::assertSent(EventRegistrationConfirmed::class, 1);
});

test('an event and its registrations are isolated to their own tenant', function () {
    [$tenantA, $userA] = eventHost('acme');
    [$tenantB] = eventHost('globex');

    $eventB = Event::factory()->create(['tenant_id' => $tenantB->id]);

    $hostA = eventSubdomainHost('acme');

    $this->actingAs($userA)
        ->get("http://{$hostA}/events/{$eventB->id}", ['HTTP_HOST' => $hostA])
        ->assertNotFound();
});

test('check-in marks a confirmed registration checked in and rejects a forged token', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
    ]);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/checkin/{$registration->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CHECKED_IN);
    expect($registration->checked_in_at)->not->toBeNull();

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/checkin/scan", ['token' => 'forged-token'], ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('checking in a guest with an assigned seat returns the seat and room in the response', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $room = EventVenueRoom::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Main Hall', 'rows' => 2, 'seats_per_row' => 2]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    EventSeatAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'room_id' => $room->id,
        'registration_id' => $registration->id,
        'seat_label' => 'A-01',
    ]);

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/checkin/{$registration->id}", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('registration.seat_label'))->toBe('A-01');
    expect($response->json('registration.room_name'))->toBe('Main Hall');
});

test('the guest list shows each registrant\'s assigned seat and room', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $room = EventVenueRoom::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Main Hall', 'rows' => 2, 'seats_per_row' => 2]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    EventSeatAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'room_id' => $room->id,
        'registration_id' => $registration->id,
        'seat_label' => 'B-02',
    ]);

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('registrations.0.seat_label', 'B-02')
        ->where('registrations.0.room_name', 'Main Hall'));
});

test('the confirmation page shows the registrant their assigned seat', function () {
    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $room = EventVenueRoom::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Main Hall', 'rows' => 2, 'seats_per_row' => 2]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);
    EventSeatAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'room_id' => $room->id,
        'registration_id' => $registration->id,
        'seat_label' => 'C-02',
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('registration.seat_label', 'C-02')
        ->where('registration.room_name', 'Main Hall'));
});
