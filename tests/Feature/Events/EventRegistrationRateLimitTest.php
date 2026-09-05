<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function rateLimitHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function rateLimitSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('the public registration endpoint is throttled per IP after 10 requests in a minute', function () {
    Mail::fake();
    [$tenant] = rateLimitHost('acme');
    $host = rateLimitSubdomain('acme');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    for ($i = 1; $i <= 10; $i++) {
        $response = $this->post("http://{$host}/e/{$event->slug}/register", [
            'full_name' => "Guest {$i}",
            'email' => "guest{$i}@example.com",
        ], ['HTTP_HOST' => $host]);

        $response->assertRedirect();
    }

    $eleventh = $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Guest 11',
        'email' => 'guest11@example.com',
    ], ['HTTP_HOST' => $host]);

    $eleventh->assertStatus(429);
});
