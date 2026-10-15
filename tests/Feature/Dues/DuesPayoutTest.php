<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\DuesCharge;
use App\Models\Event;
use App\Models\EventPayout;
use App\Models\LedgerTransaction;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Dues\DuesBalance;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

/**
 * A tenant with dues switched on, one paid dues charge of $gross (less a 100
 * platform fee) on its books and a verified payout account.
 *
 * @return array{0: Tenant, 1: User, 2: TenantPayoutAccount}
 */
function duesPayoutTenantWithBalance(int $gross = 10000): array
{
    [$tenant, $user] = eventHost('acme');
    Artisan::call('dues:enable', ['tenant' => $tenant->slug]);

    $member = Member::factory()->create(['tenant_id' => $tenant->id]);
    $charge = DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $member->membership_type_id, 'amount' => $gross]);
    app(LedgerService::class)->recordDuesPayment($tenant, $charge, $gross, 100, 'ref_seed');
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'recipient_code' => 'RCP_dues_1']);

    return [$tenant->fresh(), $user, $account];
}

/**
 * A scheduled dues payout for the tenant.
 */
function duesPayoutScheduled(Tenant $tenant, TenantPayoutAccount $account, int $amount = 5000, string $status = EventPayout::STATUS_SCHEDULED): EventPayout
{
    return EventPayout::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues,
        'payout_account_id' => $account->id, 'amount' => $amount, 'currency' => 'GHS', 'status' => $status,
    ]);
}

test('available balance is the payable less payouts still in flight', function () {
    [$tenant, , $account] = duesPayoutTenantWithBalance(10000);
    duesPayoutScheduled($tenant, $account, 3000);

    expect(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(6900);
});

test('payouts awaiting a code or processing also hold the balance, failed and paid ones do not', function () {
    [$tenant, , $account] = duesPayoutTenantWithBalance(10000);
    duesPayoutScheduled($tenant, $account, 1000, EventPayout::STATUS_AWAITING_OTP);
    duesPayoutScheduled($tenant, $account, 2000, EventPayout::STATUS_PROCESSING);
    duesPayoutScheduled($tenant, $account, 4000, EventPayout::STATUS_FAILED);

    expect(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(6900)
        ->and(app(DuesBalance::class)->available($tenant, 'NGN'))->toBe(0);
});

test('available balance never goes below zero', function () {
    [$tenant, , $account] = duesPayoutTenantWithBalance(10000);
    duesPayoutScheduled($tenant, $account, 9900);
    app(LedgerService::class)->recordDuesPayout($tenant, 9900, 'payout_seed_1', duesPayoutScheduled($tenant, $account, 1, EventPayout::STATUS_PAID), 'GHS');

    expect(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(0);
});

test('a payout request creates a dues payout with no event', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 5000, 'currency' => 'GHS'])
        ->assertCreated()
        ->assertJsonStructure(['id']);

    $payout = EventPayout::where('tenant_id', $tenant->id)->firstOrFail();
    expect($payout->category)->toBe(LedgerCategoryEnum::Dues)
        ->and($payout->event_id)->toBeNull()
        ->and($payout->currency)->toBe('GHS')
        ->and($payout->status)->toBe(EventPayout::STATUS_SCHEDULED);
});

test('a payout above the available balance is refused', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 9901, 'currency' => 'GHS'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Requested amount (9901) exceeds the available dues balance (9900).');

    expect(EventPayout::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a second request cannot spend what the first one reserved', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $post = fn (int $amount) => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => $amount, 'currency' => 'GHS']);

    $post(6000)->assertCreated();
    $post(6000)->assertStatus(422);

    expect(EventPayout::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a payout request locks the dues payable account so concurrent requests queue', function () {
    [, $user, $account] = duesPayoutTenantWithBalance(10000);
    $lockingQueries = [];
    DB::listen(function ($query) use (&$lockingQueries): void {
        if (str_contains($query->sql, 'ledger_accounts') && str_contains(mb_strtolower($query->sql), 'for update')) {
            $lockingQueries[] = $query->sql;
        }
    });

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 1000, 'currency' => 'ghs'])
        ->assertCreated();

    expect($lockingQueries)->toHaveCount(1);
});

test("another tenant's payout account cannot be used", function () {
    [, $user] = duesPayoutTenantWithBalance(10000);
    $foreign = TenantPayoutAccount::factory()->create(['is_verified' => true]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $foreign->id, 'amount' => 1000, 'currency' => 'GHS'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payout_account_id']);
});

test('a payout request validates amount and currency', function () {
    [, $user, $account] = duesPayoutTenantWithBalance(10000);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 0, 'currency' => 'GH'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount', 'currency']);
});

test('a user without manage payouts cannot request one', function () {
    [$tenant, , $account] = duesPayoutTenantWithBalance(10000);
    $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($viewer->id);

    $this->actingAs($viewer)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 1000, 'currency' => 'GHS'])
        ->assertForbidden();
});

test('a tenant without dues gets 404 on the dues payout pages', function () {
    [$tenant, $user] = eventHost('acme');

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.dues.payouts.index', ['subdomain' => 'acme']))
        ->assertNotFound();
});

test('the dues payouts page lists balances, accounts and dues payouts only', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $duesPayout = duesPayoutScheduled($tenant, $account, 3000);
    EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => Event::factory()->create(['tenant_id' => $tenant->id])->id, 'payout_account_id' => $account->id]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.dues.payouts.index', ['subdomain' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Dues/Payouts')
            ->where('balances.0.currency', 'GHS')
            ->where('balances.0.payable', 9900)
            ->where('balances.0.available', 6900)
            ->where('accounts.0.id', $account->id)
            ->where('canManagePaymentSettings', true)
            ->has('payouts', 1)
            ->where('payouts.0.id', $duesPayout->id));
});

test('a dues payout is sent through the shared payout sender', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $payout = duesPayoutScheduled($tenant, $account, 5000);

    Http::fake([
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_dues_1', 'status' => 'pending']]),
    ]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.send', ['subdomain' => 'acme', 'payout' => $payout->id]))
        ->assertOk()
        ->assertExactJson(['message' => 'Payout sent.', 'transfer_code' => 'TRF_dues_1']);

    $payout->refresh();
    expect($payout->status)->toBe(EventPayout::STATUS_PROCESSING)
        ->and($payout->provider_reference)->toStartWith('payout_'.$payout->id.'_')
        ->and($payout->provider_transfer_code)->toBe('TRF_dues_1');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/transfer' && $request['currency'] === 'GHS');
});

test('a failed dues payout cannot be re-sent once its money has been reserved by another payout', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $failed = duesPayoutScheduled($tenant, $account, 9900, EventPayout::STATUS_FAILED);
    duesPayoutScheduled($tenant, $account, 9900);
    Http::fake();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.send', ['subdomain' => 'acme', 'payout' => $failed->id]))
        ->assertStatus(422)
        ->assertExactJson(['message' => 'This payout (9900) is more than the available dues balance (0), so it cannot be sent again.']);

    Http::assertNothingSent();
    expect($failed->fresh()->status)->toBe(EventPayout::STATUS_FAILED);
});

test('a failed dues payout the balance still covers can be re-sent', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $failed = duesPayoutScheduled($tenant, $account, 9900, EventPayout::STATUS_FAILED);
    Http::fake([
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_dues_retry', 'status' => 'pending']]),
    ]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.send', ['subdomain' => 'acme', 'payout' => $failed->id]))
        ->assertOk()
        ->assertExactJson(['message' => 'Payout sent.', 'transfer_code' => 'TRF_dues_retry']);

    expect($failed->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING);
});

test('a payout cannot be requested in a currency the organisation holds no dues in', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.store', ['subdomain' => 'acme']), ['payout_account_id' => $account->id, 'amount' => 1000, 'currency' => 'XYZ'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['currency' => 'No dues have been collected in that currency.']);

    expect(App\Models\LedgerAccount::on('landlord')->where('tenant_id', $tenant->id)->where('currency', 'XYZ')->exists())->toBeFalse();
});

test('an event payout cannot be sent through the dues route', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $eventPayout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => Event::factory()->create(['tenant_id' => $tenant->id])->id, 'payout_account_id' => $account->id]);
    Http::fake();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.send', ['subdomain' => 'acme', 'payout' => $eventPayout->id]))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('a dues payout held for a one-time code is released through finalize', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $payout = duesPayoutScheduled($tenant, $account, 5000, EventPayout::STATUS_AWAITING_OTP);
    $payout->update(['provider_transfer_code' => 'TRF_dues_otp', 'provider_reference' => 'payout_dues_otp']);

    Http::fake([
        'api.paystack.co/transfer/finalize_transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_dues_otp', 'status' => 'pending']]),
    ]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.finalize', ['subdomain' => 'acme', 'payout' => $payout->id]), ['otp' => '123456'])
        ->assertOk()
        ->assertExactJson(['message' => 'Payout released.', 'status' => EventPayout::STATUS_PROCESSING]);

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING);
});

test('a confirmed transfer for a dues payout marks it paid and posts the dues payout once', function () {
    [$tenant, , $account] = duesPayoutTenantWithBalance(10000);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues,
        'payout_account_id' => $account->id, 'amount' => 9900, 'currency' => 'GHS',
        'status' => EventPayout::STATUS_PROCESSING, 'provider_reference' => 'payout_x_1',
    ]);
    $body = json_encode(['event' => 'transfer.success', 'data' => ['reference' => 'payout_x_1']]);
    $signature = hash_hmac('sha512', $body, 'sk_settlement_test_123');

    $send = fn () => $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
    $send()->assertOk();
    $send()->assertOk();

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->where('reference', 'payout_x_1')->where('transaction_type', LedgerTransaction::TYPE_PAYOUT)->count())->toBe(1)
        ->and(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(0);
});

test('a confirmed transfer for an event-less payout of another category is left alone', function () {
    [$tenant, , $account] = duesPayoutTenantWithBalance(10000);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::CommissionCredit,
        'payout_account_id' => $account->id, 'amount' => 500, 'currency' => 'GHS',
        'status' => EventPayout::STATUS_PROCESSING, 'provider_reference' => 'payout_cc_1',
    ]);
    $body = json_encode(['event' => 'transfer.success', 'data' => ['reference' => 'payout_cc_1']]);
    $signature = hash_hmac('sha512', $body, 'sk_settlement_test_123');

    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID)
        ->and(LedgerTransaction::where('tenant_id', $tenant->id)->where('reference', 'payout_cc_1')->count())->toBe(0);
});

test('the payouts page tells the client whether the user may request and send payouts', function () {
    [$tenant, $admin] = duesPayoutTenantWithBalance(10000);
    $reader = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($reader->id);
    $reader->givePermissionTo('read finance');
    $page = fn (User $user) => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.dues.payouts.index', ['subdomain' => 'acme']));

    $page($admin)->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManagePayouts', true)->etc());
    $page($reader)->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManagePayouts', false)->etc());
});

test('a dues-only tenant with no events can add a payout account and then see it on the payouts page', function () {
    Http::fake(['api.paystack.co/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_name' => 'Union Treasurer']])]);
    [$tenant, $user] = eventHost('acme');
    Artisan::call('dues:enable', ['tenant' => $tenant->slug]);
    $host = eventSubdomainHost('acme');

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])
        ->postJson(route('tenant.payout-accounts.store', ['subdomain' => 'acme']), [
            'type' => 'bank', 'label' => 'Union current', 'account_name' => 'Union Ltd', 'account_number' => '1234567894417', 'bank_code' => '030',
        ])
        ->assertCreated()
        ->assertJsonPath('is_verified', true);

    expect(Event::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])
        ->get(route('tenant.dues.payouts.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page->has('accounts', 1)->where('accounts.0.label', 'Union current'));
});

test('a dues payout whose code expired is failed, frees the balance and can be sent again', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $payout = duesPayoutScheduled($tenant, $account, 900, EventPayout::STATUS_AWAITING_OTP);
    $payout->update(['provider_transfer_code' => 'TRF_dues_otp', 'provider_reference' => 'payout_dues_expired']);
    expect(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(9000);

    Http::fake([
        'api.paystack.co/transfer/finalize_transfer' => Http::response(['status' => false, 'message' => 'Transfer is not currently awaiting OTP'], 400),
        'api.paystack.co/transfer/verify/payout_dues_expired' => Http::response(['status' => true, 'data' => ['status' => 'abandoned']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_dues_again', 'status' => 'pending']]),
    ]);
    $console = fn () => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')]);

    $console()->postJson(route('tenant.dues.payouts.finalize', ['subdomain' => 'acme', 'payout' => $payout->id]), ['otp' => '123456'])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'The one-time code expired before it was entered. Send the payout again.']);

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_FAILED)
        ->and(app(DuesBalance::class)->available($tenant, 'GHS'))->toBe(9900);

    $console()->postJson(route('tenant.dues.payouts.send', ['subdomain' => 'acme', 'payout' => $payout->id]))
        ->assertOk()
        ->assertJsonPath('transfer_code', 'TRF_dues_again');
    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PROCESSING);
});

test('a payout the provider refuses to send shows the provider\'s own message', function () {
    [$tenant, $user, $account] = duesPayoutTenantWithBalance(10000);
    $payout = duesPayoutScheduled($tenant, $account, 5000);
    Http::fake([
        'api.paystack.co/transfer' => Http::response(['status' => false, 'message' => 'Velocity alarm triggered. Please try again'], 400),
    ]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.payouts.send', ['subdomain' => 'acme', 'payout' => $payout->id]))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'Could not send payout: Velocity alarm triggered. Please try again']);
});
