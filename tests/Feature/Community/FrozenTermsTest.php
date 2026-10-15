<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Models\DuesCharge;
use App\Models\DuesPaymentAttempt;
use App\Models\LedgerTransaction;
use App\Models\Member;
use App\Models\Package;
use App\Models\Tenant;
use App\Services\Dues\DuesCheckout;
use App\Services\Dues\DuesPaymentConfirmer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Mail::fake();
    config(['services.settlement.paystack.secret_key' => 'sk_test_terms']);
    Http::fake([
        'api.paystack.co/customer/CUS_1' => Http::response(['data' => ['email' => 'ama@example.com']]),
        'api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_1']]),
        'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/frozen']]),
    ]);
});

function frozenFixture(array $tenantAttributes = []): array
{
    $tenant = Tenant::factory()->create(['package_id' => Package::factory()->create()->id, ...$tenantAttributes]);
    if (! $tenant->isPlatformDefaultSettlement()) {
        App\Models\TenantPaymentGateway::factory()->create([
            'tenant_id' => $tenant->id, 'provider' => 'paystack', 'api_key_encrypted' => 'sk_test_own', 'is_active' => true,
            'meta' => ['commission_split' => ['subaccount_code' => 'ACCT_terms', 'ok' => true, 'reason' => null]],
        ]);
        config(['services.commission_split.account_number' => '0123456789']);
        Http::fake(['api.paystack.co/subaccount/*' => Http::response(['status' => true, 'data' => ['active' => true, 'account_number' => '0123456789']])]);
    }
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);
    $charges = collect([10000, 5050])->map(fn (int $amount, int $index): DuesCharge => DuesCharge::factory()->create([
        'tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $member->membership_type_id,
        'amount' => $amount, 'period_start' => now()->startOfMonth()->subMonths($index),
    ]));
    app(DuesCheckout::class)->start($member, $charges->pluck('id')->all(), 'https://example.com/callback');

    return [$tenant, $member, $charges, DuesPaymentAttempt::query()->latest('created_at')->firstOrFail()];
}

function frozenData(DuesPaymentAttempt $attempt): array
{
    return ['metadata' => ['type' => 'member_dues', 'source' => config('services.paystack.metadata_source'), 'dues_attempt_id' => $attempt->id, 'tenant_id' => $attempt->tenant_id, 'member_id' => $attempt->member_id,
        'charge_ids' => $attempt->charge_ids], 'amount' => 15050, 'currency' => 'GHS', 'fees' => 0, 'settled_by' => 'platform'];
}

test('checkout freezes commissions and sends its own reference and attempt id', function () {
    [, , $charges, $attempt] = frozenFixture();
    expect($attempt->rate)->toBe('1.00')->and($attempt->expected_amount)->toBe(15050)
        ->and($attempt->commissions)->toMatchArray([$charges[0]->id => 100, $charges[1]->id => 51]);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'transaction/initialize')
        && $request['reference'] === $attempt->reference && $request['metadata']['dues_attempt_id'] === $attempt->id);
});

test('confirmation uses frozen commissions after the plan rate changes and redelivery is harmless', function () {
    [$tenant, , $charges, $attempt] = frozenFixture();
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    $admin = App\Models\User::factory()->create(['tenant_id' => null]);
    $admin->assignRole('Superadmin');
    app(App\Services\Finance\CommercialTerms::class)->setOverride($tenant, App\Enum\CommercialStreamEnum::Dues, 3.0, null, 'New agreement', $admin);
    expect(app(App\Services\Finance\CommercialTerms::class)->for($tenant, App\Enum\CommercialStreamEnum::Dues)->rate)->toBe(3.0);
    $confirmer = app(DuesPaymentConfirmer::class);
    expect($confirmer->confirm($attempt->reference, frozenData($attempt)))->toHaveCount(2);
    expect($charges[0]->fresh()->commission_amount)->toBe(100)->and($charges[1]->fresh()->commission_amount)->toBe(51)
        ->and($attempt->fresh()->status)->toBe('confirmed');
    expect($confirmer->confirm($attempt->reference, frozenData($attempt)))->toBe([])
        ->and(LedgerTransaction::query()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('attempt mismatches refuse all booking and leave a durable note', function (string $field) {
    [, , $charges, $attempt] = frozenFixture();
    $data = frozenData($attempt);
    $reference = $attempt->reference;
    match ($field) {
        'reference' => $reference = 'forged-reference',
        'currency' => $data['currency'] = 'USD',
        'amount' => $data['amount'] = 1,
        'tenant' => $data['metadata']['tenant_id'] = Tenant::factory()->create()->id,
        'member' => $data['metadata']['member_id'] = Member::factory()->create(['tenant_id' => $attempt->tenant_id])->id,
        'charges' => $data['metadata']['charge_ids'] = [$charges[0]->id],
    };
    expect(app(DuesPaymentConfirmer::class)->confirm($reference, $data))->toBe([])
        ->and($charges[0]->fresh()->status)->toBe(DuesChargeStatusEnum::Open)
        ->and(LedgerTransaction::query()->count())->toBe(0);
    expect((string) $charges[0]->fresh()->note)->toContain('received but not booked');
})->with(['reference', 'currency', 'amount', 'tenant', 'member', 'charges']);

test('retry supersedes an earlier attempt but its real payment can still be honoured', function () {
    [, $member, $charges, $attempt] = frozenFixture();
    app(DuesCheckout::class)->start($member, $charges->pluck('id')->all(), 'https://example.com/callback');
    expect($attempt->fresh()->status)->toBe('superseded');
    expect(app(DuesPaymentConfirmer::class)->confirm($attempt->reference, frozenData($attempt)))->toHaveCount(2);
    $new = DuesPaymentAttempt::query()->where('id', '<>', $attempt->id)->firstOrFail();
    expect(app(DuesPaymentConfirmer::class)->confirm($new->reference, frozenData($new)))->toBe([])
        ->and((string) $charges[0]->fresh()->note)->toContain('received again');
});

test('legacy payments without an attempt retain the explicit legacy one percent rate', function () {
    [, , $charges, $attempt] = frozenFixture();
    config(['services.dues.platform_fee_percentage' => 3, 'services.dues.legacy_fee_percentage' => 1]);
    $data = frozenData($attempt);
    unset($data['metadata']['dues_attempt_id']);
    expect(app(DuesPaymentConfirmer::class)->confirm('legacy-reference', $data))->toHaveCount(2)
        ->and($charges[0]->fresh()->commission_amount)->toBe(100)->and($charges[1]->fresh()->commission_amount)->toBe(51);
});

test('a checkout attempt cannot have its financial snapshot rewritten', function () {
    [, , , $attempt] = frozenFixture();
    expect(fn () => $attempt->update(['expected_amount' => 1]))->toThrow(LogicException::class);
    expect($attempt->fresh()->expected_amount)->toBe(15050);
});

test('a payment naming an attempt but missing required metadata is reconciled visibly', function () {
    [, , $charges, $attempt] = frozenFixture();
    $data = frozenData($attempt);
    unset($data['metadata']['member_id']);
    expect(app(DuesPaymentConfirmer::class)->confirm($attempt->reference, $data))->toBe([])
        ->and((string) $charges[0]->fresh()->note)->toContain('received but not booked')
        ->and($charges[0]->fresh()->status)->toBe(DuesChargeStatusEnum::Open);
});

test('own gateway checkout routes exactly the sum of the frozen commissions', function () {
    [, , $charges, $attempt] = frozenFixture(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'transaction/initialize')
        && $request['transaction_charge'] === 14899 && $request['subaccount'] === 'ACCT_terms');
    $data = frozenData($attempt);
    $data['settled_by'] = 'own_gateway';
    $data['metadata']['commission_split'] = 'ACCT_terms';
    $data['metadata']['commission_credit_applied'] = 0;
    $data['split_received'] = 151;
    expect(app(DuesPaymentConfirmer::class)->confirm($attempt->reference, $data))->toHaveCount(2)
        ->and($charges[0]->fresh()->commission_amount)->toBe(100)->and($charges[1]->fresh()->commission_amount)->toBe(51);
    $accounts = App\Models\LedgerAccount::query()->where('tenant_id', $attempt->tenant_id)->get();
    expect($accounts->firstWhere('code', App\Models\LedgerAccount::CODE_PLATFORM_REVENUE)->currentBalance())->toBe(151)
        ->and($accounts->firstWhere('code', App\Models\LedgerAccount::CODE_ORGANIZER_PAYABLE)->currentBalance())->toBe(0);
});

test('callback and webhook book one frozen payment once in either order', function (bool $callbackFirst) {
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, $member, , $attempt] = frozenFixture();
    Illuminate\Support\Facades\Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    $data = [...frozenData($attempt), 'reference' => $attempt->reference, 'status' => 'success'];
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['data' => $data])]);
    $callback = fn () => $this->get('http://'.eventSubdomainHost($tenant->slug)."/pay/{$member->pay_token}/callback?reference={$attempt->reference}")->assertRedirect();
    $webhook = function () use ($data): void {
        $body = json_encode(['event' => 'charge.success', 'data' => $data]);
        $this->call('POST', '/webhooks/settlement/paystack', [], [], [], [
            'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_terms'), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
    };
    if ($callbackFirst) {
        $callback();
        $webhook();
    } else {
        $webhook();
        $callback();
    }
    expect($attempt->fresh()->status)->toBe('confirmed')
        ->and(LedgerTransaction::query()->where('tenant_id', $tenant->id)->count())->toBe(2);
    Mail::assertQueued(App\Mail\DuesReceipt::class, 1);
})->with([true, false]);

test('omitting attempt metadata cannot downgrade a frozen reference to the legacy rate', function () {
    [, , $charges, $attempt] = frozenFixture();
    $data = frozenData($attempt);
    unset($data['metadata']['dues_attempt_id']);
    expect(app(DuesPaymentConfirmer::class)->confirm($attempt->reference, $data))->toBe([])
        ->and((string) $charges[0]->fresh()->note)->toContain('received but not booked')
        ->and(LedgerTransaction::query()->count())->toBe(0);
});

test('an invalid attempt id is refused without losing the payment reconciliation note', function () {
    [, , $charges, $attempt] = frozenFixture();
    $data = frozenData($attempt);
    $data['metadata']['dues_attempt_id'] = 'not-a-uuid';
    expect(app(DuesPaymentConfirmer::class)->confirm($attempt->reference, $data))->toBe([])
        ->and((string) $charges[0]->fresh()->note)->toContain('received but not booked');
});

test('checkout retry and confirmation acquire the same member lock before locking attempts and charges', function () {
    [, $member, $charges, $attempt] = frozenFixture();
    $locks = [];
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$locks): void {
        if (! str_contains(mb_strtolower($query->sql), 'for update')) {
            return;
        }
        foreach (['members', 'dues_payment_attempts', 'dues_charges'] as $table) {
            if (str_contains($query->sql, 'from "'.$table.'"')) {
                $locks[] = $table;
            }
        }
    });
    app(DuesCheckout::class)->start($member, $charges->pluck('id')->all(), 'https://example.com/callback');
    app(DuesPaymentConfirmer::class)->confirm($attempt->reference, frozenData($attempt));
    expect($locks)->toBe(['members', 'members', 'dues_payment_attempts', 'dues_charges'])
        ->and($attempt->fresh()->status)->toBe('confirmed');
});

test('a new dues checkout without a package freezes the Free two percent rate', function () {
    [, , $charges, $attempt] = frozenFixture(['package_id' => null]);
    expect($attempt->rate)->toBe('2.00')->and($attempt->commissions)
        ->toMatchArray([$charges[0]->id => 200, $charges[1]->id => 101]);
});
