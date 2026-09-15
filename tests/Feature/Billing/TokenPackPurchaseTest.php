<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Models\User;

/**
 * The live Paystack account settles in GHS only, and the console had no way to
 * buy a pack at all: the purchase form lived in a Blade view nothing rendered.
 */
test('every token pack is priced in cedis', function (): void {
    expect(collect(config('llm.token_packs'))->map(fn (array $pack): string => $pack['currency'].' '.$pack['price'])->all())
        ->toBe(['starter' => 'GHS 60', 'standard' => 'GHS 180', 'enterprise' => 'GHS 600']);
});

test('buying a pack charges its cedi price and sends the console to Paystack', function (): void {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['meta' => ['paystack_id' => 'CUS_123']]);
    config(['services.payment.default' => 'paystack']);

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')
        ->once()
        ->with('CUS_123', 18000, 'GHS', Mockery::any(), Mockery::on(fn (array $meta): bool => $meta['type'] === 'llm_token_purchase' && $meta['pack_key'] === 'standard'))
        ->andReturn('https://checkout.paystack.com/tokens');
    $this->swap(PaymentGateway::class, $gateway);

    $this->actingAs($user)
        ->post(route('billing.llm-checkout', ['subdomain' => $tenant->slug]), ['pack' => 'standard'], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://checkout.paystack.com/tokens');
});

test('the AI usage page offers the packs with their cedi prices', function (): void {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    $this->actingAs($user)
        ->get(route('tenant.llm-usage.index', ['subdomain' => $tenant->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/LlmUsage/Index')
            ->has('tokenPacks', 3)
            ->where('tokenPacks.0', ['key' => 'starter', 'name' => 'Starter Pack', 'tokens' => 500000, 'price' => 'GHS 60.00']));
});
