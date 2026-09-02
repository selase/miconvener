<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Contracts\Secrets\SecretsProvider;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;

class TenantDatabaseManager
{
    public function __construct(private readonly SecretsProvider $secrets) {}

    public function configure(Tenant $tenant): void
    {
        if ($tenant->isolation_mode === 'shared') {
            $this->configureShared();

            return;
        }

        if (empty($tenant->db_secret_ref)) {
            // Fallback to landlord credentials but override the database name
            $landlord = Config::get('database.connections.landlord');
            $creds = [
                'host' => $landlord['host'] ?? '127.0.0.1',
                'port' => $landlord['port'] ?? 5432,
                'database' => $tenant->meta['database'] ?? $landlord['database'],
                'username' => $landlord['username'] ?? 'root',
                'password' => $landlord['password'] ?? '',
            ];
        } else {
            $creds = $this->secrets->getSecret((string) $tenant->db_secret_ref);
        }

        Config::set('database.connections.tenant', [
            'driver' => $tenant->db_driver,
            'host' => $creds['host'],
            'port' => $creds['port'],
            'database' => $creds['database'],
            'username' => $creds['username'],
            'password' => $creds['password'],
            'charset' => $tenant->db_driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $tenant->db_driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        app('db')->purge('tenant');
    }

    public function configureShared(): void
    {
        $landlordConfig = Config::get('database.connections.landlord');
        Config::set('database.connections.tenant', $landlordConfig);

        // For SQLite :memory:, share the PDO to avoid destroying the in-memory database.
        // Each purge creates a new PDO, which for :memory: means a new empty database.
        if (($landlordConfig['driver'] ?? '') === 'sqlite' && ($landlordConfig['database'] ?? '') === ':memory:') {
            app('db')->connection('tenant')->setPdo(
                app('db')->connection('landlord')->getPdo()
            );
        } else {
            app('db')->purge('tenant');
        }
    }

    public function purge(): void
    {
        app('db')->purge('tenant');
    }
}
