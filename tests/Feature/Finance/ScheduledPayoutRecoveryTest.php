<?php

declare(strict_types=1);

use App\Models\EventPayout;
use App\Models\LedgerTransaction;
use App\Services\Finance\ScheduledPayoutApproval;
use App\Services\Finance\ScheduledPayoutPlanner;
use App\Services\Finance\ScheduledPayoutSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule', 'services.payout_schedules.liquidity_controls' => true,
        'services.payout_schedules.cash_buffer' => 100, 'services.payout_schedules.daily_total_limit' => 100000]);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC'));
});

function submittedScheduledPayout(): array
{
    [$policy, $tenant, $owner, , $account] = fundedScheduledPolicy();
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 100000]]]),
        'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_scheduled']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_scheduled']])]);
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $payout = app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, (string) Str::uuid());
    $result = app(ScheduledPayoutApproval::class)->submit($proposal, $owner);
    expect($result['http_status'])->toBe(200)->and($payout->fresh()->status)->toBe('awaiting_otp');

    return [$payout->fresh(), $proposal->fresh(), $tenant, $owner];
}

function scheduledTransferEvidence(EventPayout $payout, array $changes = []): array
{
    return array_replace(['status' => 'success', 'reference' => $payout->provider_reference, 'transfer_code' => 'TRF_scheduled',
        'amount' => $payout->requested_net_amount, 'currency' => 'GHS', 'fee' => $payout->requested_transfer_fee_amount, 'recipient_code' => 'RCP_scheduled'], $changes);
}

test('only verified scheduled transfer success posts a payout once', function () {
    [$payout] = submittedScheduledPayout();
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldReceive('verifyTransfer')->twice()->with($payout->provider_reference)->andReturn(scheduledTransferEvidence($payout));
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    app(ScheduledPayoutSettlement::class)->reconcile($payout);
    app(ScheduledPayoutSettlement::class)->reconcile($payout);
    expect($payout->fresh()->status)->toBe('paid');
    expect(LedgerTransaction::where('reference', $payout->provider_reference)->where('transaction_type', 'payout')->count())->toBe(1);
});

test('mismatched transfer evidence never marks an organisation paid', function () {
    [$payout] = submittedScheduledPayout();
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldReceive('verifyTransfer')->once()->andReturn(scheduledTransferEvidence($payout, ['amount' => 1]));
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    app(ScheduledPayoutSettlement::class)->reconcile($payout);
    expect($payout->fresh()->status)->toBe('awaiting_otp');
    expect(LedgerTransaction::where('reference', $payout->provider_reference)->count())->toBe(0);
});

test('a verified reversal restores only returned principal once and retains the transfer fee', function () {
    [$payout, , $tenant] = submittedScheduledPayout();
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldReceive('verifyTransfer')->times(3)->andReturn(scheduledTransferEvidence($payout),
        scheduledTransferEvidence($payout, ['status' => 'reversed']), scheduledTransferEvidence($payout, ['status' => 'reversed']));
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    $service = app(ScheduledPayoutSettlement::class);
    $service->reconcile($payout);
    $service->reconcile($payout);
    $service->reconcile($payout);
    expect($payout->fresh()->status)->toBe('reversed')->and($payout->fresh()->reversed_amount)->toBe($payout->requested_net_amount);
    expect(LedgerTransaction::where('transaction_type', 'payout_reversal')->count())->toBe(1);
    expect(app(App\Services\Dues\DuesBalance::class)->payable($tenant, 'GHS'))->toBe(15000 - $payout->requested_transfer_fee_amount);
});

test('a lost initiation response recovers the original OTP transfer without sending again', function () {
    [$payout] = submittedScheduledPayout();
    $payout->update(['status' => 'processing', 'provider_transfer_code' => null]);
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldReceive('verifyTransfer')->once()->andReturn(scheduledTransferEvidence($payout, ['status' => 'otp', 'fee' => null]));
    $gateway->shouldNotReceive('initiateTransfer');
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    app(ScheduledPayoutSettlement::class)->reconcile($payout);
    expect($payout->fresh()->status)->toBe('awaiting_otp')->and($payout->fresh()->provider_transfer_code)->toBe('TRF_scheduled');
});

test('a reversal observed before success still consumes the verified original transfer fee once', function () {
    [$payout, , $tenant] = submittedScheduledPayout();
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldReceive('verifyTransfer')->twice()->andReturn(scheduledTransferEvidence($payout, ['status' => 'reversed']));
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    $service = app(ScheduledPayoutSettlement::class);
    $service->reconcile($payout);
    $service->reconcile($payout);
    expect($payout->fresh()->status)->toBe('reversed')
        ->and(app(App\Services\Dues\DuesBalance::class)->payable($tenant, 'GHS'))->toBe(15000 - $payout->requested_transfer_fee_amount)
        ->and(LedgerTransaction::where('transaction_type', 'payout_reversal')->count())->toBe(1);
});

test('mismatched provider evidence invalidates the previous reconciliation certificate', function () {
    [$payout, $proposal, $tenant] = submittedScheduledPayout();
    $policy = App\Models\OrganisationPayoutPolicy::findOrFail($proposal->policy_id);
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldReceive('verifyTransfer')->once()->andReturn(scheduledTransferEvidence($payout, ['amount' => 1]));
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    app(ScheduledPayoutSettlement::class)->reconcile($payout);
    expect(App\Models\ScheduledPayoutNotice::query()->where('kind', 'review_required')->exists())->toBeTrue();
    expect(fn () => app(App\Services\Finance\PayoutReconciliationCheckpoints::class)->assertFresh($policy->account_fingerprint, $tenant->id.':dues'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('an OTP release rejection cannot discard a scheduled reversal fee', function () {
    [$payout, $proposal, $tenant, $owner] = submittedScheduledPayout();
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldReceive('finalizeTransfer')->once()->andThrow(new App\Exceptions\PaymentFailedException('Release outcome uncertain'));
    $gateway->shouldReceive('verifyTransfer')->once()->andReturn(scheduledTransferEvidence($payout, ['status' => 'reversed']));
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    app(ScheduledPayoutApproval::class)->finalize($proposal->fresh(), $owner, '123456');
    expect($payout->fresh()->status)->toBe('reversed')
        ->and(app(App\Services\Dues\DuesBalance::class)->payable($tenant, 'GHS'))->toBe(15000 - $payout->requested_transfer_fee_amount);
});
