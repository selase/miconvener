<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    //
});

test('tenant_user table has expected columns', function () {
    expect(Schema::hasTable('tenant_user'))->toBeTrue();

    $columns = [
        'tenant_id', 'user_id', 'role_hint', 'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('tenant_user', $column))->toBeTrue(
            "Column '$column' is missing from 'tenant_user' table."
        );
    }
});
