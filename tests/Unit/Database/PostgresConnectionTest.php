<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

test('database driver is pgsql', function () {
    if (env('TEST_TARGET_DB') !== 'pgsql') {
        $this->markTestSkipped('Skipping Postgres check when not targeting Postgres.');
    }

    expect(DB::connection()->getDriverName())->toBe('pgsql');
});
