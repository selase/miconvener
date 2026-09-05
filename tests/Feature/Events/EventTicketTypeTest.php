<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('registering for an event with ticket types requires selecting one', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'In-Person']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host])->assertSessionHasErrors('ticket_type_id');
});

test('each ticket type enforces its own capacity independently', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $inPerson = EventTicketType::factory()->withCapacity(1)->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'In-Person']);
    $virtual = EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Virtual']);

    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'ticket_type_id' => $inPerson->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // In-person is now sold out — the registration is waitlisted, not rejected.
    $blocked = $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Late Guest',
        'email' => 'late@example.com',
        'ticket_type_id' => $inPerson->id,
    ], ['HTTP_HOST' => $host]);

    $blocked->assertRedirect();
    expect(EventRegistration::where('email', 'late@example.com')->first()?->status)->toBe(EventRegistration::STATUS_WAITLISTED);

    // Virtual still has room.
    $ok = $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Virtual Guest',
        'email' => 'virtual@example.com',
        'ticket_type_id' => $virtual->id,
    ], ['HTTP_HOST' => $host]);

    $ok->assertRedirect();
    expect(EventRegistration::where('email', 'virtual@example.com')->first()?->ticket_type_id)->toBe($virtual->id);
});

test('platform fee is computed per event, falling back tenant then global default', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'platform_fee_percentage' => 8.0]);
    $globalDefault = Event::factory()->create(['tenant_id' => $tenant->id]);
    $override = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 2.5]);

    expect($globalDefault->effectivePlatformFeePercentage())->toBe(8.0);
    expect($override->effectivePlatformFeePercentage())->toBe(2.5);
});

test('registering for a paid ticket records the platform fee amount without deducting from the charge', function () {
    \Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/customer/*' => \Illuminate\Support\Facades\Http::response(['data' => ['customer_code' => 'CUS_1', 'email' => 'g@example.com']]),
        'api.paystack.co/customer' => \Illuminate\Support\Facades\Http::response(['data' => ['customer_code' => 'CUS_1']]),
        'api.paystack.co/transaction/initialize' => \Illuminate\Support\Facades\Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/x']]),
    ]);

    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'platform_fee_percentage' => 10.0]);
    \App\Models\TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'paystack', 'api_key_encrypted' => 'sk_test', 'is_active' => true]);

    $event = Event::factory()->published()->paid(10000)->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Guest',
        'email' => 'g@example.com',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'g@example.com')->first();

    expect($registration->amount)->toBe(10000);
    expect($registration->platform_fee_amount)->toBe(1000);
});

test('host can add a ticket type to an event', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/ticket-types", [
        'name' => 'Early Bird',
        'price' => 5000,
        'capacity' => 20,
        'is_active' => true,
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect(EventTicketType::where('event_id', $event->id)->where('name', 'Early Bird')->exists())->toBeTrue();
});
