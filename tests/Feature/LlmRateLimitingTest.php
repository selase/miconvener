<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantApiKey;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class LlmRateLimitingTest extends TestCase
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
        try {
            $this->tenant = Tenant::create([
                'name' => 'Test Tenant',
                'slug' => 'test-tenant',
                'email' => 'test@example.com',
                'status' => 'active',
                'isolation_mode' => 'shared',
                'db_driver' => 'pgsql',
            ]);
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage());
            throw $e;
        }

        // Create an API key for the tenant
        $this->apiKey = TenantApiKey::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Key',
            'key_hash' => hash('sha256', 'test-secret-123'),
            'key_hint' => 'test-sec',
        ]);

        // TenantContext is set for real by the AuthenticateWithApiKey middleware once
        // it resolves the tenant from the X-API-KEY header on each request below, so
        // it does not need to be mocked (and cannot be, since it is final).

        // Enable LLM Quota feature so EnsuresTenantHasLlmTokens middleware doesn't block
        \App\Models\TenantFeature::create([
            'tenant_id' => $this->tenant->id,
            'feature_key' => 'llm_token_quota',
            'enabled' => true,
            'meta' => [
                'type' => 'limit',
                'value' => 1000,
            ],
        ]);
    }

    public function test_it_throttles_llm_requests()
    {
        RateLimiter::clear("llm_rate_limit:{$this->tenant->id}");

        // First 60 requests should succeed (based on middleware default)
        for ($i = 0; $i < 60; $i++) {
            $response = $this->withHeaders([
                'X-API-KEY' => 'test-secret-123',
            ])->getJson('/api/llm/test-throttle');

            $response->assertStatus(200);
            $response->assertHeader('X-RateLimit-Limit', 60);
            $response->assertHeader('X-RateLimit-Remaining', 59 - $i);
        }

        // 61st request should be throttled
        $response = $this->withHeaders([
            'X-API-KEY' => 'test-secret-123',
        ])->getJson('/api/llm/test-throttle');

        $response->assertStatus(429);
        $response->assertJson([
            'message' => 'Too Many Attempts.',
        ]);
        $response->assertHeader('X-RateLimit-Remaining', 0);
    }
}
