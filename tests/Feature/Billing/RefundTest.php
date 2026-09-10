<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('refunds are disabled for subscription transactions', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    $transaction = Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'amount' => 5000,
        'status' => 'success',
        'provider_transaction_id' => 'ch_test_123',
    ]);

    $response = actingAs($user)
        ->post(route('billing.refund', ['transaction' => $transaction, 'subdomain' => $tenant->slug]));

    $response->assertStatus(403);
    expect($transaction->refresh()->status)->toBe('success');
});

test('refund button is disabled and can_refund is false on billing page', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'success',
        'provider_transaction_id' => 'ch_visible',
    ]);

    $response = actingAs($user)->get(route('billing.index', ['subdomain' => $tenant->slug]));

    $response->assertInertia(fn ($page) => $page
        ->component('Billing/Index')
        ->where('transactions.data.0.can_refund', false)
        ->has('currency')
    );
});
