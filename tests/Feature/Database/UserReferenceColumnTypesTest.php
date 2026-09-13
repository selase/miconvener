<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    refreshTenantDatabases();
});

test('every column that references a user is an integer, like users.id', function () {
    /*
     * users.id is a bigint. Four columns that store one were declared uuid,
     * and Postgres refused every insert into them — staff could not check
     * anyone into a breakout session. SQLite, which the suite runs on, stores
     * anything in any column, so no behavioural test here could notice. The
     * column type survives even on SQLite: uuid() is a text column, id() and
     * foreignId() are integers.
     */
    $userReference = '/^(?:[a-z_]+_by|user_id|owner_id|reviewer_id|assigned_to)$/';
    $problems = [];

    foreach (Schema::connection('landlord')->getTables() as $table) {
        foreach (Schema::connection('landlord')->getColumns($table['name']) as $column) {
            if (! preg_match($userReference, $column['name'])) {
                continue;
            }

            if (! str_contains(mb_strtolower($column['type_name'].' '.$column['type']), 'int')) {
                $problems[] = "{$table['name']}.{$column['name']} is {$column['type']}";
            }
        }
    }

    expect($problems)->toBe([]);
});
