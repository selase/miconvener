<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use App\Services\Settlement\PaystackSettlementGateway;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

test('resolveAccount returns the account holder name from a successful resolve call', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_number' => '1234567890', 'account_name' => 'Kwame Asante', 'bank_id' => 58],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $result = $gateway->resolveAccount('058', '1234567890');

    expect($result)->toBe(['account_name' => 'Kwame Asante']);
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'account_number=1234567890')
        && str_contains((string) $request->url(), 'bank_code=058'));
});

test('resolveAccount throws PaymentFailedException when the account cannot be resolved', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name'], 422),
    ]);

    $gateway = app(PaystackSettlementGateway::class);

    expect(fn () => $gateway->resolveAccount('058', '0000000000'))->toThrow(PaymentFailedException::class);
});

test('createRecipient returns the recipient code', function () {
    Http::fake([
        'api.paystack.co/transferrecipient' => Http::response([
            'status' => true,
            'data' => ['recipient_code' => 'RCP_test_123'],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $code = $gateway->createRecipient('nuban', 'Kwame Asante', '1234567890', '058', 'GHS');

    expect($code)->toBe('RCP_test_123');
});

test('initiateTransfer returns the transfer code and status', function () {
    Http::fake([
        'api.paystack.co/transfer' => Http::response([
            'status' => true,
            'data' => ['transfer_code' => 'TRF_test_123', 'status' => 'pending'],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $result = $gateway->initiateTransfer('RCP_test_123', 15_000, 'GHS', 'settlement_ref_1');

    // 'fee' is null here because this fake reports none; the caller then falls
    // back to the configured transfer-fee schedule.
    expect($result)->toBe(['transfer_code' => 'TRF_test_123', 'status' => 'pending', 'fee' => null]);
    Http::assertSent(fn ($request): bool => $request['recipient'] === 'RCP_test_123'
        && $request['amount'] === 15_000
        && $request['reference'] === 'settlement_ref_1');
});

test('verifyTransfer returns the transfer status', function () {
    Http::fake([
        'api.paystack.co/transfer/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success'],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $result = $gateway->verifyTransfer('settlement_ref_1');

    expect($result)->toBe(['status' => 'success', 'fee' => null]);
});

test('listBanks returns a list of bank names and codes', function () {
    Http::fake([
        'api.paystack.co/bank*' => Http::response([
            'status' => true,
            'data' => [
                ['name' => 'Absa Bank Ghana', 'code' => '030'],
                ['name' => 'MTN Mobile Money', 'code' => 'MTN'],
            ],
        ]),
    ]);

    $gateway = app(PaystackSettlementGateway::class);
    $banks = $gateway->listBanks('ghana');

    expect($banks)->toBe([
        ['name' => 'Absa Bank Ghana', 'code' => '030'],
        ['name' => 'MTN Mobile Money', 'code' => 'MTN'],
    ]);
});

test('the SettlementGateway contract resolves to PaystackSettlementGateway by default', function () {
    expect(app(SettlementGateway::class))->toBeInstanceOf(PaystackSettlementGateway::class);
});
