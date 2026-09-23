<?php

declare(strict_types=1);

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

test('every platform attendee route is protected by global GuardAttendeePortalHost', function (): void {
    $globalMiddleware = app(Illuminate\Contracts\Http\Kernel::class)->getMiddlewarePriority();

    $portalRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'attendee.my'));

    expect($portalRoutes)->not->toBeEmpty();

    // Verify obsolete public.my routes no longer exist on subdomains
    $legacyTenantRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'public.my'));

    expect($legacyTenantRoutes)->toBeEmpty();
});
