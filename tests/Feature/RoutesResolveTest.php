<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * A route pointing at a method that does not exist only fails when someone
 * visits it. Five did, from resource routes declared for actions never written.
 */
test('every route points at a controller method that exists', function (): void {
    $broken = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->getActionName())
        ->filter(fn (string $action): bool => str_contains($action, '@'))
        ->reject(function (string $action): bool {
            [$class, $method] = explode('@', $action);

            return class_exists($class) && method_exists($class, $method);
        })
        ->unique()
        ->values()
        ->all();

    expect($broken)->toBe([]);
});

test('every Inertia page a controller renders exists', function (): void {
    $missing = collect(File::allFiles(app_path()))
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all("/Inertia::render\\('([^']+)'/", (string) file_get_contents($file->getPathname()), $matches);

            return $matches[1];
        })
        ->unique()
        ->reject(fn (string $page): bool => collect(['jsx', 'tsx', 'js'])->contains(fn (string $ext): bool => file_exists(resource_path("js/Pages/{$page}.{$ext}"))))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
