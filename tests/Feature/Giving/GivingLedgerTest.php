<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\EventLedgerEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Services\Giving\GivingPaymentConfirmer;
use Tests\Support\GivingScenario;

test('Giving platform posting separates actual fees commission and eventless payable', function () {
    [$tenant, , $fund, $attempt] = GivingScenario::online();
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    $payable = LedgerAccount::query()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)->firstOrFail();
    expect($payable->currentBalance())->toBe(9700)->and($payable->event_id)->toBeNull()
        ->and(LedgerTransaction::query()->sole()->giving_fund_id)->toBe($fund->id)
        ->and(EventLedgerEntry::query()->count())->toBe(0)
        ->and($attempt->gift->fresh()->net_amount)->toBe(9700);
    $this->artisan('finance:verify-categories')->assertSuccessful();
});

test('own Paystack giving records only commission owed and never platform-held principal', function () {
    [$tenant, , , $attempt] = GivingScenario::online(own: true);
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    $accounts = LedgerAccount::query()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->get()->keyBy('code');
    expect($accounts[LedgerAccount::CODE_ORGANIZER_PAYABLE]->currentBalance())->toBe(-100)
        ->and($accounts[LedgerAccount::CODE_GATEWAY_CLEARING]->currentBalance())->toBe(0)
        ->and($attempt->gift->fresh()->net_amount)->toBe(9700);
});

test('a signed platform giving webhook accepts JSON-string metadata and rejects a wrong signature', function () {
    [, , , $attempt] = GivingScenario::online();
    $data = GivingScenario::verified($attempt);
    $data['metadata'] = json_encode($data['metadata']);
    unset($data['provider'], $data['account_identity']);
    $body = json_encode(['event' => 'charge.success', 'data' => $data]);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_giving')];
    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], $headers, $body)->assertOk();
    expect($attempt->gift->fresh()->status)->toBe('completed');
    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], [...$headers, 'HTTP_x-paystack-signature' => 'wrong'], $body)->assertStatus(400);
    expect(LedgerTransaction::query()->count())->toBe(1);
});

test('a signed merchant giving webhook uses its own account and does not enter ticket or dues fulfilment', function () {
    [$tenant, , , $attempt] = GivingScenario::online(own: true);
    $data = GivingScenario::verified($attempt);
    $data['metadata'] = json_encode($data['metadata']);
    $data['id'] = 123;
    unset($data['provider'], $data['account_identity']);
    $body = json_encode(['event' => 'charge.success', 'data' => $data]);
    $this->call('POST', '/webhooks/merchant/paystack/'.$tenant->id, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_own_giving')], $body)->assertOk();
    expect($attempt->gift->fresh()->status)->toBe('completed')
        ->and(App\Models\DuesCharge::query()->count())->toBe(0)->and(App\Models\EventRegistration::query()->count())->toBe(0);
});

test('an own-account Giving waiver completes without fabricating a platform ledger movement', function () {
    [$tenant, , $fund] = GivingScenario::online(own: true);
    App\Models\CommercialTermsOverride::query()->create(['tenant_id' => $tenant->id, 'stream' => 'giving', 'rate' => 0, 'reason' => 'Approved waiver']);
    $key = (string) Illuminate\Support\Str::uuid();
    app(App\Services\Giving\GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'provider' => 'paystack'], app('session.store'), $key);
    $attempt = App\Models\GivingPaymentAttempt::withoutGlobalScopes()->where('submission_key', $key)->firstOrFail();
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    expect($attempt->commission_amount)->toBe(0)->and($attempt->gift->fresh()->status)->toBe('completed')
        ->and($attempt->gift->fresh()->net_amount)->toBe(9800)->and(LedgerTransaction::query()->count())->toBe(0);
});
