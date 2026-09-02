<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Contracts\Secrets\SecretsProvider;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;

final readonly class TenantStorageManager
{
    public function __construct(private SecretsProvider $secrets) {}

    public function configure(Tenant $tenant): void
    {
        if ($tenant->s3_mode === 'byo') {
            $this->configureByo($tenant);
        } else {
            $this->configureShared($tenant);
        }

        // Purge the tenant disk to ensure it uses the new config
        app('filesystem')->forgetDisk('tenant');
    }

    private function configureShared(Tenant $tenant): void
    {
        $driver = Config::get('filesystems.default', 'local');
        $baseConfig = Config::get("filesystems.disks.{$driver}");

        if (! $baseConfig) {
            $baseConfig = Config::get('filesystems.disks.local');
            $driver = 'local';
        }

        $baseRoot = $baseConfig['root'] ?? '';

        $newRoot = $baseRoot
            ? mb_rtrim((string) $baseRoot, '/')."/tenants/{$tenant->id}"
            : "tenants/{$tenant->id}";

        Config::set('filesystems.disks.tenant', array_merge((array) $baseConfig, [
            'driver' => $baseConfig['driver'] ?? $driver,
            'root' => $newRoot,
        ]));
    }

    private function configureByo(Tenant $tenant): void
    {
        $creds = $this->secrets->getSecret((string) $tenant->s3_secret_ref);

        Config::set('filesystems.disks.tenant', [
            'driver' => 's3',
            'key' => $creds['key'],
            'secret' => $creds['secret'],
            'region' => $creds['region'],
            'bucket' => $creds['bucket'],
            'throw' => false,
        ]);
    }
}
