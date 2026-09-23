<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAttendeeVerified;
use App\Http\Middleware\EnsureTenantFromHost;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

test('every attendee identity route enforces the tenant host boundary', function (): void {
    $portalRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'public.my'));

    expect($portalRoutes)->not->toBeEmpty();

    $portalRoutes->each(function (LaravelRoute $route): void {
        $middleware = app('router')->gatherRouteMiddleware($route);

        expect($middleware)->toContain(EnsureTenantFromHost::class);

        if (in_array(EnsureAttendeeVerified::class, $middleware, true)) {
            $hostGuardPosition = array_search(EnsureTenantFromHost::class, $middleware, true);
            $verificationPosition = array_search(EnsureAttendeeVerified::class, $middleware, true);

            expect($hostGuardPosition)->toBeInt()
                ->and($verificationPosition)->toBeInt();
            expect($hostGuardPosition)->toBeLessThan($verificationPosition);
        }
    });
});
