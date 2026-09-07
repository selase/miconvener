<?php

declare(strict_types=1);

namespace App\Contracts;

interface TenantMigratorContract
{
    /**
     * Run migrations against a tenant database connection.
     *
     * @return array{exitCode: int, output: string}
     */
    public function migrate(string $database, string $path, bool $force = false): array;
}
