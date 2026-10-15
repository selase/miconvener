<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Dues\DuesFee;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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

if (! function_exists('payPageFixture')) {
    /**
     * An organisation with dues switched on and one member.
     *
     * @param  array<string, mixed>  $tenantAttributes
     * @return array{0: Tenant, 1: Member, 2: string}
     */
    function payPageFixture(string $slug = 'acme', array $tenantAttributes = []): array
    {
        [$tenant] = eventHost($slug);
        $tenant->update($tenantAttributes);
        Artisan::call('dues:enable', ['tenant' => $tenant->slug]);

        $member = Member::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ama Mensah', 'email' => 'ama@example.com']);

        return [$tenant->fresh(), $member, eventSubdomainHost($slug)];
    }
}

if (! function_exists('payPageCharge')) {
    /**
     * @param  array<string, mixed>  $attributes
     */
    function payPageCharge(Member $member, int $amount, string $periodStart = '2026-09-01', array $attributes = []): DuesCharge
    {
        $start = Carbon\CarbonImmutable::parse($periodStart);

        return DuesCharge::factory()->create([
            'tenant_id' => $member->tenant_id,
            'member_id' => $member->id,
            'membership_type_id' => $member->membership_type_id,
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'due_on' => $start->toDateString(),
            'amount' => $amount,
            'currency' => 'GHS',
            ...$attributes,
        ]);
    }
}

if (! function_exists('payPageFakePaystack')) {
    function payPageFakePaystack(): void
    {
        Http::fake([
            'api.paystack.co/subaccount/*' => Http::response(['status' => true, 'data' => ['active' => true, 'account_number' => '0123456789']]),
            'api.paystack.co/customer/CUS_1' => Http::response(['data' => ['email' => 'ama@example.com']]),
            'api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_1']]),
            'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/dues']]),
        ]);
    }
}

if (! function_exists('payPageInitializePayload')) {
    /**
     * @return array<string, mixed>|null
     */
    function payPageInitializePayload(): ?array
    {
        $sent = Http::recorded(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize'));

        return $sent->isEmpty() ? null : $sent->first()[0]->data();
    }
}

test('a valid link shows the member their open charges and nothing about other members', function () {
    [$tenant, $member, $host] = payPageFixture();
    $open = payPageCharge($member, 10000, '2026-09-01');
    payPageCharge($member, 10000, '2026-08-01', ['status' => DuesChargeStatusEnum::Paid, 'paid_at' => now()]);

    $other = Member::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Kofi Boateng']);
    $othersCharge = payPageCharge($other, 7000, '2026-09-01');

    $response = $this->get("http://{$host}/pay/{$member->pay_token}");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/MemberPay')
            ->where('organisation.name', $tenant->name)
            ->where('member.name', 'Ama Mensah')
            ->where('member.first_name', 'Ama')
            ->missing('member.email')
            ->missing('member.phone')
            ->missing('member.pay_token')
            ->has('charges', 1)
            ->where('charges.0.id', $open->id)
            ->where('charges.0.amount', 10000)
            ->where('charges.0.currency', 'GHS')
            ->where('canPayOnline', true)
            ->etc());

    expect($response->getContent())->not->toContain('Kofi Boateng')
        ->and($response->getContent())->not->toContain($othersCharge->id)
        ->and($response->getContent())->not->toContain('ama@example.com');
});

test('an unknown token and a rotated token both return the same 404', function () {
    [, $member, $host] = payPageFixture();
    $oldToken = $member->pay_token;
    $member->rotatePayToken();

    $rotated = $this->get("http://{$host}/pay/{$oldToken}");
    $unknown = $this->get("http://{$host}/pay/".str_repeat('x', 40));

    $rotated->assertNotFound();
    $unknown->assertNotFound();
    expect($rotated->getContent())->toBe($unknown->getContent());
});

test('a token from another organisation\'s domain returns 404', function () {
    payPageFixture('acme');
    [, $foreignMember] = payPageFixture('globex');
    payPageCharge($foreignMember, 10000);

    $this->get('http://'.eventSubdomainHost('acme')."/pay/{$foreignMember->pay_token}")->assertNotFound();
    $this->post('http://'.eventSubdomainHost('acme')."/pay/{$foreignMember->pay_token}/checkout", ['charge_ids' => []])->assertNotFound();
});

test('a pay link of an organisation without dues switched on returns the same 404', function () {
    [$tenant, $member, $host] = payPageFixture();
    $charge = payPageCharge($member, 10000);
    $tenant->features()->where('feature_key', 'member_dues')->update(['enabled' => false]);
    payPageFakePaystack();

    $disabled = $this->get("http://{$host}/pay/{$member->pay_token}");
    $unknown = $this->get("http://{$host}/pay/".str_repeat('x', 40));

    $disabled->assertNotFound();
    expect($disabled->getContent())->toBe($unknown->getContent());
    $this->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$charge->id]])->assertNotFound();
    Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize'));
});

test('an exited member can still see and pay their open charges', function () {
    [, $member, $host] = payPageFixture();
    $member->update(['status' => MemberStatusEnum::Exited, 'exited_on' => now()->toDateString()]);
    $charge = payPageCharge($member, 10000);
    payPageFakePaystack();

    $this->get("http://{$host}/pay/{$member->pay_token}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('charges', 1)->etc());

    $this->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$charge->id]])
        ->assertRedirect('https://checkout.paystack.com/dues');
});

test('checkout asks Paystack for the total and the summed commission share', function () {
    [$tenant, $member, $host] = payPageFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_tenant',
        'is_active' => true,
        'meta' => ['commission_split' => ['subaccount_code' => 'ACCT_abc123', 'ok' => true, 'reason' => null]],
    ]);
    $first = payPageCharge($member, 10000, '2026-08-01');
    $second = payPageCharge($member, 5050, '2026-09-01');
    payPageFakePaystack();

    $this->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$first->id, $second->id]])
        ->assertRedirect('https://checkout.paystack.com/dues');

    $fee = app(DuesFee::class);
    $commission = $fee->for(10000) + $fee->for(5050);
    $payload = payPageInitializePayload();

    expect($commission)->toBe(151)
        ->and($payload['amount'])->toBe(15050)
        ->and($payload['currency'])->toBe('GHS')
        ->and($payload['subaccount'])->toBe('ACCT_abc123')
        ->and($payload['transaction_charge'])->toBe(15050 - $commission)
        ->and($payload['metadata']['type'])->toBe('member_dues')
        ->and($payload['metadata']['tenant_id'])->toBe($tenant->id)
        ->and($payload['metadata']['member_id'])->toBe($member->id)
        ->and($payload['metadata']['charge_ids'])->toEqualCanonicalizing([$first->id, $second->id])
        ->and($payload['metadata']['source'])->toBe(config('services.paystack.metadata_source'))
        ->and($payload['metadata']['commission_split'])->toBe('ACCT_abc123')
        ->and($payload['metadata']['commission_credit_applied'])->toBe(0)
        ->and($payload['callback_url'])->toContain("/pay/{$member->pay_token}/callback");
});

test('a platform-default organisation checks out on the platform account with no split', function () {
    [, $member, $host] = payPageFixture();
    $charge = payPageCharge($member, 10000);
    payPageFakePaystack();

    $this->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$charge->id]])
        ->assertRedirect('https://checkout.paystack.com/dues');

    $payload = payPageInitializePayload();

    expect($payload['amount'])->toBe(10000)
        ->and($payload)->not->toHaveKey('subaccount')
        ->and($payload['metadata']['commission_split'])->toBeNull();
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize')
        && $request->hasHeader('Authorization', 'Bearer sk_settlement_test_123'));
});

test('a charge that is not open cannot be sent to checkout', function () {
    [$tenant, $member, $host] = payPageFixture();
    $paid = payPageCharge($member, 10000, '2026-08-01', ['status' => DuesChargeStatusEnum::Paid, 'paid_at' => now()]);
    $open = payPageCharge($member, 10000, '2026-09-01');
    $other = Member::factory()->create(['tenant_id' => $tenant->id]);
    $othersCharge = payPageCharge($other, 10000);
    payPageFakePaystack();

    $this->from("http://{$host}/pay/{$member->pay_token}")
        ->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$open->id, $paid->id]])
        ->assertRedirect("http://{$host}/pay/{$member->pay_token}")
        ->assertSessionHas('error');

    $this->from("http://{$host}/pay/{$member->pay_token}")
        ->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$othersCharge->id]])
        ->assertSessionHas('error');

    $this->from("http://{$host}/pay/{$member->pay_token}")
        ->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => []])
        ->assertSessionHasErrors(['charge_ids' => 'Choose at least one charge to pay.']);

    Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize'));
});

test('checkout is throttled', function () {
    [, $member, $host] = payPageFixture();

    for ($attempt = 1; $attempt <= 30; $attempt++) {
        $this->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => []])->assertRedirect();
    }

    $this->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => []])->assertStatus(429);
});

test('a stripe-only organisation sees card payments are not available', function () {
    [$tenant, $member, $host] = payPageFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'stripe',
        'provider_account_id' => 'acct_123',
        'is_active' => true,
    ]);
    $charge = payPageCharge($member, 10000);
    payPageFakePaystack();

    $this->get("http://{$host}/pay/{$member->pay_token}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canPayOnline', false)->etc());

    $this->from("http://{$host}/pay/{$member->pay_token}")
        ->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$charge->id]])
        ->assertSessionHas('error', 'Card payments are not available for this organisation yet.');

    Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize'));
});

test('an organisation with no way to take payments says so at checkout', function () {
    [, $member, $host] = payPageFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $charge = payPageCharge($member, 10000);
    payPageFakePaystack();

    $this->get("http://{$host}/pay/{$member->pay_token}")
        ->assertInertia(fn (Assert $page) => $page->where('canPayOnline', false)->etc());

    $this->from("http://{$host}/pay/{$member->pay_token}")
        ->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$charge->id]])
        ->assertSessionHas('error', 'Online payments are not currently configured for this organisation.');
});

test('the receipt lists the member\'s paid charges, most recent first', function () {
    [, $member, $host] = payPageFixture();
    $older = payPageCharge($member, 10000, '2026-08-01', ['status' => DuesChargeStatusEnum::Paid, 'paid_at' => now()->subMonth(), 'payment_method' => 'online']);
    $newer = payPageCharge($member, 10000, '2026-09-01', ['status' => DuesChargeStatusEnum::Paid, 'paid_at' => now(), 'payment_method' => 'online']);
    payPageCharge($member, 10000, '2026-10-01');

    $this->get("http://{$host}/pay/{$member->pay_token}/receipt")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/MemberPay')
            ->where('mode', 'receipt')
            ->has('paidCharges', 2)
            ->where('paidCharges.0.id', $newer->id)
            ->where('paidCharges.1.id', $older->id)
            ->etc());
});

test('an own-gateway organisation whose commission split is not set up cannot start a checkout', function () {
    [$tenant, $member, $host] = payPageFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_tenant',
        'is_active' => true,
        'meta' => [],
    ]);
    $charge = payPageCharge($member, 10000);
    payPageFakePaystack();

    $this->from("http://{$host}/pay/{$member->pay_token}")
        ->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$charge->id]])
        ->assertSessionHas('error', 'Online payment is not available right now. Please try again later or contact the organisation.');

    Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'transaction/initialize'));
});

test('the pay page, callback and receipt share a loose per-address rate limit', function () {
    [, $member, $host] = payPageFixture();

    for ($attempt = 1; $attempt <= 120; $attempt++) {
        $this->get("http://{$host}/pay/{$member->pay_token}/receipt")->assertOk();
    }

    $this->get("http://{$host}/pay/{$member->pay_token}")->assertStatus(429);
    $this->get("http://{$host}/pay/{$member->pay_token}/receipt")->assertStatus(429);
    $this->get("http://{$host}/pay/{$member->pay_token}/callback?reference=ref_x")->assertStatus(429);
});

test('a checkout that cannot start returns the member to their pay page', function () {
    [, $member, $host] = payPageFixture('acme', ['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $charge = payPageCharge($member, 10000);
    payPageFakePaystack();

    $this->from('https://elsewhere.example.com/page')
        ->post("http://{$host}/pay/{$member->pay_token}/checkout", ['charge_ids' => [$charge->id]])
        ->assertRedirect(route('public.dues.show', ['subdomain' => 'acme', 'token' => $member->pay_token]))
        ->assertSessionHas('error', 'Online payments are not currently configured for this organisation.');
});
