<?php

declare(strict_types=1);

use Tests\Support\IsolatedPostgresDatabase;

it('recognises dedicated serial and native CI worker databases without accepting production names', function (string $database, bool $permitted): void {
    expect(IsolatedPostgresDatabase::permits($database))->toBe($permitted);
})->with([
    ['testing', true], ['testing_test_1', true], ['testing_test_12', true],
    ['miconvener_testing', true], ['miconvener_multi_org_ci_testing_test_1', true],
    ['miconvener', false], ['production', false], ['production_testing_backup', false],
    ['testing_test_0', false], ['testing_test_x', false], ['testing_test_1_extra', false],
    ['testing_test_1'."\n", false], ['', false],
]);
