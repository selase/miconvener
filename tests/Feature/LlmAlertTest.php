<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Services\Llm\LlmUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class LlmAlertTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telescope.enabled' => false]);

        $this->tenant = Tenant::create([
            'name' => 'Alert Tenant',
            'slug' => 'alert-tenant',
            'email' => 'alert@example.com',
            'status' => 'active',
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
        ]);
    }

    public function test_it_logs_alert_when_tenant_quota_threshold_reached()
    {
        // 1. Setup feature with limit of 10,000 tokens
        TenantFeature::create([
            'tenant_id' => $this->tenant->id,
            'feature_key' => 'llm_token_quota',
            'enabled' => true,
            'meta' => [
                'type' => 'limit',
                'value' => 10000,
            ],
        ]);

        Log::shouldReceive('warning')->once()->withArgs(function ($message) {
            return str_contains($message, 'reached 80%');
        });

        // 2. Consume 8000 tokens (80%)
        app(LlmUsageService::class)->record(
            $this->tenant->id,
            'openai',
            'gpt-4o',
            4000,
            4000
        );
    }

    public function test_it_logs_alert_when_global_spending_reached()
    {
        config(['llm.global_spending_limit' => 100.00]);
        Cache::flush();

        Log::shouldReceive('critical')->once()->withArgs(function ($message) {
            return str_contains($message, 'reached 80%') && str_contains($message, 'GLOBAL');
        });

        // 3. Record usage that cumulative hits $80
        // gpt-4o cost is $0.005 + $0.015 per 1k. $0.02 per 1k.
        // To hit $80, we need 80 / 0.02 = 4k units of 1k tokens = 4,000,000 tokens.

        app(LlmUsageService::class)->record(
            $this->tenant->id,
            'openai',
            'gpt-4o',
            4000000,
            4000000
        );
    }
}
