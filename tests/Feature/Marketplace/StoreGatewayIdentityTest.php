<?php

declare(strict_types=1);

use App\Services\Marketplace\StoreGateways;
use App\Services\Marketplace\StorePayments;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

it('fails closed when the original platform account changes before checkout', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    config(['services.settlement.paystack.secret_key' => 'sk_test_rotated']);
    Http::fake();
    expect(fn () => app(StorePayments::class)->start($order, $session, (string) Str::uuid()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('verifies against the stored original reference and strips gateway authorizations', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
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

it('does not record provider authorizations or private checkout responses in telemetry during commerce verification', function (): void {
    [, , , , , $attempt] = StoreCheckoutScenario::online();
    $environment = app('env');
    $filters = Laravel\Telescope\Telescope::$filterUsing;
    try {
        app()->instance('env', 'local');
        Laravel\Telescope\Telescope::$filterUsing = [];
        (new App\Providers\TelescopeServiceProvider(app()))->register();
        Laravel\Telescope\Telescope::flushEntries();
        Laravel\Telescope\Telescope::startRecording(false);
        Http::fake(['*api.paystack.co/transaction/verify/*' => function () use ($attempt) {
            Laravel\Telescope\Telescope::recordClientRequest(Laravel\Telescope\IncomingEntry::make(['uri' => 'https://api.paystack.co/transaction/verify/example', 'response' => ['authorization_code' => 'sensitive-placeholder']]));

            return Http::response(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'success', 'amount' => 10000, 'fees' => 195, 'currency' => 'GHS']]);
        }]);
        app(StorePayments::class)->verify($attempt);
        expect(Laravel\Telescope\Telescope::$entriesQueue)->toBe([]);
    } finally {
        Laravel\Telescope\Telescope::stopRecording();
        Laravel\Telescope\Telescope::flushEntries();
        Laravel\Telescope\Telescope::$filterUsing = $filters;
        app()->instance('env', $environment);
    }
});

it('does not propagate provider response bodies or chained authorizations into exception logs', function (): void {
    [, , , , , $attempt] = StoreCheckoutScenario::online();
    Http::fake(['*api.paystack.co/transaction/verify/*' => Http::response(['message' => 'sensitive-placeholder-authorization'], 500)]);
    try {
        app(StorePayments::class)->verify($attempt);
        $this->fail('An unavailable original verification must fail closed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('sensitive-placeholder')->and($exception->getPrevious())->toBeNull();
    }
});
