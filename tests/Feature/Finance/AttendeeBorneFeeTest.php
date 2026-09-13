<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a registration created before pass-through existed still charges its ticket price', function () {
    $tenant = Tenant::factory()->create(['package_id' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
        'charged_amount' => null,
    ]);

    expect($registration->effectiveChargedAmount())->toBe(10000);
});

test('an attendee-borne event records a charged amount above the ticket price', function () {
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => 2.0]);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => 'attendee',
        'platform_fee_cap_amount' => 2000,
        'currency' => 'GHS',
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
        'platform_fee_amount' => 200,
        'charged_amount' => 10200,
    ]);

    expect($registration->effectiveChargedAmount())->toBe(10200)
        ->and($registration->amount)->toBe(10000);
});

test('the gateway is told to collect the charged amount, not the bare ticket price', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $tenant->update(['settlement_mode' => 'platform_default', 'platform_fee_percentage' => 2.0]);

    config([
        'services.paystack.secret_key' => 'sk_test_checkout_123',
        'services.settlement.paystack.secret_key' => 'sk_test_checkout_123',
    ]);

    Http::fake([
        'api.paystack.co/customer*' => Http::response([
            'status' => true,
            'data' => ['id' => 1, 'customer_code' => 'CUS_test_1', 'email' => 'buyer@example.com'],
        ]),
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/test'],
        ]),
    ]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => 'attendee',
        'currency' => 'GHS',
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'buyer@example.com',
        'amount' => 10000,
        'platform_fee_amount' => 200,
        'charged_amount' => 10200,
        'currency' => 'GHS',
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    $this->get("http://{$host}/e/{$event->id}/checkout/{$registration->id}", ['HTTP_HOST' => $host]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'transaction/initialize')
        && (int) $request['amount'] === 10200);
});

test('the public page does not publish the commission rate when the organizer absorbs it', function () {
    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $tenant->update(['platform_fee_percentage' => 1.25]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => 'organizer',
        'currency' => 'GHS',
        'visibility' => 'public',
    ]);

    // A negotiated rate is a commercial term, not something to put on a page
    // anyone — including another tenant — can open.
    $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('event.fee_bearer', 'organizer')
            ->missing('event.platform_fee_percentage')
            ->missing('event.platform_fee_cap_amount')
        );
});

test('the public page does publish the rate when the attendee is charged it', function () {
    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $tenant->update(['platform_fee_percentage' => 2.0]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => 'attendee',
        'currency' => 'GHS',
        'visibility' => 'public',
    ]);

    $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            // JSON renders 2.0 as 2, so compare loosely on value, not type.
            ->where('event.platform_fee_percentage', fn ($value) => (float) $value === 2.0)
            ->has('event.platform_fee_cap_amount')
        );
});
