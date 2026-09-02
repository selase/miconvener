<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

test('tenant filesystem disk is defined', function () {
    $config = Config::get('filesystems.disks.tenant');

    expect($config)->not->toBeNull()
        ->and($config['driver'])->toBe('s3');
});
