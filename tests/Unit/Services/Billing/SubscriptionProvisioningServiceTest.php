<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Billing\SubscriptionProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubscriptionProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['landlord'];

    public function test_it_provisions_subscription_and_updates_tenant_package()
    {
        // Arrange
        $tenant = Tenant::factory()->create();
        $package = Package::factory()->create(['slug' => 'premium-plan']); // Assuming Package factory exists

        $service = new SubscriptionProvisioningService();

        $dto = [
            'tenant_id' => $tenant->id,
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_123',
            'provider_plan_id' => 'price_premium',
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'amount_paid' => 1000,
            'currency' => 'USD',
            'transaction_id' => 'tx_abc123',
            'package_id' => $package->id,
        ];

        // Act
        $service->provision($tenant, $dto);

        // Assert
        // 1. Tenant Package Updated
        $tenant->refresh();
        $this->assertEquals($package->id, $tenant->package_id);

        // 2. Subscription Recorded
        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'provider_id' => 'sub_123',
            'provider_status' => 'active',
            'provider_plan' => 'price_premium',
        ], 'landlord');

        // 3. Transaction Recorded
        $this->assertDatabaseHas('transactions', [
            'tenant_id' => $tenant->id,
            'provider_transaction_id' => 'tx_abc123',
            'amount' => 1000,
            'type' => 'charge',
        ], 'landlord');
    }
}
