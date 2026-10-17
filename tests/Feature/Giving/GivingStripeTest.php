<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\GivingPaymentAttempt;
use App\Models\LedgerAccount;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Giving\GivingCheckout;
use App\Services\Giving\GivingGateways;
use App\Services\Giving\GivingPaymentConfirmer;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Support\GivingScenario;
use Tests\Support\GivingStripeHttp;

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

test('own Stripe checkout binds session account and idempotency and posts only actual commission', function () {
    [$tenant, , $fund] = GivingScenario::make(['visibility' => 'public']);
    $tenant->update(['package_id' => App\Models\Package::factory()->create()->id, 'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $tenant->features()->where('feature_key', 'community_giving')->update(['meta' => ['online_enabled' => true]]);
    config(['services.stripe.secret' => 'sk_test_giving_stripe', 'services.stripe.connect.client_id' => 'ca_giving']);
    TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_account_id' => 'acct_giving', 'api_key_encrypted' => null, 'is_active' => true]);
    $fake = new GivingStripeHttp;
    ApiRequestor::setHttpClient($fake);
    $url = app(GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'payer_email' => 'giver@example.test', 'provider' => 'stripe'], app('session.store'), (string) Str::uuid());
    $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($url)->toBe('https://checkout.stripe.com/giving')->and($attempt->provider_session_id)->toBe('cs_giving')
        ->and($fake->requests[0]['headers'])->toContain('Stripe-Account: acct_giving', 'Idempotency-Key: '.$attempt->id)
        ->and($fake->requests[0]['params']['payment_intent_data']['application_fee_amount'])->toBe(100);
    $data = app(GivingGateways::class)->verify($attempt);
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, $data);
    expect($attempt->fresh()->state)->toBe('confirmed')->and($attempt->fresh()->provider_reference)->toBe('pi_giving');
    $accounts = LedgerAccount::query()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->get()->keyBy('code');
    expect($accounts[LedgerAccount::CODE_GATEWAY_CLEARING]->currentBalance())->toBe(100)
        ->and($accounts[LedgerAccount::CODE_ORGANIZER_PAYABLE]->currentBalance())->toBe(0);
    foreach ($fake->requests as $request) {
        expect($request['headers'])->toContain('Stripe-Account: acct_giving');
    }
});
