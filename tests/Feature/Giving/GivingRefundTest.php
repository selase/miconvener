<?php

declare(strict_types=1);

use App\Models\GivingAdjustment;
use App\Models\LedgerTransaction;
use App\Services\Finance\CommercialTerms;
use App\Services\Giving\GivingBalance;
use App\Services\Giving\GivingRefunds;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\GivingScenario;

test('commission refund rounding is cumulative bounded and uses no overflowing multiplication', function () {
    $terms = app(CommercialTerms::class);
    expect($terms->refundedCommission(3, 1, 1))->toBe(0)->and($terms->refundedCommission(3, 1, 2))->toBe(1)
        ->and($terms->refundedCommission(3, 1, 3))->toBe(1)
        ->and($terms->refundedCommission(1000000000000, 10000000000, 500000000000))->toBe(5000000000);
});

test('a partial refund reserves payable then reduces only confirmed principal and recorded commission', function () {
    [$tenant, $user, $fund, $attempt, $source] = GivingScenario::paid();
    Http::fake(['*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 501, 'status' => 'pending']])]);
    $refunds = app(GivingRefunds::class);
    $key = (string) Str::uuid();
    $adjustment = $refunds->request($source, $user, 2000, 'Requested by giver', $key);
    expect($adjustment->status)->toBe('processing')->and($adjustment->commission_reversal)->toBe(20)
        ->and(app(GivingBalance::class)->pendingRefundReservations($tenant, 'GHS'))->toBe(1980)
        ->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(7720);
    expect($refunds->request($source, $user, 2000, 'Requested by giver', $key)->id)->toBe($adjustment->id);
    $refunds->confirm($adjustment, GivingScenario::refunded($adjustment, $attempt));
    expect($adjustment->fresh()->status)->toBe('confirmed')->and(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe(7720)
        ->and(app(App\Services\Giving\GivingProgress::class)->for($fund)['raised'])->toBe(8000)
        ->and($attempt->gift->fresh()->amount)->toBe(10000);
    $before = LedgerTransaction::query()->count();
    $refunds->confirm($adjustment, GivingScenario::refunded($adjustment, $attempt));
    expect(LedgerTransaction::query()->count())->toBe($before);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund') && $request['transaction'] === $attempt->reference && $request['amount'] === 2000);
});

test('full refund reverses the original commission exactly and retained processor fees remain owed', function () {
    [$tenant, $user, , $attempt, $source] = GivingScenario::paid();
    Http::fake(['*api.paystack.co/refund' => Http::sequence()->push(['status' => true, 'data' => ['id' => 501]])->push(['status' => true, 'data' => ['id' => 502]])]);
    $refunds = app(GivingRefunds::class);
    foreach ([3333, 6667] as $principal) {
        $adjustment = $refunds->request($source, $user, $principal, 'Return contribution', (string) Str::uuid());
        $refunds->confirm($adjustment, GivingScenario::refunded($adjustment, $attempt));
    }
    expect((int) GivingAdjustment::query()->sum('commission_reversal'))->toBe(100)
        ->and((int) GivingAdjustment::query()->sum('principal'))->toBe(10000)
        ->and($attempt->gift->fresh()->status)->toBe('refunded')
        ->and(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe(-200)
        ->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(0);
    expect(fn () => $refunds->request($source, $user, 1, 'Too much', (string) Str::uuid()))->toThrow(ValidationException::class);
});

test('an unknown refund outcome holds its reservation and never resends on a repeated operation', function () {
    [$tenant, $user, , , $source] = GivingScenario::paid();
    Http::fake(['*api.paystack.co/refund' => fn () => throw new Illuminate\Http\Client\ConnectionException('Synthetic outage')]);
    $key = (string) Str::uuid();
    $refunds = app(GivingRefunds::class);
    $adjustment = $refunds->request($source, $user, 5000, 'Refund', $key);
    expect($adjustment->status)->toBe('unknown')->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(4750)
        ->and($refunds->request($source, $user, 5000, 'Refund', $key)->id)->toBe($adjustment->id);
    expect(fn () => $refunds->request($source, $user, 5001, 'Another refund', (string) Str::uuid()))->toThrow(ValidationException::class);
});

test('only a matching original account and refund amount can confirm and definitive failure releases a reservation', function () {
    [$tenant, $user, , $attempt, $source] = GivingScenario::paid();
    Http::fake(['*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 501]])]);
    $refunds = app(GivingRefunds::class);
    $adjustment = $refunds->request($source, $user, 2000, 'Refund', (string) Str::uuid());
    $refunds->confirm($adjustment, [...GivingScenario::refunded($adjustment, $attempt), 'account_identity' => 'foreign-account']);
    expect($adjustment->fresh()->status)->not->toBe('confirmed');
    $refunds->confirm($adjustment, GivingScenario::refunded($adjustment, $attempt, 'failed'));
    expect($adjustment->fresh()->status)->toBe('failed')->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(9700);
});

test('refunds remain available after Giving is disabled and require the existing refund permission', function () {
    [$tenant, $user, , , $source] = GivingScenario::paid();
    $tenant->features()->whereIn('feature_key', ['community', 'community_giving'])->update(['enabled' => false]);
    Http::fake(['*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 501]])]);
    $user->revokePermissionTo('process refunds');
    expect(fn () => app(GivingRefunds::class)->request($source, $user, 2000, 'Refund', (string) Str::uuid()))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $user->givePermissionTo('process refunds');
    expect(app(GivingRefunds::class)->request($source, $user, 2000, 'Refund', (string) Str::uuid())->status)->toBe('processing');
});

test('own-account refunds never refund principal from the platform and only restore credit actually used', function (bool $usedCredit) {
    [$tenant, $user, , $attempt] = GivingScenario::online(own: true);
    $user->givePermissionTo('process refunds');
    if ($usedCredit) {
        app(App\Services\Finance\CommissionCredit::class)->grant($tenant, 'GHS', 100);
        $attempt->update(['commission_settlement' => ['commission_credit_applied' => 100, 'commission_split' => null]]);
    } else {
        $attempt->update(['commission_settlement' => ['commission_credit_applied' => 0, 'commission_split' => 'ACCT_TEST']]);
    }
    app(App\Services\Giving\GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt, ['commission_received' => $usedCredit ? 0 : 100]));
    $attempt->refresh();
    $source = app(App\Services\Giving\GivingSources::class)->find($tenant, 'gift', $attempt->gift_id);
    Http::fake(['*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 501]])]);
    $refunds = app(GivingRefunds::class);
    $adjustment = $refunds->request($source, $user, 10000, 'Full refund', (string) Str::uuid());
    $refunds->confirm($adjustment, GivingScenario::refunded($adjustment, $attempt));
    expect(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe($usedCredit ? 0 : 100)
        ->and(app(App\Services\Finance\CommissionCredit::class)->balance($tenant, 'GHS'))->toBe($usedCredit ? 100 : 0)
        ->and($adjustment->fresh()->credit_restored)->toBe($usedCredit ? 100 : 0);
})->with([false, true]);

test('cash is corrected without a refund gateway or creating platform accounts', function () {
    [$tenant, $user, $fund] = GivingScenario::make();
    $user->givePermissionTo('process refunds');
    $gift = app(App\Services\Giving\GivingCash::class)->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 10000, 'currency' => 'GHS'], (string) Str::uuid());
    $source = app(App\Services\Giving\GivingSources::class)->find($tenant, 'gift', $gift->id);
    Http::fake();
    expect(fn () => app(GivingRefunds::class)->request($source, $user, 1000, 'Use correction', (string) Str::uuid()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    expect(App\Models\LedgerAccount::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('staff can preview the refund before sending it and a signed notification verifies the exact stored refund', function () {
    [$tenant, $user, , $attempt, $source] = GivingScenario::paid();
    $host = eventSubdomainHost($tenant->slug);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host]);
    $base = 'http://'.$host.'/finance/giving/gift/'.$source->id.'/refunds';
    $this->getJson($base.'/preview?principal=2000')->assertOk()->assertJsonPath('commission_reversal', 20)->assertJsonPath('payable_impact', 1980);
    $key = (string) Str::uuid();
    Http::fake([
        '*api.paystack.co/refund/501' => Http::response(['status' => true, 'data' => ['id' => 501, 'merchant_note' => 'giving:'.$key, 'status' => 'processed', 'amount' => 2000, 'currency' => 'GHS']]),
        '*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 501]]),
    ]);
    $this->postJson($base, ['principal' => 2000, 'reason' => 'Giver requested refund', 'operation_key' => $key])->assertCreated()->assertJsonPath('adjustment.status', 'processing');
    $body = json_encode(['event' => 'refund.processed', 'data' => ['status' => 'processed', 'transaction_reference' => $attempt->reference, 'refund_reference' => 'bank-reference-not-api-id', 'amount' => '2000', 'currency' => 'GHS']]);
    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_giving')], $body)->assertOk();
    expect(GivingAdjustment::query()->sole()->status)->toBe('confirmed');
});

test('a pending payout reservation cannot be spent by a new refund', function () {
    [$tenant, $user, , , $source] = GivingScenario::paid();
    $account = App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'type' => App\Models\TenantPayoutAccount::TYPE_MOBILE_MONEY]);
    app(App\Services\Finance\CommunityPayouts::class)->reserve($tenant, $user, App\Enum\LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 7000]);
    Http::fake();
    expect(fn () => app(GivingRefunds::class)->request($source, $user, 7000, 'Refund during payout', (string) Str::uuid()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

test('staff can reconcile a lost refund response using a provider ID bound to the exact operation', function () {
    [$tenant, $user, , $attempt, $source] = GivingScenario::paid();
    Http::fake(['*api.paystack.co/refund' => fn () => throw new Illuminate\Http\Client\ConnectionException('Synthetic lost response')]);
    $key = (string) Str::uuid();
    $adjustment = app(GivingRefunds::class)->request($source, $user, 2000, 'Refund', $key);
    expect($adjustment->status)->toBe('unknown');
    Http::fake(['*api.paystack.co/refund/501' => Http::response(['status' => true, 'data' => ['id' => 501, 'merchant_note' => 'giving:'.$key, 'status' => 'processed', 'amount' => 2000, 'currency' => 'GHS']])]);
    $host = eventSubdomainHost($tenant->slug);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])->postJson('http://'.$host.'/finance/giving/refunds/'.$adjustment->id.'/reconcile', ['provider_refund_reference' => '501'])->assertOk()->assertJsonPath('adjustment.status', 'confirmed');
    expect(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe(7720);
});

test('a refund that leaves retained processing fees owed raises an operational review after commit', function () {
    [$tenant,$user,,$attempt,$source] = GivingScenario::paid();
    Http::fake(['*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 9901]])]);
    $refund = app(GivingRefunds::class)->request($source, $user, 10000, 'Full return', (string) Str::uuid());
    app(GivingRefunds::class)->confirm($refund, GivingScenario::refunded($refund, $attempt));
    $signals = app(App\Services\Operations\OperationalSignals::class)->webhookFailuresSince(Carbon\CarbonImmutable::now()->subMinute(), 'processing');
    expect(collect($signals)->contains(fn ($signal) => str_contains($signal['detail'], $refund->id) && str_contains($signal['detail'], 'negative payable')))->toBeTrue();
});
