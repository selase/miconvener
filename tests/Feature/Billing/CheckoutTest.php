<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;

function makeProPackage(): Package
{
    return Package::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price' => 19,
        'yearly_price' => 190,
        'interval' => 'month',
        'billing_model' => 'flat_rate',
        'description' => 'Pro plan',
        'is_active' => true,
        'is_free' => false,
        'sort_order' => 1,
    ]);
}

// ── Upgrade (new subscription) ────────────────────────────────────────────────

it('redirects to paystack checkout for upgrade using one-time transaction with plan metadata', function () {
    $user = User::factory()->create();
    $package = makeProPackage();
    $tenant = setActiveTenantForTest($user, ['meta' => ['paystack_id' => 'CUS_123']]);

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')
        ->once()
        ->with('CUS_123', 1900, Mockery::any(), Mockery::any(), Mockery::on(function ($meta) {
            return ($meta['type'] ?? '') === 'plan_subscription'
                && ($meta['plan_slug'] ?? '') === 'pro'
                && ($meta['interval'] ?? '') === 'month';
        }))
        ->andReturn('https://paystack.com/checkout/test');

    $this->swap(PaymentGateway::class, $gateway);
    $this->actingAs($user);

    $this->post(route('billing.checkout', ['subdomain' => $tenant->slug]), [
        'plan' => 'pro',
        'interval' => 'month',
    ])->assertRedirect('https://paystack.com/checkout/test');
});

it('uses yearly price when interval is year', function () {
    $user = User::factory()->create();
    $package = makeProPackage();
    $tenant = setActiveTenantForTest($user, ['meta' => ['paystack_id' => 'CUS_123']]);

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')
        ->once()
        ->with('CUS_123', 19000, Mockery::any(), Mockery::any(), Mockery::on(function ($meta) {
            return ($meta['interval'] ?? '') === 'year';
        }))
        ->andReturn('https://paystack.com/checkout/yearly');

    $this->swap(PaymentGateway::class, $gateway);
    $this->actingAs($user);

    $this->post(route('billing.checkout', ['subdomain' => $tenant->slug]), [
        'plan' => 'pro',
        'interval' => 'year',
    ])->assertRedirect('https://paystack.com/checkout/yearly');
});

it('creates customer if missing before checkout', function () {
    $user = User::factory()->create();
    $package = makeProPackage();
    $tenant = setActiveTenantForTest($user, ['meta' => []]);

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createCustomer')->once()->andReturn('CUS_new_456');
    $gateway->shouldReceive('createOneTimeCheckoutSession')
        ->once()
        ->with('CUS_new_456', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturn('https://paystack.com/checkout/test');

    $this->swap(PaymentGateway::class, $gateway);
    $this->actingAs($user);

    $this->post(route('billing.checkout', ['subdomain' => $tenant->slug]), [
        'plan' => 'pro',
        'interval' => 'month',
    ]);

    $tenant->refresh();
    expect($tenant->meta['paystack_id'])->toBe('CUS_new_456');
});

it('validates that plan slug must exist in packages', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $this->actingAs($user);

    $this->post(route('billing.checkout', ['subdomain' => $tenant->slug]), [
        'plan' => 'nonexistent-plan',
    ])->assertSessionHasErrors(['plan']);
});

// ── Dev bypass ─────────────────────────────────────────────────────────────────

it('dev bypass provisions immediately without hitting paystack', function () {
    Config::set('services.payment.dev_bypass', true);

    $user = User::factory()->create();
    $package = makeProPackage();
    $tenant = setActiveTenantForTest($user);

    $this->actingAs($user);

    $this->post(route('billing.checkout', ['subdomain' => $tenant->slug]), [
        'plan' => 'pro',
        'interval' => 'month',
    ])->assertRedirect();

    $tenant->refresh();
    expect((int) $tenant->package_id)->toBe((int) $package->id);
});

// ── Free plan ─────────────────────────────────────────────────────────────────

it('switching to free plan provisions immediately without payment', function () {
    $user = User::factory()->create();
    $freePackage = Package::create([
        'name' => 'Free', 'slug' => 'free', 'price' => 0,
        'interval' => 'month', 'billing_model' => 'flat_rate',
        'description' => 'Free plan', 'is_active' => true,
        'is_free' => true, 'sort_order' => 0,
    ]);
    $proPackage = makeProPackage();
    $tenant = setActiveTenantForTest($user, ['package_id' => $proPackage->id]);

    $this->actingAs($user);
    $this->post(route('billing.checkout', ['subdomain' => $tenant->slug]), [
        'plan' => 'free',
    ])->assertRedirect();

    $tenant->refresh();
    expect((int) $tenant->package_id)->toBe((int) $freePackage->id);
});

// ── Downgrade ─────────────────────────────────────────────────────────────────

it('downgrade is scheduled without initiating payment', function () {
    $user = User::factory()->create();
    $bizPackage = Package::create([
        'name' => 'Business', 'slug' => 'business', 'price' => 49,
        'interval' => 'month', 'billing_model' => 'flat_rate',
        'description' => 'Business plan', 'is_active' => true,
        'is_free' => false, 'sort_order' => 2,
    ]);
    $proPackage = makeProPackage();
    $tenant = setActiveTenantForTest($user, ['package_id' => $bizPackage->id]);

    Subscription::create([
        'tenant_id' => $tenant->id, 'name' => 'default',
        'provider_id' => 'SUB_CTRL', 'provider_status' => 'active',
        'provider_plan' => 'pro_month', 'current_period_end' => now()->addMonth(),
    ]);

    $this->actingAs($user);
    $response = $this->post(route('billing.checkout', ['subdomain' => $tenant->slug]), [
        'plan' => 'pro',
    ]);

    $tenant->refresh();
    expect((int) $tenant->package_id)->toBe((int) $bizPackage->id);
    $response->assertRedirect()->assertSessionHas('info');
});

// ── Invoice checkout ──────────────────────────────────────────────────────────

it('redirects to provider for invoice payment', function () {
    Config::set('services.payment.default', 'paystack');
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['meta' => ['paystack_id' => 'CUS_123']]);
    $invoice = App\Models\Invoice::create([
        'tenant_id' => $tenant->id,
        'number' => 'INV-001',
        'total' => 100,
        'currency' => 'USD',
        'status' => App\Models\Invoice::STATUS_ISSUED,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')
        ->once()->andReturn('https://paystack.com/invoice-checkout');
    $this->swap(PaymentGateway::class, $gateway);
    $this->actingAs($user);

    $this->post(route('billing.checkout', ['subdomain' => $tenant->slug]), [
        'invoice_id' => $invoice->id,
    ])->assertRedirect('https://paystack.com/invoice-checkout');
});
