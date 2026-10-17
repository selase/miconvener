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

test('Giving transfer reconciliation recovers a lost OTP response without resending the transfer', function () {
    [$tenant,$user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'recipient_code' => 'RCP_UNKNOWN']);
    $service = app(CommunityPayouts::class);
    $payout = $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 9700]);
    Http::fake(['*api.paystack.co/transfer' => fn () => throw new Illuminate\Http\Client\ConnectionException('Response lost')]);
    $service->send($tenant, $user, $payout);
    $payout->refresh();
    expect($payout->status)->toBe('processing');
    $payout->forceFill(['updated_at' => now()->subHours(2)])->save();
    Http::fake(['*api.paystack.co/transfer/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_RECOVERED', 'reference' => $payout->provider_reference, 'amount' => $payout->requested_net_amount, 'currency' => 'GHS']])]);
    $this->artisan('payouts:reconcile')->assertSuccessful();
    expect($payout->fresh()->status)->toBe('awaiting_otp')->and($payout->fresh()->provider_transfer_code)->toBe('TRF_RECOVERED')->and($payout->fresh()->transfer_fee_amount)->toBe(0);
    expect($service->send($tenant, $user, $payout)['http_status'])->toBe(422);
});

test('recipient registration failure releases a community payout claim before transfer submission', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'recipient_code' => null]);
    $service = app(CommunityPayouts::class);
    $payout = $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 9000]);
    Http::fake([
        '*api.paystack.co/transferrecipient' => Http::sequence()->push(['status' => false, 'message' => 'Recipient unavailable'], 502)->push(['status' => true, 'data' => ['recipient_code' => 'RCP_RETRY']]),
        '*api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_RETRY']]),
    ]);
    expect($service->send($tenant, $user, $payout)['http_status'])->toBe(502);
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_FAILED)
        ->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(9700);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/transfer'));
    expect($service->send($tenant, $user, $payout)['http_status'])->toBe(200)
        ->and($payout->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP);
});

test('Dues uncertain OTP release recovers the original verified transfer without resending', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'recipient_code' => 'RCP_DUES']);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues, 'payout_account_id' => $account->id, 'amount' => 9000, 'currency' => 'GHS', 'status' => EventPayout::STATUS_AWAITING_OTP, 'provider_reference' => 'dues-otp-unknown', 'provider_transfer_code' => 'TRF_DUES', 'requested_net_amount' => 8900, 'requested_transfer_fee_amount' => 100]);
    Http::fake(['*api.paystack.co/transfer/finalize_transfer' => fn () => throw new Illuminate\Http\Client\ConnectionException('Response lost')]);
    app(CommunityPayouts::class)->finalize($tenant, $user, $payout, '123456');
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING);
    $payout->refresh()->forceFill(['updated_at' => now()->subHours(2)])->save();
    Http::fake(['*api.paystack.co/transfer/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_DUES', 'reference' => 'dues-otp-unknown', 'amount' => 8900, 'currency' => 'GHS']])]);
    $this->artisan('payouts:reconcile')->assertSuccessful();
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP)
        ->and($payout->fresh()->transfer_fee_amount)->toBe(0);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/transfer'));
});

test('an incomplete community transfer response stays reserved until original-reference recovery', function (array $response) {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'recipient_code' => 'RCP_NO_CODE']);
    $service = app(CommunityPayouts::class);
    $payout = $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 9700]);
    Http::fake(['*api.paystack.co/transfer' => Http::response(['status' => true, 'data' => $response])]);
    expect($service->send($tenant, $user, $payout)['http_status'])->toBe(502);
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING)->and($payout->fresh()->transfer_fee_amount)->toBe(0);
    expect($service->send($tenant, $user, $payout)['http_status'])->toBe(422);
    expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/transfer')))->toHaveCount(1);
    $payout->refresh()->forceFill(['updated_at' => now()->subHours(2)])->save();
    Http::fake(['*api.paystack.co/transfer/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_NO_CODE_RECOVERED', 'reference' => $payout->provider_reference, 'amount' => $payout->requested_net_amount, 'currency' => 'GHS']])]);
    $this->artisan('payouts:reconcile')->assertSuccessful();
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/transfer'));
})->with(['OTP without code' => [['status' => 'otp']], 'missing transfer outcome' => [[]]]);

test('a late community send response cannot replace a newer transfer after definitive failure', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'recipient_code' => 'RCP_ORDER']);
    $service = app(CommunityPayouts::class);
    $payout = $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 9700]);
    $calls = 0;
    $currentReference = null;
    Http::fake(['*api.paystack.co/transfer' => function ($request) use ($service, $payout, $tenant, $user, &$calls, &$currentReference) {
        $calls++;
        if ($calls === 1) {
            $payout->fresh()->update(['status' => EventPayout::STATUS_FAILED]);
            expect($service->send($tenant, $user, $payout)['http_status'])->toBe(200);

            return Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_STALE']]);
        }
        $currentReference = $request['reference'];

        return Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_CURRENT']]);
    }]);
    $service->send($tenant, $user, $payout);
    expect($payout->fresh()->provider_reference)->toBe($currentReference)
        ->and($payout->fresh()->provider_transfer_code)->toBe('TRF_CURRENT')
        ->and($payout->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP);
});

test('stale original-reference reconciliation cannot mutate a newer community claim', function (string $category, string $outcome) {
    [$tenant] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::from($category), 'payout_account_id' => $account->id, 'amount' => 9700, 'currency' => 'GHS', 'status' => EventPayout::STATUS_PROCESSING, 'provider_reference' => 'old-reconcile-reference', 'provider_transfer_code' => 'TRF_OLD', 'requested_net_amount' => 9600, 'updated_at' => now()->subHours(2)]);
    Http::fake(['*api.paystack.co/transfer/verify/*' => function () use ($payout, $outcome) {
        $payout->fresh()->update(['provider_reference' => 'new-reconcile-reference', 'provider_transfer_code' => 'TRF_NEW']);

        return Http::response(['status' => true, 'data' => ['status' => $outcome, 'reference' => 'old-reconcile-reference', 'transfer_code' => 'TRF_OLD', 'amount' => 9600, 'currency' => 'GHS']]);
    }]);
    $this->artisan('payouts:reconcile')->assertSuccessful();
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING)->and($payout->fresh()->provider_reference)->toBe('new-reconcile-reference')->and($payout->fresh()->provider_transfer_code)->toBe('TRF_NEW');
    expect(LedgerTransaction::withoutGlobalScopes()->where('reference', 'new-reconcile-reference')->count())->toBe(0);
})->with(['giving', 'dues'])->with(['otp', 'failed', 'success']);

test('stale community release cannot change a newer processing transfer', function () {
    [$tenant, $user] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Giving, 'payout_account_id' => $account->id, 'amount' => 9700, 'currency' => 'GHS', 'status' => EventPayout::STATUS_AWAITING_OTP, 'provider_reference' => 'old-release-reference', 'provider_transfer_code' => 'TRF_OLD']);
    Http::fake(['*api.paystack.co/transfer/finalize_transfer' => function () use ($payout) {
        $payout->fresh()->update(['provider_reference' => 'new-release-reference', 'provider_transfer_code' => 'TRF_NEW']);

        return Http::response(['status' => true, 'data' => ['status' => 'failed']]);
    }]);
    app(CommunityPayouts::class)->finalize($tenant, $user, $payout, '123456');
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING)->and($payout->fresh()->provider_reference)->toBe('new-release-reference');
});
