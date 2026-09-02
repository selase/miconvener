<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantApiKey;
use App\Models\TenantFeature;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LlmQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Telescope
        config(['telescope.enabled' => false]);

        // Create a landlord tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'email' => 'test@example.com',
            'status' => 'active',
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
        ]);

        // Create an API key for the tenant
        $this->apiKey = TenantApiKey::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Key',
            'key_hash' => hash('sha256', 'test-secret-123'),
            'key_hint' => 'test-sec',
        ]);

        // Mock TenantContext
        $this->mock(TenantContext::class, function ($mock) {
            $mock->shouldReceive('getTenant')->andReturn($this->tenant)->byDefault();
            $mock->shouldReceive('activeTenantId')->andReturn($this->tenant->id)->byDefault();
            $mock->shouldReceive('setTenant')->andReturn(null);
        });
    }

    public function test_it_blocks_request_when_quota_exceeded()
    {
        // 1. Setup feature with limit of 1000 tokens
        TenantFeature::create([
            'tenant_id' => $this->tenant->id,
            'feature_key' => 'llm_token_quota',
            'enabled' => true,
            'meta' => [
                'type' => 'limit',
                'value' => 1000,
            ],
        ]);

        // 2. Request should succeed initially
        $response = $this->withHeaders([
            'X-API-KEY' => 'test-secret-123',
        ])->getJson('/api/llm/test-quota');

        $response->assertStatus(200);

        // 3. Manually consume 1001 tokens
        $this->tenant->recordUsage('llm_token_quota', 1001);

        // 4. Next request should fail
        $response = $this->withHeaders([
            'X-API-KEY' => 'test-secret-123',
        ])->getJson('/api/llm/test-quota');

        $response->assertStatus(403);
        $response->assertJson([
            'error_code' => 'QUOTA_EXCEEDED',
        ]);
    }

    public function test_it_allows_request_when_using_byok_even_if_platform_quota_exceeded()
    {
        // 1. Setup feature with limit of 0 tokens
        TenantFeature::create([
            'tenant_id' => $this->tenant->id,
            'feature_key' => 'llm_token_quota',
            'enabled' => true,
            'meta' => [
                'type' => 'limit',
                'value' => 0,
            ],
        ]);

        // 2. Enable llm_byok feature
        TenantFeature::create([
            'tenant_id' => $this->tenant->id,
            'feature_key' => 'llm_byok',
            'enabled' => true,
        ]);

        // 3. Add a mock LLM config to simulate BYOK
        \App\Models\TenantLlmConfig::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'openai',
            'api_key_encrypted' => 'sk-test-123',
            'is_active' => true,
        ]);

        // 4. Request should succeed despite 0 tokens platform quota
        $response = $this->withHeaders([
            'X-API-KEY' => 'test-secret-123',
        ])->getJson('/api/llm/test-quota');

        $response->assertStatus(200);
    }
}
