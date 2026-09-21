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

test('an empty backup alert address falls back instead of breaking every artisan command', function () {
    // A key that is present but blank -- which is how .env.example ships it --
    // makes env() return '' rather than the default, so the fallback chain
    // never fires. Spatie validates the address while booting, so an empty one
    // takes down artisan entirely, including the deploy's migrate --force.
    putenv('BACKUP_ALERT_EMAIL=');
    putenv('SUPERADMIN_EMAIL=');
    $_ENV['BACKUP_ALERT_EMAIL'] = '';
    $_SERVER['BACKUP_ALERT_EMAIL'] = '';
    $_ENV['SUPERADMIN_EMAIL'] = '';
    $_SERVER['SUPERADMIN_EMAIL'] = '';

    try {
        $config = require base_path('config/backup.php');

        expect($config['notifications']['mail']['to'])
            ->toBeString()
            ->not->toBe('');
        expect(filter_var($config['notifications']['mail']['to'], FILTER_VALIDATE_EMAIL))
            ->not->toBeFalse();
    } finally {
        putenv('BACKUP_ALERT_EMAIL');
        putenv('SUPERADMIN_EMAIL');
        unset(
            $_ENV['BACKUP_ALERT_EMAIL'],
            $_SERVER['BACKUP_ALERT_EMAIL'],
            $_ENV['SUPERADMIN_EMAIL'],
            $_SERVER['SUPERADMIN_EMAIL'],
        );
    }
});
