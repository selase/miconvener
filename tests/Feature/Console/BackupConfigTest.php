<?php

declare(strict_types=1);

test('spatie backup is configured to target the landlord database connection', function () {
    $databases = config('backup.backup.source.databases');

    expect($databases)->toBeArray()
        ->toContain('landlord')
        ->not->toContain('mysql');

    expect(config('database.connections.landlord'))->toBeArray();
});

test('spatie backup destination disk defaults or respects configured disk', function () {
    $disks = config('backup.backup.destination.disks');

    expect($disks)->toBeArray()
        ->not->toBeEmpty();

    expect(config('filesystems.disks.s3'))->toBeArray();
});
