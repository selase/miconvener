<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    //
});

test('tenants table has expected columns', function () {
    expect(Schema::hasTable('tenants'))->toBeTrue();

    $columns = [
        'id', 'name', 'slug', 'status', 'isolation_mode',
        'db_driver', 'db_secret_ref', 's3_mode', 's3_secret_ref',
        'encryption_at_rest', 'kms_key_ref', 'meta',
        'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('tenants', $column))->toBeTrue(
            "Column '$column' is missing from 'tenants' table."
        );
    }
});
