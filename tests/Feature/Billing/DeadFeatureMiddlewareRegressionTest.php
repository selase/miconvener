<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('the dead feature and feature_limit middleware aliases are not attached to any route', function () {
    $offendingRoutes = collect(Route::getRoutes())
        ->filter(function ($route): bool {
            $middleware = $route->gatherMiddleware();

            return collect($middleware)->contains(fn (string $m): bool => str_starts_with($m, 'feature:') || str_starts_with($m, 'feature_limit:') || $m === 'feature' || $m === 'feature_limit');
        });

    expect($offendingRoutes)->toHaveCount(0);
});
