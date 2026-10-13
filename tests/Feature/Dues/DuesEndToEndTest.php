<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\LedgerCategoryEnum;
use App\Mail\DuesReceipt;
use App\Models\DuesCharge;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Dues\DuesBalance;
use App\Services\Dues\DuesFee;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config([
        'services.settlement.paystack.secret_key' => 'sk_settlement_test_123',
        'services.commission_split.account_number' => '0123456789',
    ]);
});

if (! function_exists('duesE2eConsole')) {
    /**
     * Sets up dues the way an admin does, through the console endpoints:
     * switch dues on, create a class, add a member and raise one charge.
     *
     * @return array{0: Tenant, 1: User, 2: DuesCharge}
     */
    function duesE2eConsole(string $slug, int $classAmount, string $settlementMode): array
    {
        [$tenant, $user] = eventHost($slug);
        $tenant->update(['settlement_mode' => $settlementMode]);
        expect(Artisan::call('dues:enable', ['tenant' => $tenant->slug]))->toBe(0);
        $console = fn () => test()->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($slug)]);

        $classId = $console()
            ->postJson(route('tenant.dues.classes.store', ['subdomain' => $slug]), ['name' => 'Full member', 'amount' => $classAmount, 'currency' => 'GHS', 'period' => 'monthly'])
            ->assertCreated()
            ->json('id');

        $memberId = $console()
            ->postJson(route('tenant.dues.members.store', ['subdomain' => $slug]), ['name' => 'Ama Mensah', 'email' => 'ama@example.com', 'phone' => '0244000001', 'dues_class_id' => $classId])
            ->assertCreated()
            ->json('id');

        $chargeId = $console()
            ->postJson(route('tenant.dues.charges.store', ['subdomain' => $slug]), ['member_id' => $memberId, 'period_start' => '2026-09-01'])
            ->assertCreated()
            ->json('id');

        return [$tenant->fresh(), $user, DuesCharge::query()->findOrFail($chargeId)];
    }
}

if (! function_exists('duesE2eCheckout')) {
    /**
     * The member opens their pay link, sees the charge and starts a checkout.
     * Returns what was sent to Paystack's initialize endpoint, whose metadata
     * Paystack echoes back on the webhook.
     *
     * @return array<string, mixed>
     */
    function duesE2eCheckout(string $slug, DuesCharge $charge): array
    {
        $host = eventSubdomainHost($slug);
        $token = $charge->member->pay_token;

        test()->get("http://{$host}/pay/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/MemberPay')
                ->where('canPayOnline', true)
                ->has('charges', 1)
                ->where('charges.0.id', $charge->id)
                ->etc());

        test()->post("http://{$host}/pay/{$token}/checkout", ['charge_ids' => [$charge->id]])
            ->assertRedirect('https://checkout.paystack.com/dues-e2e');

        $sent = Http::recorded(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize'));
        expect($sent)->toHaveCount(1);

        return $sent->first()[0]->data();
    }
}

if (! function_exists('duesE2eSigned')) {
    /**
     * Posts a webhook body signed with $secret, as Paystack signs it.
     *
     * @param  array<string, mixed>  $payload
     */
    function duesE2eSigned(string $uri, array $payload, string $secret): TestResponse
    {
        $body = json_encode($payload);

        return test()->call('POST', $uri, [], [], [], [
            'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}

if (! function_exists('duesE2eBalance')) {
    /**
     * The tenant's dues-category balance on one ledger account.
     */
    function duesE2eBalance(string $tenantId, string $code): int
    {
        $account = LedgerAccount::where('tenant_id', $tenantId)->where('category', LedgerCategoryEnum::Dues)->where('code', $code)->first();

        return $account?->currentBalance() ?? 0;
    }
}

if (! function_exists('duesE2eAssertBalanced')) {
    function duesE2eAssertBalanced(string $tenantId): void
    {
        $transactions = LedgerTransaction::where('tenant_id', $tenantId)->get();
        expect($transactions)->not->toBeEmpty();

        $transactions->each(function (LedgerTransaction $tx): void {
            $debits = (int) LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'debit')->sum('amount');
            $credits = (int) LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'credit')->sum('amount');

            expect($debits)->toBe($credits)
                ->and($tx->category)->toBe(LedgerCategoryEnum::Dues);
        });
    }
}

test('a platform-default organisation collects a member\'s dues and pays the balance out', function () {
    Mail::fake();
    Http::fake([
        'api.paystack.co/customer/*' => Http::response(['data' => ['email' => 'ama@example.com']]),
        'api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_1']]),
        'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/dues-e2e']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_dues_e2e', 'status' => 'pending']]),
    ]);
    [$tenant, $user, $charge] = duesE2eConsole('acme', 10000, Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT);
    $console = fn () => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')]);

    $initialized = duesE2eCheckout('acme', $charge);
    expect($initialized['amount'])->toBe(10000)
        ->and($initialized)->not->toHaveKey('subaccount');
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize')
        && $request->hasHeader('Authorization', 'Bearer sk_settlement_test_123'));

    $gatewayFee = 150;
    $commission = app(DuesFee::class)->for(10000);
    $chargeEvent = ['event' => 'charge.success', 'data' => [
        'reference' => 'ref_dues_e2e',
        'amount' => 10000,
        'fees' => $gatewayFee,
        'currency' => 'GHS',
        'customer' => ['email' => 'ama@example.com'],
        'metadata' => $initialized['metadata'],
    ]];
    duesE2eSigned('/webhooks/settlement/paystack', $chargeEvent, 'sk_settlement_test_123')->assertOk();
    duesE2eSigned('/webhooks/settlement/paystack', $chargeEvent, 'sk_settlement_test_123')->assertOk();

    $charge->refresh();
    $payable = 10000 - $gatewayFee - $commission;
    expect($charge->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and($charge->payment_reference)->toBe('ref_dues_e2e')
        ->and($charge->gateway_fee_amount)->toBe($gatewayFee)
        ->and($charge->commission_amount)->toBe($commission)
        ->and(duesE2eBalance($tenant->id, LedgerAccount::CODE_ORGANIZER_PAYABLE))->toBe($payable)
        ->and(duesE2eBalance($tenant->id, LedgerAccount::CODE_PLATFORM_REVENUE))->toBe($commission)
        ->and(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe($payable);
    Mail::assertQueued(DuesReceipt::class, 1);
    Mail::assertQueued(DuesReceipt::class, fn (DuesReceipt $mail): bool => $mail->hasTo('ama@example.com') && $mail->reference === 'ref_dues_e2e');

    $this->get('http://'.eventSubdomainHost('acme')."/pay/{$charge->member->pay_token}/receipt")
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'receipt')->has('paidCharges', 1)->where('paidCharges.0.id', $charge->id)->etc());

    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'recipient_code' => 'RCP_dues_e2e']);
    $payoutId = $console()
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => $payable, 'currency' => 'GHS'])
        ->assertCreated()
        ->json('id');
    $console()
        ->postJson(route('tenant.dues.payouts.send', ['subdomain' => 'acme', 'payout' => $payoutId]))
        ->assertOk();

    $payout = EventPayout::query()->findOrFail($payoutId);
    expect($payout->status)->toBe(EventPayout::STATUS_PROCESSING)
        ->and(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(0);

    $transferEvent = ['event' => 'transfer.success', 'data' => ['reference' => $payout->provider_reference]];
    duesE2eSigned('/webhooks/settlement/paystack', $transferEvent, 'sk_settlement_test_123')->assertOk();
    duesE2eSigned('/webhooks/settlement/paystack', $transferEvent, 'sk_settlement_test_123')->assertOk();

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID)
        ->and(duesE2eBalance($tenant->id, LedgerAccount::CODE_ORGANIZER_PAYABLE))->toBe(0)
        ->and(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(0)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->where('transaction_type', LedgerTransaction::TYPE_PAYOUT)->count())->toBe(1);
    duesE2eAssertBalanced($tenant->id);

    expect(Artisan::call('finance:verify-categories'))->toBe(0);
});

test('an own-gateway organisation collects dues on its own account and the platform books only the commission', function () {
    Mail::fake();
    Http::fake([
        'api.paystack.co/subaccount/*' => Http::response(['status' => true, 'data' => ['active' => true, 'account_number' => '0123456789']]),
        'api.paystack.co/customer/*' => Http::response(['data' => ['email' => 'ama@example.com']]),
        'api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_1']]),
        'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/dues-e2e']]),
    ]);
    [$tenant, , $charge] = duesE2eConsole('acme', 10000, Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_tenant',
        'is_active' => true,
        'meta' => ['commission_split' => ['subaccount_code' => 'ACCT_abc123', 'ok' => true, 'reason' => null]],
    ]);

    $commission = app(DuesFee::class)->for(10000);
    $initialized = duesE2eCheckout('acme', $charge);
    expect($initialized['subaccount'])->toBe('ACCT_abc123')
        ->and($initialized['transaction_charge'])->toBe(10000 - $commission);
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize')
        && $request->hasHeader('Authorization', 'Bearer sk_test_tenant'));

    $chargeEvent = ['event' => 'charge.success', 'data' => [
        'reference' => 'ref_own_e2e',
        'amount' => 10000,
        'fees' => 150,
        'currency' => 'GHS',
        'customer' => ['email' => 'ama@example.com', 'first_name' => 'Ama', 'last_name' => 'Mensah'],
        'metadata' => $initialized['metadata'],
        'fees_split' => ['subaccount' => $commission],
    ]];
    duesE2eSigned("/webhooks/merchant/paystack/{$tenant->id}", $chargeEvent, 'sk_test_tenant')->assertOk();
    duesE2eSigned("/webhooks/merchant/paystack/{$tenant->id}", $chargeEvent, 'sk_test_tenant')->assertOk();

    $charge->refresh();
    expect($charge->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and($charge->payment_reference)->toBe('ref_own_e2e')
        ->and($charge->commission_amount)->toBe($commission)
        ->and(duesE2eBalance($tenant->id, LedgerAccount::CODE_ORGANIZER_PAYABLE))->toBe(0)
        ->and(duesE2eBalance($tenant->id, LedgerAccount::CODE_PLATFORM_REVENUE))->toBe($commission)
        ->and(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(0)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(1);
    Mail::assertQueued(DuesReceipt::class, 1);
    duesE2eAssertBalanced($tenant->id);

    expect(Artisan::call('finance:verify-categories'))->toBe(0);
});
