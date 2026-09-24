<?php

declare(strict_types=1);

use App\Http\Middleware\GuardAttendeePortalHost;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * The portal's whole tenancy boundary is one piece of global middleware. If a
 * platform route were ever added outside its reach, every check inside the
 * controllers would still pass while the request carried a tenant the caller
 * chose. So this asserts the boundary by exercising it, route by route, rather
 * than by trusting that the middleware is registered somewhere.
 */
test('the host guard is registered globally, before routing', function (): void {
    $global = (fn () => $this->middleware)->call(app(App\Http\Kernel::class));

    expect($global)->toContain(GuardAttendeePortalHost::class);

    $position = array_search(GuardAttendeePortalHost::class, $global, true);
    $trustProxies = array_search(App\Http\Middleware\TrustProxies::class, $global, true);

    // The host must be proxy-resolved before it is judged.
    expect($position)->toBeGreaterThan($trustProxies);
});

test('every platform attendee route refuses a host that is not the platform', function (): void {
    $portalRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'attendee.my'));

    expect($portalRoutes)->not->toBeEmpty();

    $host = 'evil.example.com';
    $checked = 0;

    foreach ($portalRoutes as $route) {
        $method = collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD');
        $uri = '/'.mb_ltrim(preg_replace('/\{[^}]+\}/', '01a0d284-92f8-73b9-a4f3-cda13558d3d9', $route->uri()) ?? '', '/');

        $response = $this->call($method, "http://{$host}{$uri}", [], [], [], ['HTTP_HOST' => $host]);

        expect($response->status())
            ->toBe(404, "{$method} {$uri} answered {$response->status()} on a host that names no organiser");

        $checked++;
    }

    expect($checked)->toBeGreaterThan(20);
});

test('the obsolete tenant-scoped identity routes are gone', function (): void {
    $legacyTenantRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'public.my'));

    expect($legacyTenantRoutes)->toBeEmpty();
});
