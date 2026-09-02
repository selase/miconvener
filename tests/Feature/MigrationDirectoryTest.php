<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('landlord migration directory exists', function () {
    $this->assertTrue(File::isDirectory(database_path('migrations/landlord')));
});

test('tenant migration directory exists', function () {
    $this->assertTrue(File::isDirectory(database_path('migrations/tenant')));
});
