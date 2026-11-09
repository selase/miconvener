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
    app(App\Services\Finance\LedgerService::class)->postTransaction($tenant, $event, 'test_funding', 'Verified stream funds', 'stream-funding', [
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
