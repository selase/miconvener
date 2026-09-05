<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('creating an event beyond the tenant\'s events-in-flight limit is rejected', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 1],
    ]);

    Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_PUBLISHED]);

    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Second Event',
        'description' => 'Should be rejected',
        'status' => 'published',
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => '123 Main St',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors();
    expect(Event::where('tenant_id', $tenant->id)->where('name', 'Second Event')->exists())->toBeFalse();
});

test('creating an event succeeds again once an existing one is cancelled', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme2', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 1],
    ]);

    Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_CANCELLED]);

    $host = 'acme2.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Allowed Event',
        'description' => 'A cancelled event should not count',
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
    expect(Event::where('tenant_id', $tenant->id)->where('name', 'Allowed Event')->exists())->toBeTrue();
});

test('creating an event succeeds again once an existing one has already ended', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme3', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 1],
    ]);

    // A published event that ended years ago must not permanently occupy a
    // concurrent-event slot — there is no "archive" concept in this app.
    Event::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => Event::STATUS_PUBLISHED,
        'starts_at' => now()->subYears(2),
        'ends_at' => now()->subYears(2)->addHours(3),
    ]);

    $host = 'acme3.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'New Event After Old One Ended',
        'description' => 'An ended event should not count',
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
    expect(Event::where('tenant_id', $tenant->id)->where('name', 'New Event After Old One Ended')->exists())->toBeTrue();
});
