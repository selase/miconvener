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

test('tenant_features table has expected columns', function () {
    expect(Schema::hasTable('tenant_features'))->toBeTrue();

    $columns = [
        'id', 'tenant_id', 'feature_key', 'enabled', 'meta', 'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('tenant_features', $column))->toBeTrue(
            "Column '$column' is missing from 'tenant_features' table."
        );
    }
});
