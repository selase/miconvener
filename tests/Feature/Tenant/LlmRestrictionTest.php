<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\ApiKeyService;
use App\Services\Llm\LlmUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'restricted-tenant',
        'status' => \App\Enum\TenantStatusEnum::ACTIVE,
    ]);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // Create a key for testing
    $service = app(ApiKeyService::class);
    $this->apiKey = $service->generate($this->tenant->id, $this->user->id, 'Restricted Key')['key'];

    // Define a test route using the middlewares
    Route::get('/api/llm/test-restrictions', function () {
        return response()->json(['success' => true]);
    })->middleware(['auth_api_key', 'App\Http\Middleware\ThrottleLlmRequests']);
});

test('it enforces tenant-level IP restrictions', function () {
    // Set restriction to an IP that is NOT the test environment IP (127.0.0.1)
    $this->tenant->update(['allowed_ips' => ['8.8.8.8']]);

    $response = $this->withHeaders(['X-Api-Key' => $this->apiKey])
        ->getJson('/api/llm/test-restrictions');

    $response->assertStatus(403)
        ->assertJson(['message' => 'Tenant IP restriction: IP not allowed.']);

    // Allow the test IP
    $this->tenant->update(['allowed_ips' => ['127.0.0.1']]);

    $response = $this->withHeaders(['X-Api-Key' => $this->apiKey])
        ->getJson('/api/llm/test-restrictions');

    $response->assertStatus(200);
});

test('it enforces tenant-level model whitelist', function () {
    $llmService = app(LlmUsageService::class);

    // No whitelist = all allowed
    $this->tenant->update(['llm_models_whitelist' => null]);
    expect($llmService->isModelAllowed($this->tenant->id, 'gpt-4o'))->toBeTrue();

    // Whitelist gpt-4o
    $this->tenant->update(['llm_models_whitelist' => ['gpt-4o']]);
    expect($llmService->isModelAllowed($this->tenant->id, 'gpt-4o'))->toBeTrue();
    expect($llmService->isModelAllowed($this->tenant->id, 'claude-3-opus'))->toBeFalse();
});

test('it enforces tenant-level rate limits', function () {
    // Set a very low limit
    $this->tenant->update(['custom_llm_limit' => 2]);
    $key = "llm_rate_limit:{$this->tenant->id}";
    RateLimiter::clear($key);

    // First request
    $this->withHeaders(['X-Api-Key' => $this->apiKey])
        ->getJson('/api/llm/test-restrictions')
        ->assertStatus(200);

    // Second request
    $this->withHeaders(['X-Api-Key' => $this->apiKey])
        ->getJson('/api/llm/test-restrictions')
        ->assertStatus(200);

    // Third request (blocked)
    $this->withHeaders(['X-Api-Key' => $this->apiKey])
        ->getJson('/api/llm/test-restrictions')
        ->assertStatus(429);
});

test('it uses default rate limit if no custom limit is set', function () {
    // No custom limit set (defaults to 60)
    $this->tenant->update(['custom_llm_limit' => null]);
    $key = "llm_rate_limit:{$this->tenant->id}";
    RateLimiter::clear($key);

    // We hit it 60 times
    for ($i = 0; $i < 60; $i++) {
        $this->withHeaders(['X-Api-Key' => $this->apiKey])
            ->getJson('/api/llm/test-restrictions')
            ->assertStatus(200);
    }

    // 61st should fail
    $this->withHeaders(['X-Api-Key' => $this->apiKey])
        ->getJson('/api/llm/test-restrictions')
        ->assertStatus(429);
});

test('it enforces token quota', function () {
    // Add the ensure_llm_tokens middleware to our test route
    Route::get('/api/llm/test-quota', function () {
        return response()->json(['success' => true]);
    })->middleware(['auth_api_key', 'ensure_llm_tokens']);

    // Set a very low quota (e.g., 10 tokens)
    $this->tenant->features()->updateOrCreate(
        ['feature_key' => 'llm_token_quota'],
        ['enabled' => true, 'meta' => ['type' => 'limit', 'value' => 10]]
    );

    // Initial check - should pass
    $this->withHeaders(['X-Api-Key' => $this->apiKey])
        ->getJson('/api/llm/test-quota')
        ->assertStatus(200);

    // Record usage that exceeds the quota
    $this->tenant->recordUsage('llm_token_quota', 11);

    // Next check - should fail
    $this->withHeaders(['X-Api-Key' => $this->apiKey])
        ->getJson('/api/llm/test-quota')
        ->assertStatus(403)
        ->assertJson(['error_code' => 'QUOTA_EXCEEDED']);
});
