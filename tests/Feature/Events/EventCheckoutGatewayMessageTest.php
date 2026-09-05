<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function gatewayMessageHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function gatewayMessageSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('checkout shows a generic message when no payment gateway is configured at all', function () {
    [$tenant] = gatewayMessageHost('acme');
    $host = gatewayMessageSubdomain('acme');

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

test('checkout shows a Paystack-specific message when only a non-Paystack gateway is configured', function () {
    [$tenant] = gatewayMessageHost('acme');
    $host = gatewayMessageSubdomain('acme');

    // Explicit own_gateway: this tenant has configured its own (non-Paystack) gateway,
    // which pre-existing tenants (Task 1's column default) would not have set otherwise.
    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'stripe',
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 5000,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/checkout/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas(
        'error',
        'This event currently only accepts payments via Paystack, which is not configured for this organizer.'
    );
});
