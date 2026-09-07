<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Contracts\TenantMigratorContract;
use Illuminate\Support\Facades\Artisan;

final class TenantMigrator implements TenantMigratorContract
{
    /**
     * @return array{exitCode: int, output: string}
     */
    public function migrate(string $database, string $path, bool $force = false): array
    {
        $exitCode = Artisan::call('migrate', [
            '--database' => $database,
            '--path' => $path,
            '--force' => $force,
        ]);

        return [
            'exitCode' => $exitCode,
            'output' => Artisan::output(),
        ];
    }
}
