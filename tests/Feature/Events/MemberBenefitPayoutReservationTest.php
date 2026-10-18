<?php

declare(strict_types=1);

use App\Models\EventPayout;
use App\Services\Events\MemberBenefitBalance;
use App\Services\Events\MemberBenefitPayments;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MemberBenefitScenario;

beforeEach(fn () => Mail::fake());

it('holds paid review and unresolved refunds out of event payout availability', function (string $state): void {
    [, $event, , , $quote, $attempt] = MemberBenefitScenario::make();
    $payment = MemberBenefitScenario::payment($attempt);
    if ($state === 'paid_review') {
        $payment['paid_at'] = $quote->expires_at;
    }
    app(MemberBenefitPayments::class)->confirm($attempt, $payment);
    if ($state !== 'paid_review') {
        $attempt->update(['state' => $state]);
    }
    expect(app(MemberBenefitBalance::class)->available($event))->toBe(0);
})->with(['paid_review', 'refund_pending', 'refund_unknown']);

it('never treats own-account member ticket gross as platform cash', function (string $provider): void {
    [, $event, , , , $attempt] = MemberBenefitScenario::make($provider);
    app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    expect(app(MemberBenefitBalance::class)->available($event))->toBe(0);
})->with(['own-paystack', 'stripe']);

it('separates a scheduled payout reservation from its own send-time capacity check', function (): void {
    [$tenant, $event, , , , $attempt] = MemberBenefitScenario::make();
    app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'currency' => 'GHS', 'amount' => 1000, 'status' => 'scheduled']);
    $balance = app(MemberBenefitBalance::class);
    expect($balance->available($event))->toBe(3800)->and($balance->available($event, $payout->id))->toBe(4800);
    $attempt->update(['state' => 'refund_pending']);
    expect($balance->available($event, $payout->id))->toBe(0);
});

it('rechecks a scheduled payout after a refund hold before contacting the provider', function (): void {
    [$tenant, $event, , , , $attempt] = MemberBenefitScenario::make();
    app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    $account = App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 1000, 'status' => 'scheduled']);
    $attempt->update(['state' => 'refund_pending']);
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldNotReceive('createRecipient');
    $gateway->shouldNotReceive('transfer');
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    expect(app(App\Services\Finance\PayoutSender::class)->send($payout, 'GHS')['http_status'])->toBe(422)
        ->and($payout->fresh()->status)->toBe('scheduled');
});

it('review keeps a parked OTP transfer when a later refund hold consumes its balance', function (): void {
    [$tenant, $event, , , , $attempt] = MemberBenefitScenario::make();
    app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    $account = App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 1000, 'status' => 'awaiting_otp', 'provider_transfer_code' => 'TRF_parked']);
    $attempt->update(['state' => 'refund_pending']);
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldNotReceive('finalizeTransfer');
    $gateway->shouldNotReceive('transfer');
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    expect(app(App\Services\Finance\PayoutRelease::class)->release($payout, '123456')['http_status'])->toBe(422);
    expect($payout->fresh()->status)->toBe('awaiting_otp')->and($payout->fresh()->provider_transfer_code)->toBe('TRF_parked');
});
