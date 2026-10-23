<?php

declare(strict_types=1);

use App\Services\Marketplace\StoreGateways;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\StoreQuotes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

it('fails closed when the original platform account changes before checkout', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = app(StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    config(['services.settlement.paystack.secret_key' => 'sk_test_rotated']);
    Http::fake();
    expect(fn () => app(StorePayments::class)->start($order, $session, (string) Str::uuid()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('verifies against the stored original reference and strips gateway authorizations', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = app(StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    Http::fake([
        '*api.paystack.co/customer*' => Http::response(['data' => ['customer_code' => 'CUS_STORE', 'email' => 'buyer@example.test']]),
        '*api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/store-test']]),
    ]);
    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid());
    Http::fake(['*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'success', 'amount' => 10000, 'fees' => 195, 'currency' => 'GHS',
        'paid_at' => now()->toIso8601String(), 'metadata' => json_encode(['source' => 'miconvener_store', 'store_order_id' => $order->id, 'store_attempt_id' => $attempt->id, 'seller_id' => $tenant->id]),
        'authorization' => ['authorization_code' => 'never-retain-this']]])]);
    $verified = app(StorePayments::class)->verify($attempt);
    expect($verified['reference'])->toBe($attempt->reference)->and($verified['amount'])->toBe(10000)
        ->and($verified['account_fingerprint'])->toBe($order->account_fingerprint)->and($verified)->not->toHaveKey('authorization');
    $tenant->update(['settlement_mode' => App\Models\Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    expect(app(StoreGateways::class)->forAttempt($attempt))->toBeInstanceOf(App\Services\Payment\PaystackGateway::class);
});
