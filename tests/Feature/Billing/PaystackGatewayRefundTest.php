<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Services\Payment\PaystackGateway;
use Illuminate\Support\Facades\Http;

test('refund returns a string even when Paystack returns the refund id as a JSON integer', function () {
    // This is what Paystack's real API actually returns — data.id is a
    // numeric refund ID, not a quoted string. Every prior test faked this
    // as a string (e.g. 'ref_test_456'), masking that refund()'s `: string`
    // return type throws a TypeError the moment a real integer comes back.
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 987654321],
        ]),
    ]);

    $gateway = new PaystackGateway(['secret_key' => 'sk_test_123']);
    $result = $gateway->refund('some_reference');

    expect($result)->toBeString();
    expect($result)->toBe('987654321');
});

test('refund still falls back to pending when data.id is absent', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => [],
        ]),
    ]);

    $gateway = new PaystackGateway(['secret_key' => 'sk_test_123']);
    $result = $gateway->refund('some_reference');

    expect($result)->toBe('pending');
});
