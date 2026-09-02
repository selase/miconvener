<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

test('landlord database connection is defined', function () {
    $config = Config::get('database.connections.landlord');
    expect($config)->not->toBeNull()
        ->and($config['driver'])->toBeIn(['mysql', 'pgsql', 'sqlite']);
});

test('tenant database connection is defined', function () {
    $config = Config::get('database.connections.tenant');
    expect($config)->not->toBeNull()
        ->and($config['driver'])->toBeNull(); // Should be null or placeholder
});

test('default database connection is landlord', function () {
    expect(Config::get('database.default'))->toBe('landlord');
});
