<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->superadmin = User::factory()->create(['email' => 'admin@system.com']);
    $role = Spatie\Permission\Models\Role::create([
        'name' => 'Superadmin',
        'guard_name' => 'web',
        'uuid' => Illuminate\Support\Str::uuid(),
    ]);
    $this->superadmin->assignRole($role);
    $this->superadmin->givePermissionTo(Spatie\Permission\Models\Permission::create([
        'name' => 'access-superadmin-dashboard',
        'uuid' => Illuminate\Support\Str::uuid(),
        'category' => 'system',
    ]));
});

test('superadmin can view transactions', function () {
    $response = $this->actingAs($this->superadmin)
        ->get(route('admin.billing.transactions.index'));

    $response->assertStatus(200);
    $response->assertViewIs('admin.billing.transactions.index');
    $response->assertViewHas('transactions');
});

test('superadmin can view subscriptions', function () {
    $response = $this->actingAs($this->superadmin)
        ->get(route('admin.billing.subscriptions.index'));

    $response->assertStatus(200);
    $response->assertViewIs('admin.billing.subscriptions.index');
    $response->assertViewHas('subscriptions');
});

test('regular user cannot view global billing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('admin.billing.transactions.index'));

    $response->assertStatus(403);
});

test('dashboard loads with stats', function () {
    // Seed some data
    Transaction::create([
        'tenant_id' => Tenant::factory()->create()->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => 'success',
        'provider' => 'stripe',
        'provider_transaction_id' => 'tx_123',
        'type' => 'charge',
    ]);

    $response = $this->actingAs($this->superadmin)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertViewHas('totalSuccessVolume', 5000);
    $response->assertViewHas('totalTransactions', 1);
    $response->assertViewHas('transactionTrend');
});
