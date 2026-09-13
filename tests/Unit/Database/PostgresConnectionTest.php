<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

test('database driver is pgsql', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});
