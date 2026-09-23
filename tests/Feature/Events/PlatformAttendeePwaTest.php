<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function pwaPlatformHost(): string
{
    return app(TenantHostMatcher::class)->baseDomain();
}

test('manifest endpoint returns valid PWA web app manifest scoped to /my/', function (): void {
    $host = pwaPlatformHost();

    $response = $this->get("http://{$host}/my/manifest.json", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJson([
            'name' => 'MiConvener Attendee Portal',
            'short_name' => 'My Events',
            'start_url' => '/my',
            'scope' => '/my/',
            'display' => 'standalone',
            'theme_color' => '#4f46e5',
        ]);

    $data = $response->json();
    expect($data['icons'])->toBeArray()
        ->and(count($data['icons']))->toBeGreaterThanOrEqual(2);
});

test('service worker file exists and contains scoped attendee cache logic', function (): void {
    $swPath = public_path('attendee-sw.js');
    expect(file_exists($swPath))->toBeTrue();

    $content = file_get_contents($swPath);
    expect($content)
        ->toContain('miconvener-attendee-')
        ->toContain('/my/manifest.json')
        ->toContain("startsWith('/my')");
});
