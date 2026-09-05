<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function idempotencyHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function idempotencySubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('submitting the public registration form twice for the same email does not create a duplicate', function () {
    Mail::fake();
    [$tenant] = idempotencyHost('acme');
    $host = idempotencySubdomain('acme');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $payload = ['full_name' => 'Dup Guest', 'email' => 'dup@example.com'];

    $this->post("http://{$host}/e/{$event->slug}/register", $payload, ['HTTP_HOST' => $host]);
    $second = $this->post("http://{$host}/e/{$event->slug}/register", $payload, ['HTTP_HOST' => $host]);

    expect(EventRegistration::where('email', 'dup@example.com')->count())->toBe(1);

    $registration = EventRegistration::where('email', 'dup@example.com')->firstOrFail();
    $second->assertRedirect(route('public.events.confirmation', [
        'subdomain' => $tenant->slug,
        'event' => $event->slug,
        'registration' => $registration->id,
    ]));
});

test('resubmitting for a pending-payment registration redirects back to checkout instead of duplicating', function () {
    [$tenant] = idempotencyHost('acme');
    $host = idempotencySubdomain('acme');
    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $existing = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $response = $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Paying Guest',
        'email' => 'guest@example.com',
    ], ['HTTP_HOST' => $host]);

    expect(EventRegistration::where('email', 'guest@example.com')->count())->toBe(1);
    $response->assertRedirect(route('public.events.checkout', [
        'subdomain' => $tenant->slug,
        'event' => $event->slug,
        'registration' => $existing->id,
    ]));
});

test('a cancelled registration does not block re-registering with the same email', function () {
    Mail::fake();
    [$tenant] = idempotencyHost('acme');
    $host = idempotencySubdomain('acme');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'again@example.com',
        'status' => EventRegistration::STATUS_CANCELLED,
    ]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Again Guest',
        'email' => 'again@example.com',
    ], ['HTTP_HOST' => $host]);

    expect(EventRegistration::where('email', 'again@example.com')->count())->toBe(2);
});
