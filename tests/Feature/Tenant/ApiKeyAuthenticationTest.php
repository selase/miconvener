<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'test-tenant', 'status' => \App\Enum\TenantStatusEnum::ACTIVE]);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // Explicitly register a temporary test route for this test session
    Route::get('/api/test-auth', function () {
        return response()->json([
            'tenant_id' => app(\App\Services\Tenancy\TenantContext::class)->getTenant()?->id,
            'user_id' => auth()->id(),
            'auth_type' => request()->attributes->get('auth_type'),
        ]);
    })->middleware('auth_api_key');
});

test('it authenticates with a valid API key', function () {
    $service = app(ApiKeyService::class);
    $result = $service->generate($this->tenant->id, $this->user->id, 'Test Key');

    $response = $this->withHeaders(['X-Api-Key' => $result['key']])
        ->getJson('/api/test-auth');

    $response->assertStatus(200)
        ->assertJson([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'auth_type' => 'api_key',
        ]);
});

test('it fails without an API key', function () {
    $response = $this->getJson('/api/test-auth');

    $response->assertStatus(401)
        ->assertJson(['message' => 'API Key required.']);
});

test('it fails with an invalid API key', function () {
    $response = $this->withHeaders(['X-Api-Key' => 'sk_this_is_fake_and_not_in_db'])
        ->getJson('/api/test-auth');

    $response->assertStatus(401)
        ->assertJson(['message' => 'Invalid or revoked API Key.']);
});

test('it fails with a revoked API key', function () {
    $service = app(ApiKeyService::class);
    $result = $service->generate($this->tenant->id, $this->user->id, 'Revoked Key');

    $result['model']->update(['revoked_at' => now()]);

    $response = $this->withHeaders(['X-Api-Key' => $result['key']])
        ->getJson('/api/test-auth');

    $response->assertStatus(401);
});

test('it enforces IP restrictions', function () {
    $service = app(ApiKeyService::class);
    $result = $service->generate(
        $this->tenant->id,
        $this->user->id,
        'IP Guarded Key',
        [],
        ['8.8.8.8']
    );

    // Default test runner IP is usually 127.0.0.1
    $response = $this->withHeaders(['X-Api-Key' => $result['key']])
        ->getJson('/api/test-auth');

    $response->assertStatus(403)
        ->assertJson(['message' => 'IP not allowed.']);
});

test('it fails if tenant is suspended', function () {
    $this->tenant->update(['status' => \App\Enum\TenantStatusEnum::DEACTIVATED]);

    $service = app(ApiKeyService::class);
    $result = $service->generate($this->tenant->id, $this->user->id, 'Deactivated Key');

    $response = $this->withHeaders(['X-Api-Key' => $result['key']])
        ->getJson('/api/test-auth');

    $response->assertStatus(403)
        ->assertJson(['message' => 'Tenant account is not active.']);
});
