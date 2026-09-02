<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Payment\StripeGateway;
use Illuminate\Support\Facades\Config;

it('resolves stripe gateway by default', function () {
    Config::set('services.payment.default', 'stripe');
    Config::set('services.stripe.secret', 'sk_test_123');

    $gateway = app(PaymentGateway::class);

    expect($gateway)->toBeInstanceOf(StripeGateway::class);
});

it('resolves paystack gateway when configured', function () {
    Config::set('services.payment.default', 'paystack');
    Config::set('services.paystack.secret_key', 'sk_test_123');

    // Mock Http calls for Paystack
    Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/*' => Illuminate\Support\Facades\Http::response(['data' => ['customer_code' => 'CUS_123']], 200),
    ]);

    $gateway = app(PaymentGateway::class);

    expect($gateway)->toBeInstanceOf(PaystackGateway::class);
    expect($gateway->createCustomer('test@example.com', 'Test User'))->toBe('CUS_123');
});
