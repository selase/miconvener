<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function publishingHost(array $tenantAttributes = []): array
{
    $tenant = Tenant::factory()->create([...['slug' => 'acme', 'isolation_mode' => 'shared'], ...$tenantAttributes]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function publishingPayload(Event $event, array $overrides = []): array
{
    return [
        ...[
            'name' => $event->name,
            'status' => Event::STATUS_PUBLISHED,
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addDay()->toDateTimeString(),
            'timezone' => 'Africa/Accra',
            'location_type' => Event::LOCATION_VIRTUAL,
            'virtual_link' => 'https://meet.example.com/acme',
            'ticket_price' => 10_000,
            'currency' => 'GHS',
        ],
        ...$overrides,
    ];
}

test('a platform_default tenant with no payment gateway row can publish a paid event', function () {
    [$tenant, $user] = publishingHost();
    expect($tenant->isPlatformDefaultSettlement())->toBeTrue();
    expect($tenant->hasActivePaymentGateway())->toBeFalse();

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->putJson("http://{$host}/events/{$event->id}", publishingPayload($event), ['HTTP_HOST' => $host])
        ->assertOk();

    expect($event->fresh())->status->toBe(Event::STATUS_PUBLISHED)->ticket_price->toBe(10_000);
});

test('a platform_default tenant can add a paid ticket type to a published event', function () {
    [$tenant, $user] = publishingHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/ticket-types", [
        'name' => 'Early Bird',
        'price' => 5_000,
        'is_active' => true,
    ], ['HTTP_HOST' => $host])->assertOk();

    expect(EventTicketType::where('event_id', $event->id)->where('name', 'Early Bird')->exists())->toBeTrue();
});

test('an own_gateway tenant with no configured gateway still cannot publish a paid event', function () {
    [$tenant, $user] = publishingHost(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    expect($tenant->canAcceptPayments())->toBeFalse();

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->putJson("http://{$host}/events/{$event->id}", publishingPayload($event), ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($event->fresh()->status)->not->toBe(Event::STATUS_PUBLISHED);
});

test('an own_gateway tenant with no configured gateway still cannot add a paid ticket type', function () {
    [$tenant, $user] = publishingHost(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/ticket-types", [
        'name' => 'Early Bird',
        'price' => 5_000,
        'is_active' => true,
    ], ['HTTP_HOST' => $host])->assertStatus(422);

    expect(EventTicketType::where('event_id', $event->id)->exists())->toBeFalse();
});
