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

test('tenant_switch_audit table has expected columns', function () {
    expect(Schema::hasTable('tenant_switch_audit'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'from_tenant_id', 'to_tenant_id', 'ip', 'user_agent', 'created_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('tenant_switch_audit', $column))->toBeTrue(
            "Column '$column' is missing from 'tenant_switch_audit' table."
        );
    }
});
