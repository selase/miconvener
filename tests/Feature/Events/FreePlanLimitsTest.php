<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventTicketType;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\TenantFeatureUsage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * The free tier costs the platform real money -- object storage for every
 * uploaded file, a queued email per registration. These tests exist because the
 * catalogue advertised ceilings the code never enforced: the registration limit
 * was recorded and then ignored, so "100 registrations" was decoration.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function freePlanTenant(string $slug): Tenant
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'event_registrations',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 50],
    ]);

    foreach (['paid_tickets', 'event_materials'] as $key) {
        TenantFeature::create([
            'tenant_id' => $tenant->id,
            'feature_key' => $key,
            'enabled' => false,
            'meta' => ['type' => 'boolean', 'value' => false],
        ]);
    }

    return $tenant;
}

function organizerFor(Tenant $tenant): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return $user;
}

function tenantHost(Tenant $tenant): string
{
    return $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
}

test('a guest cannot register once the tenant has reached its registration ceiling', function () {
    Mail::fake();

    $tenant = freePlanTenant('free-ceiling');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $host = tenantHost($tenant);

    TenantFeatureUsage::create([
        'tenant_id' => $tenant->id,
        'feature_slug' => 'event_registrations',
        'period_start' => null,
        'period_end' => null,
        'used_count' => 50,
    ]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host]);

    expect($event->registrations()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('a guest can still register one below the ceiling', function () {
    Mail::fake();

    $tenant = freePlanTenant('free-headroom');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $host = tenantHost($tenant);

    TenantFeatureUsage::create([
        'tenant_id' => $tenant->id,
        'feature_slug' => 'event_registrations',
        'period_start' => null,
        'period_end' => null,
        'used_count' => 49,
    ]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host]);

    expect($event->registrations()->count())->toBe(1);
});

test('a tenant with no configured ceiling is not blocked', function () {
    Mail::fake();

    // Absence of a limit row means the plan's features were never synced, not
    // that registration was withdrawn. Failing closed here would take every
    // legacy tenant's public event offline.
    $tenant = Tenant::factory()->create(['slug' => 'no-ceiling', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $host = tenantHost($tenant);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host]);

    expect($event->registrations()->count())->toBe(1);
});

test('a free-plan organizer cannot create a paid ticket type', function () {
    $tenant = freePlanTenant('free-tickets');
    $user = organizerFor($tenant);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = tenantHost($tenant);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/ticket-types", [
            'name' => 'Early Bird',
            'price' => 5000,
            'quantity' => 100,
            'is_active' => true,
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJson(['message' => 'Your plan runs free events only. Upgrade to sell paid tickets.']);

    expect(EventTicketType::where('event_id', $event->id)->count())->toBe(0);
});

test('a free-plan organizer can still create a free ticket type', function () {
    $tenant = freePlanTenant('free-free-tickets');
    $user = organizerFor($tenant);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = tenantHost($tenant);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/ticket-types", [
            'name' => 'General Admission',
            'price' => 0,
            'quantity' => 100,
            'is_active' => true,
        ], ['HTTP_HOST' => $host])
        ->assertSuccessful();

    expect(EventTicketType::where('event_id', $event->id)->count())->toBe(1);
});

test('a free-plan organizer cannot give an event a ticket price', function () {
    $tenant = freePlanTenant('free-event-price');
    $user = organizerFor($tenant);
    $host = tenantHost($tenant);

    $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Paid Summit',
        'description' => 'Should be rejected',
        'status' => 'draft',
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => '123 Main St',
        'ticket_price' => 15000,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host])->assertSessionHasErrors('ticket_price');

    expect(Event::where('tenant_id', $tenant->id)->where('name', 'Paid Summit')->exists())->toBeFalse();
});

test('a free-plan organizer cannot upload speaker materials', function () {
    Storage::fake('public');

    $tenant = freePlanTenant('free-materials');
    $user = organizerFor($tenant);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = tenantHost($tenant);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/materials", [
            'title' => 'Slides',
            'download_limit' => 10,
            'file' => UploadedFile::fake()->create('slides.pdf', 512, 'application/pdf'),
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect(EventMaterial::where('event_id', $event->id)->count())->toBe(0);
    Storage::disk('public')->assertDirectoryEmpty('/');
});

test('a plan that permits materials still accepts uploads', function () {
    Storage::fake('public');

    $tenant = freePlanTenant('paid-materials');
    $tenant->features()->where('feature_key', 'event_materials')->update(['enabled' => true]);

    $user = organizerFor($tenant);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = tenantHost($tenant);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/materials", [
            'title' => 'Slides',
            'download_limit' => 10,
            'file' => UploadedFile::fake()->create('slides.pdf', 512, 'application/pdf'),
        ], ['HTTP_HOST' => $host])
        ->assertSuccessful();

    expect(EventMaterial::where('event_id', $event->id)->count())->toBe(1);
});
