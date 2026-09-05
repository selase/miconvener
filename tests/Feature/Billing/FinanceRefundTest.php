<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function financeRefundHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function financeRefundSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('refunding a transaction stores the real Paystack refund id, not a mangled string', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'ref_test_456'],
        ]),
    ]);

    [$tenant, $user] = financeRefundHost('acme');
    $host = financeRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $transaction = MerchantTransaction::create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'orig_ref_123',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => 'succeeded',
        'type' => 'payment',
        'customer_email' => 'guest@example.com',
    ]);

    $response = $this->actingAs($user)->post("http://{$host}/finance/refund/{$transaction->id}", [], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Refund processed successfully.');

    $transaction->refresh();
    expect($transaction->status)->toBe('refunded');

    $refundRecord = MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'refund')->firstOrFail();
    expect($refundRecord->provider_transaction_id)->toBe('ref_test_456');
    expect($refundRecord->meta)->toBe(['refund_id' => 'ref_test_456']);
});
