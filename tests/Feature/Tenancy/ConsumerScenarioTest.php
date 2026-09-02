<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\Tenant\Post;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ConsumerScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Since we are testing multi-DB with SQLite, we need to ensure local storage exists
        if (! file_exists(storage_path('tenants'))) {
            mkdir(storage_path('tenants'), 0755, true);
        }
    }

    public function test_multi_database_isolation_with_sqlite()
    {
        // 1. Create Tenant A (SQLite)
        $tenantA = Tenant::factory()->create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'isolation_mode' => 'db_per_tenant',
            'db_driver' => 'sqlite',
        ]);
        app(TenantProvisioner::class)->provision($tenantA);

        // 2. Create Tenant B (SQLite)
        $tenantB = Tenant::factory()->create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'isolation_mode' => 'db_per_tenant',
            'db_driver' => 'sqlite',
        ]);
        app(TenantProvisioner::class)->provision($tenantB);

        // 3. Insert data into Tenant A
        app(TenantContext::class)->setTenant($tenantA);
        app(TenantDatabaseManager::class)->configure($tenantA);
        Post::create(['title' => 'Post in A', 'content' => 'Secret context A']);
        $this->assertEquals(1, Post::count());

        // 4. Switch to Tenant B and verify isolation
        app(TenantContext::class)->setTenant($tenantB);
        app(TenantDatabaseManager::class)->configure($tenantB);
        $this->assertEquals(0, Post::count(), 'Tenant B should not see Tenant A posts');
        Post::create(['title' => 'Post in B', 'content' => 'Secret context B']);
        $this->assertEquals(1, Post::count());

        // 5. Back to Tenant A
        app(TenantContext::class)->setTenant($tenantA);
        app(TenantDatabaseManager::class)->configure($tenantA);
        $this->assertEquals(1, Post::count());
        $this->assertEquals('Post in A', Post::first()->title);
    }

    public function test_shared_database_isolation_via_landlord_connection()
    {
        // 1. Create Tenant Shared
        $tenantShared = Tenant::factory()->create([
            'name' => 'Shared Tenant',
            'slug' => 'shared-tenant',
            'isolation_mode' => 'shared',
        ]);
        app(TenantProvisioner::class)->provision($tenantShared);

        // 2. Insert data via tenant connection
        app(TenantContext::class)->setTenant($tenantShared);
        app(TenantDatabaseManager::class)->configureShared();

        // Force sharing the same PDO instance for SQLite memory to work across connections
        if (DB::connection('landlord')->getDriverName() === 'sqlite') {
            DB::purge('tenant');
            Config::set('database.connections.tenant', Config::get('database.connections.landlord'));
            DB::connection('tenant')->setPdo(DB::connection('landlord')->getPdo());
        }

        // In shared mode for SQLite memory, we must ensure migrations are in the same memory space.
        \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--database' => 'landlord',
            '--path' => 'database/migrations/tenant',
            '--realpath' => true,
        ]);

        Post::create(['title' => 'Shared Post', 'content' => 'Shared content']);

        // In shared mode, the table exists in the landlord DB
        $this->assertTrue(DB::connection('landlord')->getSchemaBuilder()->hasTable('posts'));
        $this->assertEquals(1, Post::on('tenant')->count());
    }

    public function test_llm_config_api_key_encryption()
    {
        $tenant = Tenant::factory()->create();

        $config = \App\Models\TenantLlmConfig::create([
            'tenant_id' => $tenant->id,
            'provider' => 'openai',
            'api_key_encrypted' => 'sk-1234567890',
        ]);

        // Verify it is encrypted in the database
        $raw = DB::connection('landlord')->table('tenant_llm_configs')->where('id', $config->id)->value('api_key_encrypted');
        $this->assertNotEquals('sk-1234567890', $raw);

        // Verify we can decrypt it
        $this->assertEquals('sk-1234567890', $config->getDecryptedKey());
    }

    public function test_byo_database_configuration_via_secrets()
    {
        // 1. Setup mock secret in storage
        $secrets = [
            'tenant-db-ref' => [
                'type' => 'db',
                'host' => '1.2.3.4',
                'port' => 3306,
                'database' => 'external_db',
                'username' => 'ext_user',
                'password' => 'ext_pass',
            ],
        ];
        file_put_contents(storage_path('secrets.json'), json_encode($secrets));

        // 2. Create BYO Tenant
        $tenant = Tenant::factory()->create([
            'isolation_mode' => 'byo',
            'db_driver' => 'mysql',
            'db_secret_ref' => 'tenant-db-ref',
        ]);

        // 3. Configure DB
        app(TenantDatabaseManager::class)->configure($tenant);

        // 4. Assert config is correct
        $config = config('database.connections.tenant');
        $this->assertEquals('1.2.3.4', $config['host']);
        $this->assertEquals('external_db', $config['database']);
        $this->assertEquals('ext_user', $config['username']);

        // Clean up
        @unlink(storage_path('secrets.json'));
    }

    public function test_migration_compliance_check_for_kms()
    {
        // 1. Create Tenant with encryption enabled but no KMS key
        $tenant = Tenant::factory()->create([
            'isolation_mode' => 'db_per_tenant',
            'encryption_at_rest' => true,
            'kms_key_ref' => null,
            'status' => \App\Enum\TenantStatusEnum::ACTIVE,
        ]);

        // 2. Run migration command
        $exitCode = \Illuminate\Support\Facades\Artisan::call('tenants:migrate', [
            '--tenant' => $tenant->id,
        ]);

        // 3. Assert it was skipped
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Skipping tenant', $output);
        $this->assertStringContainsString('no KMS key ref', $output);

        // Verify skipped record in database
        $run = DB::connection('landlord')->table('tenant_migration_runs')->where('tenant_id', $tenant->id)->first();
        $this->assertEquals('skipped', $run->status);
    }
}
