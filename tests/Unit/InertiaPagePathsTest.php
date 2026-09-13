<?php

declare(strict_types=1);

test('every Inertia page path exists with exactly the case configured', function () {
    /*
     * Inertia's default page path is resources/js/pages; this app's folder is
     * resources/js/Pages. macOS matched them anyway, Linux did not, and every
     * page-component assertion failed in CI while passing locally. file_exists
     * cannot see the difference on a case-insensitive filesystem, so each path
     * segment is compared against the real directory listing.
     */
    $problems = [];

    foreach ((array) config('inertia.pages.paths') as $path) {
        $relative = mb_ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
        $current = base_path();

        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if (! in_array($segment, scandir($current), true)) {
                $problems[] = "{$path}: no directory named exactly `{$segment}` in {$current}";

                break;
            }

            $current .= DIRECTORY_SEPARATOR.$segment;
        }
    }

    expect($problems)->toBe([]);
});
