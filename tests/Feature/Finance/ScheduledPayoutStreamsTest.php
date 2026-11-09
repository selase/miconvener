<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\EventPayoutSchedule;
use App\Models\LedgerAccount;
use App\Models\PayoutReconciliationCheckpoint;
use App\Services\Finance\PayoutEligibility;
use App\Services\Finance\PayoutPolicies;
use App\Services\Finance\ScheduledPayoutApproval;
use App\Services\Finance\ScheduledPayoutPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule']);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC'));
    Queue::fake();
});

function scheduledStreamPolicy(string $category): array
{
    [$tenant, $owner, $platform, $account] = scheduledPolicyActors();
    $event = $category === 'event' ? Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']) : null;
    if ($category === 'commerce') {
        $shop = App\Models\Shop::factory()->create(['tenant_id' => $tenant->id]);
        App\Models\StoreCommercialAgreement::factory()->create(['tenant_id' => $tenant->id, 'shop_id' => $shop->id,
            'account_fingerprint' => app(PayoutPolicies::class)->accountFingerprint()]);
    }
    app(App\Services\Finance\LedgerService::class)->postTransaction($tenant, $event, $category === 'event' ? 'ticket_sale' : 'test_funding', 'Verified stream funds', 'stream-funding', [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => 'debit', 'amount' => 15000],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => 'credit', 'amount' => 15000],
    ], currency: 'GHS', category: LedgerCategoryEnum::from($category));
    $policy = app(PayoutPolicies::class)->approve(app(PayoutPolicies::class)->request($tenant, $owner,
        array_replace(scheduledPolicyInput($account), ['category' => $category])), $platform);
    test()->travel(7)->days();
    foreach (['*', $tenant->id.':'.$category] as $scope) {
        PayoutReconciliationCheckpoint::query()->create(['account_fingerprint' => $policy->account_fingerprint,
            'scope_key' => $scope, 'observed_at' => now(), 'status' => 'matched', 'reviewed_by' => $platform->id, 'created_at' => now(),
            'evidence' => ['ledger_checked' => true, 'provider_checked' => true, 'unresolved_count' => 0]]);
    }

    return [$policy, $tenant, $owner, $event];
}

test('human approval reserves only its own stream with no payment posting', function (string $category) {
    [$policy, $tenant, $owner, $event] = scheduledStreamPolicy($category);
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $payout = app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, (string) Str::uuid());
    expect($payout->category->value)->toBe($category)->and($payout->event_id)->toBe($event?->id)
        ->and(App\Models\LedgerTransaction::where('transaction_type', 'payout')->count())->toBe(0);
})->with(['event', 'giving', 'commerce']);

test('an existing event release date remains binding on the new schedule', function () {
    [$policy, , , $event] = scheduledStreamPolicy('event');
    $event->update(['ends_at' => now()->addDays(10)]);
    EventPayoutSchedule::query()->create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id,
        'schedule_type' => 'post_event', 'status' => 'active', 'days_after_event' => 3, 'holdback_percentage' => 10, 'holdback_release_days' => 14]);
    expect(app(PayoutEligibility::class)->inspect($policy, $event->id)['eligible'])->toBeFalse();
});

test('existing event holdbacks are retained without double posting or changing history', function () {
    [$policy, , , $event] = scheduledStreamPolicy('event');
    $event->update(['ends_at' => now()->subDays(5)]);
    EventPayoutSchedule::query()->create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id,
        'schedule_type' => 'post_event', 'status' => 'active', 'days_after_event' => 3, 'holdback_percentage' => 80, 'holdback_release_days' => 14]);
    expect(app(PayoutEligibility::class)->inspect($policy, $event->id)['available'])->toBe(2500);
});

test('an event refund hold added after submission prevents OTP release', function () {
    [$policy, $tenant, $owner, $event] = scheduledStreamPolicy('event');
    config(['services.payout_schedules.liquidity_controls' => true, 'services.payout_schedules.cash_buffer' => 100, 'services.payout_schedules.daily_total_limit' => 100000]);
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/balance' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 100000]]]),
        '*api.paystack.co/transferrecipient' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_event']]),
        '*api.paystack.co/transfer' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_event']])]);
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $payout = app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, (string) Str::uuid());
    app(ScheduledPayoutApproval::class)->submit($proposal, $owner);
    App\Models\EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id,
        'member_benefit_required' => false, 'status' => App\Models\EventRegistration::STATUS_PENDING_BENEFIT_REVIEW,
        'currency' => 'GHS', 'amount' => 14000, 'charged_amount' => 14000, 'platform_fee_amount' => 0, 'payment_reference' => 'stream-funding']);
    $gateway = Mockery::mock(App\Contracts\SettlementGateway::class);
    $gateway->shouldNotReceive('finalizeTransfer');
    app()->instance(App\Contracts\SettlementGateway::class, $gateway);
    expect(app(ScheduledPayoutApproval::class)->finalize($proposal->fresh(), $owner, '123456')['http_status'])->toBe(422);
    expect($payout->fresh()->status)->toBe('awaiting_otp');
});

test('event policy preparation continues in bounded queued batches', function () {
    [$policy, $tenant] = scheduledStreamPolicy('event');
    Event::factory()->count(26)->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now());
    Queue::assertPushed(App\Jobs\PrepareScheduledPayoutPolicy::class, 1);
    $job = Queue::pushed(App\Jobs\PrepareScheduledPayoutPolicy::class)->first();
    expect($job->afterEventId)->not->toBeNull();
    $job->handle(app(ScheduledPayoutPlanner::class));
    Queue::assertPushed(App\Jobs\PrepareScheduledPayoutPolicy::class, 1);
    expect(App\Models\ScheduledPayoutProposal::query()->count())->toBe(1);
});
