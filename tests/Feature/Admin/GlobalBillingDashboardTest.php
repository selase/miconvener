<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Subscription;
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

test('superadmin can view transactions, including a real one', function () {
    // A populated row is the point of this test: the page previously called a
    // money-formatting method that did not exist, so it only ever rendered
    // successfully when the transaction list was empty.
    Transaction::create([
        'tenant_id' => Tenant::factory()->create()->id,
        'amount' => 9900,
        'currency' => 'GHS',
        'status' => 'success',
        'provider' => 'paystack',
        'provider_transaction_id' => 'tx_real_123',
        'type' => 'charge',
    ]);

    $response = $this->actingAs($this->superadmin)
        ->get(route('admin.billing.transactions.index'));

    $response->assertStatus(200);
    $response->assertViewIs('admin.billing.transactions.index');
    $response->assertViewHas('transactions');
    $response->assertSee('tx_real_123');
    $response->assertSee('GHS 99.00');
});

test('superadmin can view plan renewals, including a real past-due one', function () {
    $growth = Package::factory()->create(['name' => 'Growth', 'is_free' => false]);
    $tenant = Tenant::factory()->create(['name' => 'Acme Events', 'package_id' => $growth->id]);
    Subscription::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'ps_real_1',
        'provider_status' => Subscription::STATUS_PAST_DUE,
        'provider_plan' => 'growth_month',
        'interval' => 'month',
        'current_period_end' => now()->subDays(2),
        'grace_ends_at' => now()->addDays(5),
    ]);

    $response = $this->actingAs($this->superadmin)
        ->get(route('admin.billing.subscriptions.index'));

    $response->assertStatus(200);
    $response->assertViewIs('admin.billing.subscriptions.index');
    $response->assertViewHas('rows');
    $response->assertSee('Acme Events');
    $response->assertSee('Growth');
    $response->assertSee('Past due');
});

test('a free tenant appears in the renewals list with no toggle button', function () {
    $free = Package::factory()->create(['name' => 'Free', 'is_free' => true]);
    Tenant::factory()->create(['name' => 'Freebie Org', 'package_id' => $free->id]);

    $response = $this->actingAs($this->superadmin)
        ->get(route('admin.billing.subscriptions.index'));

    $response->assertOk();
    $response->assertSee('Freebie Org');
    $response->assertSee('Free plan');
    $response->assertDontSee('Make complimentary');
});

test('the status filter narrows to complimentary tenants', function () {
    $paid = Package::factory()->create(['is_free' => false]);
    Tenant::factory()->create(['name' => 'Comped Org', 'package_id' => $paid->id, 'billing_complimentary' => true]);
    Tenant::factory()->create(['name' => 'Paying Org', 'package_id' => $paid->id, 'billing_complimentary' => false]);

    $response = $this->actingAs($this->superadmin)
        ->get(route('admin.billing.subscriptions.index', ['status' => 'complimentary']));

    $response->assertSee('Comped Org');
    $response->assertDontSee('Paying Org');
});

test('superadmin can make a tenant complimentary and bill them again from the admin page', function () {
    $paid = Package::factory()->create(['is_free' => false]);
    $tenant = Tenant::factory()->create(['name' => 'Toggle Org', 'package_id' => $paid->id, 'billing_complimentary' => false]);

    // The admin layout's toast only ever reads session('message') +
    // session('status') -- a plain 'success' key, which several sibling admin
    // controllers use, is silently dropped and never shown. Assert the keys
    // that are actually rendered.
    $this->actingAs($this->superadmin)
        ->post(route('admin.billing.subscriptions.toggle-complimentary', $tenant->uuid))
        ->assertRedirect()
        ->assertSessionHas('status', 'success')
        ->assertSessionHas('message', 'Toggle Org is now complimentary.');

    expect($tenant->fresh()->billing_complimentary)->toBeTrue();

    $this->actingAs($this->superadmin)
        ->post(route('admin.billing.subscriptions.toggle-complimentary', $tenant->uuid))
        ->assertRedirect()
        ->assertSessionHas('message', 'Toggle Org is billed again.');

    expect($tenant->fresh()->billing_complimentary)->toBeFalse();
});

test('a regular user cannot toggle a tenant\'s complimentary status', function () {
    $tenant = Tenant::factory()->create(['billing_complimentary' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.billing.subscriptions.toggle-complimentary', $tenant->uuid))
        ->assertStatus(403);

    expect($tenant->fresh()->billing_complimentary)->toBeFalse();
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

test('a tenant with no package assigned does not crash the renewals page', function (): void {
    Tenant::factory()->create(['name' => 'Unassigned Org', 'package_id' => null]);

    $response = $this->actingAs($this->superadmin)
        ->get(route('admin.billing.subscriptions.index'));

    $response->assertOk();
    $response->assertSee('Unassigned Org');
    $response->assertSee('Free');
});
