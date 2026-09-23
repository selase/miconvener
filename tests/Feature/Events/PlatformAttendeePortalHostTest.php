<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
});

function baseHost(): string
{
    return mb_ltrim((string) config('session.domain'), '.');
}

test('the platform portal opens on the base domain with no tenant context', function (): void {
    $host = baseHost();

    $response = $this->get("http://{$host}/my", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->where('organiser', null)
            ->where('verifiedEmail', null)
        );

    expect(app(TenantContext::class)->getTenant())->toBeNull();
});

test('dns-normalized host spelling opens the platform portal', function (string $spelling): void {
    $host = match ($spelling) {
        'uppercase' => mb_strtoupper(baseHost()),
        'terminal-dot' => baseHost().'.',
    };

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Public/Events/AttendeePortal/MyPortal'));
})->with([
    'uppercase' => 'uppercase',
    'one terminal dot' => 'terminal-dot',
]);

test('www host redirects to the central platform portal', function (): void {
    $host = 'www.'.baseHost();

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertRedirect('http://'.baseHost().'/my');
});

test('www host preserves query parameters when redirecting', function (): void {
    $host = 'www.'.baseHost();

    $this->get("http://{$host}/my?source=newsletter", ['HTTP_HOST' => $host])
        ->assertRedirect('http://'.baseHost().'/my?source=newsletter');
});

test('tenant subdomain redirects to the central portal with organiser context', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme-events', 'isolation_mode' => 'shared']);
    $host = 'acme-events.'.baseHost();

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertRedirect('http://'.baseHost().'/my?organiser=acme-events');
});

test('tenant subdomain preserves existing query parameters when redirecting', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme-events', 'isolation_mode' => 'shared']);
    $host = 'acme-events.'.baseHost();

    $this->get("http://{$host}/my?tab=schedule", ['HTTP_HOST' => $host])
        ->assertRedirect('http://'.baseHost().'/my?tab=schedule&organiser=acme-events');
});

test('the portal displays organiser presentation context when provided via query', function (): void {
    $tenant = Tenant::factory()->create([
        'name' => 'Acme Conferences',
        'slug' => 'acme-conf',
        'isolation_mode' => 'shared',
    ]);
    $host = baseHost();

    $this->get("http://{$host}/my?organiser=acme-conf", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('organiser.name', 'Acme Conferences')
            ->where('organiser.slug', 'acme-conf')
        );

    // Presentation context must never set TenantContext!
    expect(app(TenantContext::class)->getTenant())->toBeNull();
});

test('session active_tenant_id does not contaminate the platform portal', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'session-victim', 'isolation_mode' => 'shared']);
    $host = baseHost();

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('organiser', null));

    expect(app(TenantContext::class)->getTenant())->toBeNull();
});

test('X-Tenant header does not contaminate the platform portal', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'header-victim', 'isolation_mode' => 'shared']);
    $host = baseHost();

    $this->get("http://{$host}/my", [
        'HTTP_HOST' => $host,
        'HTTP_X_TENANT' => $tenant->slug,
    ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('organiser', null));

    expect(app(TenantContext::class)->getTenant())->toBeNull();
});

test('a user belonging to a banned tenant can still access the platform portal', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'banned-tenant',
        'status' => TenantStatusEnum::BANNED,
        'isolation_mode' => 'shared',
    ]);
    $user = User::factory()->create();
    $host = baseHost();

    $this->actingAs($user)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get("http://{$host}/my", [
            'HTTP_HOST' => $host,
            'HTTP_X_TENANT' => $tenant->slug,
        ])
        ->assertOk();

    expect(app(TenantContext::class)->getTenant())->toBeNull();
});

test('nested, suffix-confused, and external custom domain hosts return 404 for /my', function (string $host): void {
    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertNotFound();
})->with([
    'nested label' => fn () => 'acme.extra.'.baseHost(),
    'suffix confusion' => fn () => 'acme.'.baseHost().'.example.com',
    'external custom domain' => fn () => 'events.custom.test',
]);

test('non-GET requests to non-platform hosts return 404', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'post-victim', 'isolation_mode' => 'shared']);
    $tenantHost = 'post-victim.'.baseHost();
    $wwwHost = 'www.'.baseHost();

    $this->post("http://{$tenantHost}/my", [], ['HTTP_HOST' => $tenantHost])
        ->assertNotFound();

    $this->post("http://{$wwwHost}/my", [], ['HTTP_HOST' => $wwwHost])
        ->assertNotFound();
});
