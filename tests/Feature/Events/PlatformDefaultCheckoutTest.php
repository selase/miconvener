<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

function platformDefaultHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function platformDefaultSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('a platform_default tenant with no TenantPaymentGateway row still completes checkout via the platform Paystack account', function () {
    Http::fake([
        'api.paystack.co/customer/*' => Http::response(['data' => ['email' => 'guest@example.com']]),
        'api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_platform_1']]),
        'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/platform123']]),
    ]);

    [$tenant] = platformDefaultHost('acme');
    $host = platformDefaultSubdomain('acme');
    // No TenantPaymentGateway row is created for this tenant — settlement_mode defaults to platform_default.

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/checkout/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertRedirect('https://checkout.paystack.com/platform123');
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/transaction/initialize')
        && ($request['metadata']['tenant_id'] ?? null) === $tenant->id);
});

test('an own_gateway tenant with no TenantPaymentGateway row gets the existing not-accepting-payments message, unaffected by the platform default', function () {
    [$tenant] = platformDefaultHost('acme');
    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $host = platformDefaultSubdomain('acme');

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 5000,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/checkout/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'This event is not currently accepting payments.');
});
