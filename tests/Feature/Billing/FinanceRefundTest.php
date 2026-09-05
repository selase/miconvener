<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Tenant\FinanceController;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Mockery;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('refunding a transaction stores the real Paystack refund id, not a mangled string', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    // Set up tenant context
    app(TenantContext::class)->setTenant($tenant);

    // Create original transaction
    $transaction = MerchantTransaction::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'orig_ref_123',
        'currency' => 'GHS',
    ]);

    // Mock the payment gateway in the container
    $mockGateway = Mockery::mock(PaymentGateway::class);
    $mockGateway->shouldReceive('refund')
        ->with('orig_ref_123')
        ->once()
        ->andReturn('ref_test_456'); // Returns a string, not an array

    app()->instance(PaymentGateway::class, $mockGateway);

    // Create a request object
    $request = Request::create('POST', '/finance/refund/'.$transaction->id);

    // Call the controller method directly
    $controller = new FinanceController(app(TenantContext::class));
    $response = $controller->refund($request, $transaction, $mockGateway);

    // Verify response is a redirect (status 302 or contains Location header)
    expect($response->getStatusCode())->toBeIn([302, 303, 307, 308]);

    // Verify original transaction is marked as refunded
    $transaction->refresh();
    expect($transaction->status)->toBe('refunded');

    // Verify refund record was created with correct data
    $refundRecord = MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'refund')->firstOrFail();
    expect($refundRecord->provider_transaction_id)->toBe('ref_test_456');
    expect($refundRecord->meta)->toBe(['refund_id' => 'ref_test_456']);
});
