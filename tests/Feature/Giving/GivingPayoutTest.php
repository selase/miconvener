<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\EventPayout;
use App\Models\LedgerTransaction;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\CommunityPayouts;
use App\Services\Giving\GivingBalance;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\GivingScenario;

test('Giving reservations cannot exceed their own balance or spend Dues and event pools', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY, 'recipient_code' => 'RCP_GIVING']);
    $service = app(CommunityPayouts::class);
    $payout = $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 9000]);
    expect($payout->event_id)->toBeNull()->and($payout->category)->toBe(LedgerCategoryEnum::Giving)
        ->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(700);
    expect(fn () => $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 701]))->toThrow(ValidationException::class);
    expect(fn () => $service->reserve($tenant, $user, LedgerCategoryEnum::Event, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 100]))->toThrow(ValidationException::class);
});

test('Giving payout parks for a code finalises and a duplicate transfer confirms exactly one ledger debit', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY, 'recipient_code' => 'RCP_GIVING']);
    Http::fake([
        '*api.paystack.co/transfer/finalize_transfer' => Http::response(['status' => true, 'data' => ['status' => 'pending', 'transfer_code' => 'TRF_GIVING']]),
        '*api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_GIVING']]),
    ]);
    $service = app(CommunityPayouts::class);
    $payout = $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 9700]);
    $service->send($tenant, $user, $payout);
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP)->and($payout->fresh()->transfer_fee_amount)->toBe(0);
    expect($service->send($tenant, $user, $payout)['http_status'])->toBe(422);
    $service->finalize($tenant, $user, $payout, '123456');
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING);
    $body = json_encode(['event' => 'transfer.success', 'data' => ['reference' => $payout->fresh()->provider_reference]]);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_giving')];
    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], $headers, $body)->assertOk();
    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], $headers, $body)->assertOk();
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID)->and(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe(0)
        ->and(LedgerTransaction::query()->where('category', LedgerCategoryEnum::Giving)->where('transaction_type', 'payout')->count())->toBe(1);
});

test('Giving payout request routes accept the category and retained history survives flags off', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY]);
    $tenant->features()->whereIn('feature_key', ['community', 'community_giving'])->update(['enabled' => false]);
    $host = eventSubdomainHost($tenant->slug);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host]);
    $this->get('http://'.$host.'/finance/payouts?category=giving')->assertOk()->assertInertia(fn ($page) => $page->where('category', 'giving')->where('balances.0.available', 9700));
    $this->postJson('http://'.$host.'/finance/payouts', ['category' => 'giving', 'payout_account_id' => $account->id, 'amount' => 9000, 'currency' => 'GHS'])->assertCreated();
    expect(EventPayout::query()->sole()->category)->toBe(LedgerCategoryEnum::Giving);
});

test('a transfer completion arriving before initialization returns never reopens the paid payout', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY, 'recipient_code' => 'RCP_GIVING']);
    $service = app(CommunityPayouts::class);
    $payout = $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 9700]);
    Http::fake(['*api.paystack.co/transfer' => function ($request) use ($service, $payout) {
        $service->settleTransfer($payout->fresh(), $request['reference']);

        return Http::response(['status' => true, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_EARLY']]);
    }]);
    $service->send($tenant, $user, $payout);
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID)->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(0)
        ->and(LedgerTransaction::query()->where('transaction_type', 'payout')->count())->toBe(1);
});

test('Giving transfer reconciliation posts the debit and late failure does not undo paid status', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY]);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Giving, 'payout_account_id' => $account->id, 'amount' => 9700, 'currency' => 'GHS', 'status' => EventPayout::STATUS_PROCESSING, 'provider_reference' => 'giving-reconcile', 'updated_at' => now()->subHours(2)]);
    Http::fake(['*api.paystack.co/transfer/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success']])]);
    $this->artisan('payouts:reconcile')->assertSuccessful();
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID)->and(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe(0);
    $body = json_encode(['event' => 'transfer.failed', 'data' => ['reference' => 'giving-reconcile']]);
    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_giving')], $body)->assertOk();
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID);
});

test('platform payout summaries keep Giving currencies separate', function () {
    [$tenant] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    foreach (['GHS' => 9700, 'USD' => 5000] as $currency => $amount) {
        EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Giving, 'payout_account_id' => $account->id, 'amount' => $amount, 'currency' => $currency, 'status' => EventPayout::STATUS_PAID, 'paid_at' => now()]);
    }
    setPermissionsTeamId(null);
    $admin = App\Models\User::factory()->create(['tenant_id' => null]);
    $admin->assignRole(Spatie\Permission\Models\Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
    $this->actingAs($admin)->get(route('admin.finance.overview'))->assertOk()->assertViewHas('paid30ByCurrency', fn ($totals) => $totals->all() === ['GHS' => 9700, 'USD' => 5000]);
});

test('releasing a Giving transfer calls the provider outside the payout transaction', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY, 'recipient_code' => 'RCP_GIVING']);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Giving, 'payout_account_id' => $account->id, 'amount' => 9700, 'currency' => 'GHS', 'status' => EventPayout::STATUS_AWAITING_OTP, 'provider_reference' => 'giving-otp-outside', 'provider_transfer_code' => 'TRF_GIVING']);
    $level = Illuminate\Support\Facades\DB::connection('landlord')->transactionLevel();
    $observedLevel = null;
    Http::fake(['*api.paystack.co/transfer/finalize_transfer' => function () use (&$observedLevel) {
        $observedLevel = Illuminate\Support\Facades\DB::connection('landlord')->transactionLevel();

        return Http::response(['status' => true, 'data' => ['status' => 'pending', 'transfer_code' => 'TRF_GIVING']]);
    }]);
    app(CommunityPayouts::class)->finalize($tenant, $user, $payout, '123456');
    expect($observedLevel)->toBe($level);
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING);
});
