<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\PaymentFulfillmentService;
use Illuminate\Support\Facades\Artisan;

/**
 * Token packs and invoices were previously fulfilled only when the customer's
 * browser returned from the payment provider. A mobile money payment is approved
 * on the customer's phone and that tab often never comes back, so the money was
 * taken and nothing was delivered. The webhook always arrives, so it fulfils them
 * too -- which means both routes must be safe to run against the same payment.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_one_account']);
    config(['services.paystack.secret_key' => 'sk_one_account']);
});

function fulfillmentTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'llm_topup_balance' => 0]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($user->id);

    return $tenant;
}

function signedBillingPost(array $payload)
{
    $body = json_encode($payload);

    return test()->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_one_account'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

test('a token purchase is fulfilled by the webhook when the browser never returns', function (): void {
    $tenant = fulfillmentTenant();

    signedBillingPost([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_tokens_1',
            'amount' => 500,
            'currency' => 'USD',
            'customer' => ['email' => 'owner@example.com'],
            'metadata' => ['source' => 'miconvener', 'type' => 'llm_token_purchase', 'tenant_id' => $tenant->id, 'pack_key' => 'starter'],
        ],
    ])->assertOk();

    expect((int) $tenant->fresh()->llm_topup_balance)->toBe(500000);
    expect(Transaction::query()->where('provider_transaction_id', 'ref_tokens_1')->count())->toBe(1);
});

test('a redelivered token purchase does not credit the pack twice', function (): void {
    $tenant = fulfillmentTenant();

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_tokens_2',
            'amount' => 500,
            'currency' => 'USD',
            'customer' => ['email' => 'owner@example.com'],
            'metadata' => ['source' => 'miconvener', 'type' => 'llm_token_purchase', 'tenant_id' => $tenant->id, 'pack_key' => 'starter'],
        ],
    ];

    signedBillingPost($payload)->assertOk();
    signedBillingPost($payload)->assertOk();

    expect((int) $tenant->fresh()->llm_topup_balance)->toBe(500000);
    expect(Transaction::query()->where('provider_transaction_id', 'ref_tokens_2')->count())->toBe(1);
});

test('the callback and the webhook cannot both credit the same purchase', function (): void {
    // Whichever route lands first does the work; the other is a no-op. Without
    // this the customer is credited twice for one payment.
    $tenant = fulfillmentTenant();
    $service = app(PaymentFulfillmentService::class);
    $metadata = ['type' => 'llm_token_purchase', 'pack_key' => 'starter'];

    expect($service->fulfillLlmTokenPurchase($tenant, 'ref_tokens_3', $metadata))->toBeTrue();
    expect($service->fulfillLlmTokenPurchase($tenant, 'ref_tokens_3', $metadata))->toBeFalse();

    expect((int) $tenant->fresh()->llm_topup_balance)->toBe(500000);
});

test('an invoice is marked paid by the webhook when the browser never returns', function (): void {
    $tenant = fulfillmentTenant();
    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => Invoice::STATUS_ISSUED,
        'currency' => 'GHS',
    ]);

    signedBillingPost([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_invoice_1',
            'amount' => 12_000,
            'currency' => 'GHS',
            'customer' => ['email' => 'owner@example.com'],
            'metadata' => ['source' => 'miconvener', 'type' => 'metered_invoice', 'invoice_id' => $invoice->id],
        ],
    ])->assertOk();

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID);
    expect($invoice->fresh()->paid_at)->not->toBeNull();
});

test('a redelivered invoice payment does not record a second payment', function (): void {
    $tenant = fulfillmentTenant();
    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => Invoice::STATUS_ISSUED,
        'currency' => 'GHS',
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_invoice_2',
            'amount' => 12_000,
            'currency' => 'GHS',
            'customer' => ['email' => 'owner@example.com'],
            'metadata' => ['source' => 'miconvener', 'type' => 'invoice_payment', 'invoice_id' => $invoice->id],
        ],
    ];

    signedBillingPost($payload)->assertOk();
    signedBillingPost($payload)->assertOk();

    expect(Transaction::query()->where('provider_transaction_id', 'ref_invoice_2')->count())->toBe(1);
});
