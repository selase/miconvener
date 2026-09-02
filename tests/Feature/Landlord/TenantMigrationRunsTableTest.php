<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('tenant_migration_runs table has expected columns', function () {
    expect(Schema::hasTable('tenant_migration_runs'))->toBeTrue();

    $columns = [
        'id', 'tenant_id', 'migration_path', 'batch', 'status',
        'output', 'exception', 'started_at', 'finished_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('tenant_migration_runs', $column))->toBeTrue(
            "Column '$column' is missing from 'tenant_migration_runs' table."
        );
    }
});
