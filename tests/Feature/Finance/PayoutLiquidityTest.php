<?php

declare(strict_types=1);

use App\Models\EventPayout;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\PayoutPolicies;
use App\Services\Finance\ProviderLiquidity;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['services.settlement.paystack.secret_key' => 'sk_test_liquidity', 'services.payout_schedules.liquidity_controls' => true,
        'services.payout_schedules.cash_buffer' => 100, 'services.payout_schedules.daily_total_limit' => 100000,
        'services.payout_schedules.paused' => false]);
});

test('shared account liquidity cannot be allocated twice across organisations', function () {
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 10000]]])]);
    $service = app(ProviderLiquidity::class);
    $fingerprint = app(PayoutPolicies::class)->accountFingerprint();
    $service->refresh($fingerprint, 'GHS');
    $account = TenantPayoutAccount::factory()->create();
    $first = EventPayout::factory()->create(['tenant_id' => $account->tenant_id, 'event_id' => null, 'category' => 'dues',
        'payout_account_id' => $account->id, 'amount' => 6000, 'status' => 'processing', 'provider_reference' => 'first-liquidity-transfer']);
    $service->claim($first);
    $otherAccount = TenantPayoutAccount::factory()->create();
    $second = EventPayout::factory()->create(['tenant_id' => $otherAccount->tenant_id, 'event_id' => null, 'category' => 'dues',
        'payout_account_id' => $otherAccount->id, 'amount' => 6000, 'status' => 'processing', 'provider_reference' => 'second-liquidity-transfer']);
    expect(fn () => $service->claim($second))->toThrow(ValidationException::class);
});

test('unknown provider balance and missing platform limits fail closed', function () {
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => false], 503)]);
    expect(fn () => app(ProviderLiquidity::class)->refresh(app(PayoutPolicies::class)->accountFingerprint(), 'GHS'))->toThrow(ValidationException::class);
});
