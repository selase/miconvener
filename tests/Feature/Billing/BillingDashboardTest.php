<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Shared setup if needed
});

it('displays billing dashboard with transactions and subscription', function () {
    // 1. Arrange
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $this->actingAs($user);

    // Create Subscription
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'provider_status' => 'active',
        'current_period_end' => now()->addDays(15),
    ]);

    // Create Transactions
    $t1 = Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'amount' => 5000,
        'status' => 'success',
        'created_at' => now()->subDays(1),
    ]);

    // 2. Act
    $response = $this->get(route('billing.index', ['subdomain' => $tenant->slug]));

    // 3. Assert
    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Billing/Index')
        ->where('subscription.status', 'active')
        ->where('transactions.data.0.amount_formatted', number_format(5000 / 100, 2))
    );
});
