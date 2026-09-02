<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\TenantStatsService;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TenantStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure 'landlord' connection to use the same sqlite database as default for testing
        config(['database.connections.landlord' => config('database.connections.sqlite')]);

        // Manually create tenants table to avoid migration path/connection issues in test env
        if (! Schema::connection('landlord')->hasTable('tenants')) {
            Schema::connection('landlord')->create('tenants', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('status')->default('active'); // Matches Enum default or likely values
                $table->string('database')->nullable();
                $table->string('domain')->nullable();
                $table->timestamps();
                // Add minimal columns needed for the model to save
            });
        }

        // Similarly for tenant_user pivot if needed, but let's check if 'users' exists first (it should via RefreshDatabase)
        if (! Schema::connection('landlord')->hasTable('tenant_user')) {
            Schema::connection('landlord')->create('tenant_user', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('tenant_id');
                $table->foreignId('user_id');
                $table->timestamps();
            });
        }

        $this->service = new TenantStatsService();
    }

    public function test_it_can_get_total_active_users_count(): void
    {
        // Arrange
        User::factory()->count(5)->create(['status' => User::STATUS_ACTIVE]);
        User::factory()->count(2)->create(['status' => User::STATUS_INACTIVE]);

        // Act
        $count = $this->service->getTotalActiveUsers();

        // Assert
        $this->assertEquals(5, $count);
    }

    public function test_it_can_get_new_users_trend_for_last_7_days(): void
    {
        // Arrange
        $today = now()->startOfDay();
        User::factory()->create(['created_at' => $today->copy()->subDays(2)]);
        User::factory()->create(['created_at' => $today->copy()->subDays(5)]);
        User::factory()->create(['created_at' => $today->copy()->subDays(8)]); // Should be excluded

        // Act
        $trend = $this->service->getNewUsersTrend(7);

        // Assert
        $this->assertCount(7, $trend['labels']);
        $this->assertCount(7, $trend['data']);
        $this->assertEquals(1, $trend['data'][4]); // 2 days ago (index 4 out of 7 ... 0=6days ago, 6=today.  6-2=4)
        $this->assertEquals(1, $trend['data'][1]); // 5 days ago (6-5=1)
    }

    public function test_it_caches_total_active_users_count(): void
    {
        // Arrange
        \Illuminate\Support\Facades\Cache::shouldReceive('remember')
            ->once()
            ->with('tenant_stats.active_users', 300, Closure::class) // Assuming 5 mins cache
            ->andReturn(10);

        // Act
        $count = $this->service->getTotalActiveUsers();

        // Assert
        $this->assertEquals(10, $count);
    }

    public function test_get_tenant_growth_trend_returns_correct_data()
    {
        // 1. Create tenants created over different days
        \App\Models\Tenant::create(['name' => 'T1', 'slug' => 't1', 'created_at' => now()->subDays(1), 'status' => \App\Enum\TenantStatusEnum::ACTIVE]);
        \App\Models\Tenant::create(['name' => 'T2', 'slug' => 't2', 'created_at' => now()->subDays(1), 'status' => \App\Enum\TenantStatusEnum::ACTIVE]);
        \App\Models\Tenant::create(['name' => 'T3', 'slug' => 't3', 'created_at' => now(), 'status' => \App\Enum\TenantStatusEnum::ACTIVE]);

        // 2. Call service
        $result = $this->service->getTenantGrowthTrend(7);

        // 3. Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals(now()->format('m-d'), end($result['labels'])); // Last label is today
        $this->assertEquals(1, end($result['data'])); // 1 created today
        // Check yesterday (index -2)
        $data = array_values($result['data']);
        $this->assertEquals(2, $data[count($data) - 2]);
    }

    public function test_get_tenant_status_distribution()
    {
        \App\Models\Tenant::create(['name' => 'Active1', 'slug' => 'active1', 'status' => \App\Enum\TenantStatusEnum::ACTIVE]);
        \App\Models\Tenant::create(['name' => 'Active2', 'slug' => 'active2', 'status' => \App\Enum\TenantStatusEnum::ACTIVE]);
        \App\Models\Tenant::create(['name' => 'Banned', 'slug' => 'banned', 'status' => \App\Enum\TenantStatusEnum::BANNED]);

        $result = $this->service->getTenantStatusDistribution();

        $this->assertIsArray($result);
        // We know keys will be status enums or values.
        // Expect keys to be sorted or we sort them for test.
        // Assuming implementation returns array with labels and data
        $this->assertContains('ACTIVE', $result['labels']);
        $this->assertContains('BANNED', $result['labels']);
    }

    public function test_get_top_tenants_by_users()
    {
        $t1 = \App\Models\Tenant::create(['name' => 'Big Corp', 'slug' => 'big-corp']);
        $t2 = \App\Models\Tenant::create(['name' => 'Small Startup', 'slug' => 'small-startup']);

        // Create users and attach
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();

        // Manual pivot insert fallback if Eloquent fails in this complex test env
        \Illuminate\Support\Facades\DB::connection('landlord')->table('tenant_user')->insert([
            ['tenant_id' => $t1->id, 'user_id' => $u1->id],
            ['tenant_id' => $t1->id, 'user_id' => $u2->id],
            ['tenant_id' => $t2->id, 'user_id' => $u3->id],
        ]);

        $result = $this->service->getTopTenantsByUsers(5);

        $this->assertIsArray($result);
        $this->assertEquals(['Big Corp', 'Small Startup'], $result['labels']);
        $this->assertEquals([2, 1], $result['data']);
    }
}
