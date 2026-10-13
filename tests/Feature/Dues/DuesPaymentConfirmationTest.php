<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\LedgerCategoryEnum;
use App\Mail\DuesReceipt;
use App\Models\DuesCharge;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

if (! function_exists('duesConfirmFixture')) {
    /**
     * An organisation with dues switched on and one member with an email.
     *
     * @param  array<string, mixed>  $tenantAttributes
     * @return array{0: Tenant, 1: Member}
     */
    function duesConfirmFixture(string $slug = 'acme', array $tenantAttributes = []): array
    {
        [$tenant] = eventHost($slug);
        $tenant->update($tenantAttributes);
        Artisan::call('dues:enable', ['tenant' => $tenant->slug]);

        $member = Member::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ama Mensah', 'email' => 'ama@example.com']);

        return [$tenant->fresh(), $member];
    }
}

if (! function_exists('duesConfirmCharge')) {
    /**
     * @param  array<string, mixed>  $attributes
     */
    function duesConfirmCharge(Member $member, int $amount, string $periodStart = '2026-09-01', array $attributes = []): DuesCharge
    {
        $start = Carbon\CarbonImmutable::parse($periodStart);

        return DuesCharge::factory()->create([
            'tenant_id' => $member->tenant_id,
            'member_id' => $member->id,
            'dues_class_id' => $member->dues_class_id,
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'due_on' => $start->toDateString(),
            'amount' => $amount,
            'currency' => 'GHS',
            ...$attributes,
        ]);
    }
}

if (! function_exists('duesConfirmMetadata')) {
    /**
     * @param  list<string>  $chargeIds
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    function duesConfirmMetadata(Member $member, array $chargeIds, array $extra = []): array
    {
        return [
            'type' => 'member_dues',
            'tenant_id' => $member->tenant_id,
            'member_id' => $member->id,
            'charge_ids' => $chargeIds,
            'source' => config('services.paystack.metadata_source'),
            'commission_split' => null,
            'commission_credit_applied' => 0,
            ...$extra,
        ];
    }
}

if (! function_exists('duesSettlementWebhook')) {
    /**
     * Delivers a charge.success to the platform's settlement webhook, signed
     * with the settlement key unless another signature is given.
     *
     * @param  array<string, mixed>  $metadata
     */
    function duesSettlementWebhook(array $metadata, int $amount, int $fees, string $reference = 'ref_dues_1', ?string $signature = null): TestResponse
    {
        $body = json_encode(['event' => 'charge.success', 'data' => [
            'reference' => $reference,
            'amount' => $amount,
            'fees' => $fees,
            'currency' => 'GHS',
            'customer' => ['email' => 'ama@example.com'],
            'metadata' => $metadata,
        ]]);

        return test()->call('POST', '/webhooks/settlement/paystack', [], [], [], [
            'HTTP_x-paystack-signature' => $signature ?? hash_hmac('sha512', $body, 'sk_settlement_test_123'),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}

if (! function_exists('duesMerchantWebhook')) {
    /**
     * Delivers a charge.success to an organisation's own-gateway webhook,
     * signed with that organisation's secret key.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $extra
     */
    function duesMerchantWebhook(Tenant $tenant, array $metadata, int $amount, int $fees, array $extra = [], string $secret = 'sk_test_tenant'): TestResponse
    {
        $body = json_encode(['event' => 'charge.success', 'data' => [
            'reference' => 'ref_own_1',
            'amount' => $amount,
            'fees' => $fees,
            'currency' => 'GHS',
            'customer' => ['email' => 'ama@example.com', 'first_name' => 'Ama', 'last_name' => 'Mensah'],
            'metadata' => $metadata,
            ...$extra,
        ]]);

        return test()->call('POST', "/webhooks/merchant/paystack/{$tenant->id}", [], [], [], [
            'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}

if (! function_exists('duesConfirmPayable')) {
    function duesConfirmPayable(string $tenantId): int
    {
        $account = LedgerAccount::where('tenant_id', $tenantId)->where('category', LedgerCategoryEnum::Dues)
            ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)->first();

        return $account?->currentBalance() ?? 0;
    }
}

if (! function_exists('duesConfirmAssertBalanced')) {
    function duesConfirmAssertBalanced(string $tenantId): void
    {
        LedgerTransaction::where('tenant_id', $tenantId)->get()->each(function (LedgerTransaction $tx): void {
            $debits = (int) LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'debit')->sum('amount');
            $credits = (int) LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'credit')->sum('amount');

            expect($debits)->toBe($credits);
        });
    }
}

if (! function_exists('duesOwnGateway')) {
    function duesOwnGateway(Tenant $tenant): TenantPaymentGateway
    {
        return TenantPaymentGateway::factory()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'paystack',
            'api_key_encrypted' => 'sk_test_tenant',
            'is_active' => true,
            'meta' => ['commission_split' => ['subaccount_code' => 'ACCT_abc123', 'ok' => true, 'reason' => null]],
        ]);
    }
}

test('a successful charge event pays the charge, posts the ledger and queues a receipt', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000);

    duesSettlementWebhook(duesConfirmMetadata($member, [$charge->id]), 10000, 150)->assertOk();

    $charge->refresh();
    expect($charge->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and($charge->payment_method)->toBe('online')
        ->and($charge->payment_reference)->toBe('ref_dues_1')
        ->and($charge->paid_at)->not->toBeNull()
        ->and($charge->charged_amount)->toBe(10000)
        ->and($charge->gateway_fee_amount)->toBe(150)
        ->and($charge->commission_amount)->toBe(100);

    $tx = LedgerTransaction::where('tenant_id', $tenant->id)->where('reference', "ref_dues_1:{$charge->id}")->first();
    expect($tx)->not->toBeNull()
        ->and($tx->transaction_type)->toBe(LedgerTransaction::TYPE_DUES_PAYMENT)
        ->and($tx->category)->toBe(LedgerCategoryEnum::Dues)
        ->and(duesConfirmPayable($tenant->id))->toBe(10000 - 150 - 100);
    duesConfirmAssertBalanced($tenant->id);

    Mail::assertQueued(DuesReceipt::class, fn (DuesReceipt $mail): bool => $mail->hasTo('ama@example.com')
        && $mail->reference === 'ref_dues_1'
        && count($mail->charges) === 1);
});

test('redelivering the same webhook changes nothing', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000);
    $metadata = duesConfirmMetadata($member, [$charge->id]);

    duesSettlementWebhook($metadata, 10000, 150)->assertOk();
    $paidAt = $charge->fresh()->paid_at;
    $this->travel(5)->minutes();
    duesSettlementWebhook($metadata, 10000, 150)->assertOk();

    expect(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and($charge->fresh()->paid_at->equalTo($paidAt))->toBeTrue()
        ->and($charge->fresh()->note)->toBeNull()
        ->and(duesConfirmPayable($tenant->id))->toBe(9750);
    Mail::assertQueued(DuesReceipt::class, 1);
});

test('paying two charges at once splits the gateway fee in proportion and balances to the pesewa', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $first = duesConfirmCharge($member, 10000, '2026-08-01');
    $second = duesConfirmCharge($member, 5050, '2026-09-01');

    duesSettlementWebhook(duesConfirmMetadata($member, [$first->id, $second->id]), 15050, 151)->assertOk();

    $first->refresh();
    $second->refresh();
    expect($first->gateway_fee_amount + $second->gateway_fee_amount)->toBe(151)
        ->and($first->gateway_fee_amount)->toBe(100)
        ->and($second->gateway_fee_amount)->toBe(51)
        ->and($first->commission_amount)->toBe(100)
        ->and($second->commission_amount)->toBe(51)
        ->and($first->charged_amount + $second->charged_amount)->toBe(15050)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(duesConfirmPayable($tenant->id))->toBe(15050 - 151 - (100 + 51));
    duesConfirmAssertBalanced($tenant->id);
    Mail::assertQueued(DuesReceipt::class, fn (DuesReceipt $mail): bool => count($mail->charges) === 2);
});

test('a payment for a charge already paid is flagged and posts nothing', function () {
    Mail::fake();
    Log::spy();
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000, '2026-09-01', ['status' => DuesChargeStatusEnum::Paid, 'payment_method' => 'offline', 'paid_at' => now()]);

    duesSettlementWebhook(duesConfirmMetadata($member, [$charge->id]), 10000, 150)->assertOk();
    duesSettlementWebhook(duesConfirmMetadata($member, [$charge->id]), 10000, 150)->assertOk();

    expect(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and($charge->fresh()->payment_method)->toBe('offline')
        ->and($charge->fresh()->note)->toContain('received again')
        ->and(mb_substr_count((string) $charge->fresh()->note, 'ref_dues_1'))->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'dues.payment.unmatched'
        && $context['charge_id'] === $charge->id && $context['reference'] === 'ref_dues_1')->once();
    Mail::assertNothingQueued();
});

test('a payment for a voided charge is flagged and posts nothing', function () {
    Mail::fake();
    Log::spy();
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000, '2026-09-01', ['status' => DuesChargeStatusEnum::Void]);

    duesSettlementWebhook(duesConfirmMetadata($member, [$charge->id]), 10000, 150)->assertOk();

    expect(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and($charge->fresh()->status)->toBe(DuesChargeStatusEnum::Void)
        ->and($charge->fresh()->note)->toBe('Payment ref_dues_1 received again after this charge was void.');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'dues.payment.unmatched')->once();
    Mail::assertNothingQueued();
});

test('a payment covering an open and an already-paid charge pays the open one only', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $paid = duesConfirmCharge($member, 10000, '2026-08-01', ['status' => DuesChargeStatusEnum::Paid, 'payment_method' => 'offline', 'paid_at' => now()]);
    $open = duesConfirmCharge($member, 5050, '2026-09-01');

    duesSettlementWebhook(duesConfirmMetadata($member, [$paid->id, $open->id]), 15050, 151)->assertOk();

    expect($open->fresh()->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and($open->fresh()->gateway_fee_amount)->toBe(151)
        ->and(duesConfirmPayable($tenant->id))->toBe(5050 - 151 - 51)
        ->and($paid->fresh()->note)->toContain('received again')
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(1);
    duesConfirmAssertBalanced($tenant->id);
});

test('a forged charge list cannot pay another member\'s charge', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $other = Member::factory()->create(['tenant_id' => $tenant->id]);
    $othersCharge = duesConfirmCharge($other, 10000);
    $own = duesConfirmCharge($member, 10000);

    duesSettlementWebhook(duesConfirmMetadata($member, [$othersCharge->id, $own->id]), 20000, 300)->assertOk();

    expect($othersCharge->fresh()->status)->toBe(DuesChargeStatusEnum::Open)
        ->and($own->fresh()->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->where('reference', "ref_dues_1:{$othersCharge->id}")->exists())->toBeFalse();
});

test('a webhook whose amount is short of the charges posts nothing', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000);

    duesSettlementWebhook(duesConfirmMetadata($member, [$charge->id]), 9000, 150)->assertOk();

    expect($charge->fresh()->status)->toBe(DuesChargeStatusEnum::Open)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('a member without an email still has their charge paid but gets no receipt', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $member->update(['email' => null]);
    $charge = duesConfirmCharge($member, 10000);

    duesSettlementWebhook(duesConfirmMetadata($member, [$charge->id]), 10000, 150)->assertOk();

    expect($charge->fresh()->status)->toBe(DuesChargeStatusEnum::Paid);
    Mail::assertNothingQueued();
});

test('an own-gateway organisation books only the commission', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    duesOwnGateway($tenant);
    $charge = duesConfirmCharge($member, 10000);

    duesMerchantWebhook($tenant, duesConfirmMetadata($member, [$charge->id], ['commission_split' => 'ACCT_abc123']), 10000, 150, ['fees_split' => ['subaccount' => 100]])
        ->assertOk();

    $charge->refresh();
    expect($charge->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and($charge->payment_reference)->toBe('ref_own_1')
        ->and($charge->commission_amount)->toBe(100)
        ->and($charge->gateway_fee_amount)->toBe(150);

    $tx = LedgerTransaction::where('tenant_id', $tenant->id)->where('reference', "ref_own_1:{$charge->id}")->firstOrFail();
    $debits = (int) LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'debit')->sum('amount');
    expect($tx->transaction_type)->toBe(LedgerTransaction::TYPE_DUES_PAYMENT)
        ->and($debits)->toBe(100)
        ->and(duesConfirmPayable($tenant->id))->toBe(0);
    duesConfirmAssertBalanced($tenant->id);
    Mail::assertQueued(DuesReceipt::class);

    duesMerchantWebhook($tenant, duesConfirmMetadata($member, [$charge->id], ['commission_split' => 'ACCT_abc123']), 10000, 150, ['fees_split' => ['subaccount' => 100]])
        ->assertOk();
    expect(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('an own-gateway payment whose commission did not reach the platform is booked as owed', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    duesOwnGateway($tenant);
    $first = duesConfirmCharge($member, 10000, '2026-08-01');
    $second = duesConfirmCharge($member, 5050, '2026-09-01');

    duesMerchantWebhook($tenant, duesConfirmMetadata($member, [$first->id, $second->id]), 15050, 151)->assertOk();

    $types = LedgerTransaction::where('tenant_id', $tenant->id)->pluck('transaction_type')->unique()->values()->all();
    expect($types)->toBe([LedgerTransaction::TYPE_COMMISSION_RECEIVABLE])
        ->and(duesConfirmPayable($tenant->id))->toBe(-151);
    duesConfirmAssertBalanced($tenant->id);
});

test('the own-gateway webhook of a platform-default organisation cannot pay dues', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    duesOwnGateway($tenant);
    $charge = duesConfirmCharge($member, 10000);

    duesMerchantWebhook($tenant, duesConfirmMetadata($member, [$charge->id]), 10000, 150)->assertOk();

    expect($charge->fresh()->status)->toBe(DuesChargeStatusEnum::Open)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('an own-gateway webhook cannot pay another organisation\'s dues', function () {
    Mail::fake();
    [$tenant] = duesConfirmFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    duesOwnGateway($tenant);
    [, $foreignMember] = duesConfirmFixture('globex', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $foreignCharge = duesConfirmCharge($foreignMember, 10000);

    duesMerchantWebhook($tenant, duesConfirmMetadata($foreignMember, [$foreignCharge->id]), 10000, 150)->assertOk();

    expect($foreignCharge->fresh()->status)->toBe(DuesChargeStatusEnum::Open);
});

test('the callback verifies with Paystack and confirms when the webhook has not arrived yet', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000);
    Http::fake([
        'api.paystack.co/transaction/verify/ref_cb_1' => Http::response(['data' => [
            'id' => 1, 'status' => 'success', 'reference' => 'ref_cb_1', 'amount' => 10000, 'fees' => 150,
            'currency' => 'GHS', 'metadata' => duesConfirmMetadata($member, [$charge->id]),
        ]]),
    ]);
    $host = eventSubdomainHost('acme');

    $this->get("http://{$host}/pay/{$member->pay_token}/callback?reference=ref_cb_1")
        ->assertRedirect(route('public.dues.receipt', ['subdomain' => 'acme', 'token' => $member->pay_token]));

    $charge->refresh();
    expect($charge->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and($charge->payment_reference)->toBe('ref_cb_1')
        ->and($charge->gateway_fee_amount)->toBe(150)
        ->and(duesConfirmPayable($tenant->id))->toBe(9750);
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk_settlement_test_123'));

    duesSettlementWebhook(duesConfirmMetadata($member, [$charge->id]), 10000, 150, 'ref_cb_1')->assertOk();
    expect(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(1);
    Mail::assertQueued(DuesReceipt::class, 1);
});

test('a callback whose verified amount is lower than the charges is refused', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000);
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response(['data' => [
            'id' => 1, 'status' => 'success', 'reference' => 'ref_cb_2', 'amount' => 5000, 'fees' => 75,
            'currency' => 'GHS', 'metadata' => duesConfirmMetadata($member, [$charge->id]),
        ]]),
    ]);
    $host = eventSubdomainHost('acme');

    $this->get("http://{$host}/pay/{$member->pay_token}/callback?reference=ref_cb_2")
        ->assertRedirect(route('public.dues.show', ['subdomain' => 'acme', 'token' => $member->pay_token]))
        ->assertSessionHas('error');

    expect($charge->fresh()->status)->toBe(DuesChargeStatusEnum::Open)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a callback for a payment made for another member is refused', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $other = Member::factory()->create(['tenant_id' => $tenant->id]);
    $othersCharge = duesConfirmCharge($other, 10000);
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response(['data' => [
            'id' => 1, 'status' => 'success', 'reference' => 'ref_cb_3', 'amount' => 10000, 'fees' => 150,
            'currency' => 'GHS', 'metadata' => duesConfirmMetadata($other, [$othersCharge->id]),
        ]]),
    ]);
    $host = eventSubdomainHost('acme');

    $this->get("http://{$host}/pay/{$member->pay_token}/callback?reference=ref_cb_3")
        ->assertRedirect(route('public.dues.show', ['subdomain' => 'acme', 'token' => $member->pay_token]))
        ->assertSessionHas('error');

    expect($othersCharge->fresh()->status)->toBe(DuesChargeStatusEnum::Open);
});

test('a callback that cannot be verified tells the member the payment is being finalised', function () {
    Mail::fake();
    [, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['message' => 'down'], 500)]);
    $host = eventSubdomainHost('acme');

    $this->get("http://{$host}/pay/{$member->pay_token}/callback?reference=ref_cb_4")
        ->assertRedirect(route('public.dues.show', ['subdomain' => 'acme', 'token' => $member->pay_token]))
        ->assertSessionHas('info', 'We are finalising your payment.');

    expect($charge->fresh()->status)->toBe(DuesChargeStatusEnum::Open);
});

test('a signature that does not verify is rejected before anything is read', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000);
    $duesQueries = [];
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$duesQueries): void {
        if (str_contains($query->sql, 'dues_charges') || str_contains($query->sql, 'ledger_')) {
            $duesQueries[] = $query->sql;
        }
    });

    duesSettlementWebhook(duesConfirmMetadata($member, [$charge->id]), 10000, 150, 'ref_dues_1', 'not-a-signature')->assertStatus(400);
    expect($duesQueries)->toBe([]);

    [$ownTenant, $ownMember] = duesConfirmFixture('globex', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    duesOwnGateway($ownTenant);
    $ownCharge = duesConfirmCharge($ownMember, 10000);
    $duesQueries = [];
    duesMerchantWebhook($ownTenant, duesConfirmMetadata($ownMember, [$ownCharge->id]), 10000, 150, [], 'sk_wrong_key')->assertStatus(400);
    expect($duesQueries)->toBe([]);

    expect($charge->fresh()->status)->toBe(DuesChargeStatusEnum::Open)
        ->and($ownCharge->fresh()->status)->toBe(DuesChargeStatusEnum::Open)
        ->and(LedgerTransaction::count())->toBe(0);
});

test('the receipt comes from the organisation by name and replies reach it', function () {
    [$tenant, $member] = duesConfirmFixture();
    $charge = duesConfirmCharge($member, 10000, '2026-09-01', ['status' => DuesChargeStatusEnum::Paid, 'paid_at' => now(), 'payment_reference' => 'ref_r']);

    $mail = new DuesReceipt($member, new Illuminate\Database\Eloquent\Collection([$charge]), 'ref_r');

    $mail->assertHasSubject("Receipt: {$tenant->name} dues");
    $mail->assertHasReplyTo($tenant->email, $tenant->name);
    $mail->assertFrom((string) config('mail.from.address'), $tenant->planAllows('white_label') ? $tenant->name : "{$tenant->name} via ".config('app.name'));
    $mail->assertSeeInHtml('ref_r');
    $mail->assertSeeInHtml('100.00');
    expect($mail)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('the callback of an own-gateway organisation verifies on its own account and books only the commission', function () {
    Mail::fake();
    [$tenant, $member] = duesConfirmFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    duesOwnGateway($tenant);
    $charge = duesConfirmCharge($member, 10000);
    Http::fake([
        'api.paystack.co/transaction/verify/ref_cb_own' => Http::response(['data' => [
            'id' => 1, 'status' => 'success', 'reference' => 'ref_cb_own', 'amount' => 10000, 'fees' => 150,
            'currency' => 'GHS', 'metadata' => duesConfirmMetadata($member, [$charge->id], ['commission_split' => 'ACCT_abc123']),
        ]]),
    ]);
    $host = eventSubdomainHost('acme');

    $this->get("http://{$host}/pay/{$member->pay_token}/callback?reference=ref_cb_own")
        ->assertRedirect(route('public.dues.receipt', ['subdomain' => 'acme', 'token' => $member->pay_token]));

    expect($charge->fresh()->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and(duesConfirmPayable($tenant->id))->toBe(0)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->value('transaction_type'))->toBe(LedgerTransaction::TYPE_DUES_PAYMENT);
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk_test_tenant'));
    duesConfirmAssertBalanced($tenant->id);
});
