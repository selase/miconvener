<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Llm\LlmUsageService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Create tenant
    $this->tenant = Tenant::create([
        'name' => 'Test Tenant',
        'slug' => 'test-tenant',
        'email' => 'test@example.com',
        'status' => 'active',
        'isolation_mode' => 'shared',
        'db_driver' => 'pgsql',
        'llm_topup_balance' => 0,
    ]);

    // Create user
    $this->user = User::factory()->create();
    $this->tenant->users()->attach($this->user);

    // Mock TenantContext
    $this->mock(TenantContext::class, function ($mock) {
        $mock->shouldReceive('getTenant')->andReturn($this->tenant);
        $mock->shouldReceive('activeTenantId')->andReturn($this->tenant->id);
        $mock->shouldReceive('setTenant')->zeroOrMoreTimes();
    });

    $this->actingAs($this->user);

    // Set up domain for subdomain routing
    Config::set('app.url', 'http://starterkit-v2.test');
    Config::set('session.domain', '.starterkit-v2.test');
});

test('it initiates a checkout session for a token pack', function () {
    $mockGateway = $this->mock(PaymentGateway::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->andReturn('cus_123');
        $mock->shouldReceive('createOneTimeCheckoutSession')->once()->andReturn('https://checkout.stripe.com/pay/test');
    });

    $response = $this->post(route('billing.llm-checkout', ['subdomain' => 'test-tenant']), [
        'pack' => 'starter',
    ]);

    $response->assertRedirect('https://checkout.stripe.com/pay/test');

    $this->tenant->refresh();
    $driver = config('services.payment.default', 'stripe');
    $metaKey = "{$driver}_id";
    expect($this->tenant->meta[$metaKey])->toBe('cus_123');
});

test('it fulfills token purchase and increments balance', function () {
    $mockGateway = $this->mock(PaymentGateway::class, function ($mock) {
        $mock->shouldReceive('verifyTransaction')->andReturn([
            'status' => 'succeeded',
            'metadata' => [
                'type' => 'llm_token_purchase',
                'pack_key' => 'starter',
                'tenant_id' => $this->tenant->id,
            ],
            'transaction_id' => 'tx_abc123',
        ]);
    });

    // Initial balance should be 0
    expect($this->tenant->llm_topup_balance)->toBe(0);

    $response = $this->get(route('billing.callback', [
        'subdomain' => 'test-tenant',
        'session_id' => 'sess_test',
    ]));

    $response->assertRedirect(route('tenant.llm-usage.index', ['subdomain' => 'test-tenant']));

    $this->tenant->refresh();
    // Starter pack is defined in config/llm.php as 500,000 tokens
    expect($this->tenant->llm_topup_balance)->toBe(500000);
});

test('it consumes tokens from top-up balance when quota is exhausted', function () {
    // 1. Set top-up balance to 1000
    $this->tenant->update(['llm_topup_balance' => 1000]);

    // 2. Ensure quota is exhausted (limit = 0)
    App\Models\TenantFeature::create([
        'tenant_id' => $this->tenant->id,
        'feature_key' => 'llm_token_quota',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 0],
    ]);

    $llmService = app(LlmUsageService::class);

    // 3. Verify canConsume is true because of top-up
    expect($llmService->canConsume($this->tenant->id, 500))->toBeTrue();

    // 4. Record usage
    $llmService->record(
        $this->tenant->id,
        'openai',
        'gpt-3.5-turbo',
        200,
        300
    );

    // 5. Verify balance is decremented (1000 - 500 = 500)
    $this->tenant->refresh();
    expect($this->tenant->llm_topup_balance)->toBe(500);
});
