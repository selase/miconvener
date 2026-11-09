<?php

declare(strict_types=1);

use App\Models\EventPayout;
use App\Models\PayoutLiquidityAllocation;
use App\Services\Finance\ScheduledPayoutApproval;
use App\Services\Finance\ScheduledPayoutPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';
uses(DatabaseTruncation::class);

beforeEach(function () {
    expect((string) config('database.connections.landlord.database'))->toContain(getenv('CI') === 'true' ? 'testing' : 'scheduled_payouts_test');
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule']);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC'));
    Queue::fake();
});
afterEach(function () { $this->truncateDatabaseTables(); });

function scheduledPayoutRace(array $jobs): array
{
    $start = microtime(true) + 2;
    $workers = [];
    foreach ($jobs as $job) {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/finance/scheduled-payout-worker.php')], base_path(), [
            'DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'),
            'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1',
        ]);
        $worker->setTimeout(45)->setInput(json_encode($job + ['start_at' => $start, 'now' => now()->toIso8601String()], JSON_THROW_ON_ERROR));
        $worker->start(); $workers[] = $worker;
    }
    return array_map(function (Process $worker): array {
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        return json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }, $workers);
}

test('two human approvals reserve one payout with one original operation', function () {
    [$policy, $tenant, $owner, , $account] = fundedScheduledPolicy();
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $job = ['action' => 'reserve', 'tenant' => $tenant->id, 'actor' => $owner->id, 'proposal' => $proposal->id, 'key' => (string) Str::uuid()];
    $results = scheduledPayoutRace([$job, $job]);
    expect($results[0]['ok'])->toBeTrue()->and($results[1]['ok'])->toBeTrue()
        ->and(EventPayout::count())->toBe(1)->and(EventPayout::sum('amount'))->toBe(10000);
});

test('a manual payout and a scheduled approval cannot spend the same dues', function () {
    [$policy, $tenant, $owner, , $account] = fundedScheduledPolicy();
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $base = ['tenant' => $tenant->id, 'actor' => $owner->id, 'proposal' => $proposal->id, 'key' => (string) Str::uuid(), 'account' => $account->id];
    $results = scheduledPayoutRace([['action' => 'manual'] + $base, ['action' => 'reserve'] + $base]);
    expect(array_sum(array_column($results, 'ok')))->toBe(1)->and(EventPayout::count())->toBe(1)->and(EventPayout::sum('amount'))->toBe(10000);
});

test('cancellation or pause racing submission never duplicates or cancels a submitted transfer', function (string $action) {
    [$policy, $tenant, $owner, , $account] = fundedScheduledPolicy();
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $payout = app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, (string) Str::uuid());
    $base = ['tenant' => $tenant->id, 'actor' => $owner->id, 'proposal' => $proposal->id, 'policy' => $policy->id];
    scheduledPayoutRace([['action' => $action] + $base, ['action' => 'submit'] + $base]);
    expect(EventPayout::count())->toBe(1)->and(PayoutLiquidityAllocation::count())->toBeLessThanOrEqual(1);
    if (PayoutLiquidityAllocation::count() === 1) {
        expect($payout->fresh()->status)->toBe('awaiting_otp');
    } else {
        expect($payout->fresh()->status)->toBeIn(['scheduled', 'cancelled']);
    }
    expect(App\Models\LedgerTransaction::where('transaction_type', 'payout')->count())->toBe(0);
})->with(['cancel', 'pause']);

test('different organisations cannot allocate the same provider cash', function () {
    config(['services.payout_schedules.liquidity_controls' => true, 'services.payout_schedules.cash_buffer' => 100, 'services.payout_schedules.daily_total_limit' => 100000]);
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 10000]]])]);
    app(App\Services\Finance\ProviderLiquidity::class)->refresh(app(App\Services\Finance\PayoutPolicies::class)->accountFingerprint(), 'GHS');
    $jobs = [];
    for ($i = 0; $i < 2; $i++) {
        $account = App\Models\TenantPayoutAccount::factory()->create();
        $payout = EventPayout::factory()->create(['tenant_id' => $account->tenant_id, 'event_id' => null, 'category' => 'dues', 'currency' => 'GHS', 'payout_account_id' => $account->id, 'amount' => 6000, 'status' => 'processing', 'provider_reference' => 'race-liquidity-'.Str::uuid()]);
        $jobs[] = ['action' => 'liquidity', 'tenant' => $account->tenant_id, 'payout' => $payout->id];
    }
    $results = scheduledPayoutRace($jobs);
    expect(array_sum(array_column($results, 'ok')))->toBe(1)->and((int) PayoutLiquidityAllocation::sum('amount'))->toBe(6000)
        ->and(App\Models\ProviderPayoutLiquidity::first()->remaining)->toBe(3900);
});

test('a real commerce refund and payout submission preserve refund funding and one transfer claim', function () {
    [$tenant, , $actor, , $order] = Tests\Support\StoreCheckoutScenario::paid();
    $actor->givePermissionTo('manage store finance');
    $account = App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = app(App\Services\Marketplace\StorePayouts::class)->reserve($tenant, $actor,
        ['amount' => 5000, 'currency' => 'GHS', 'payout_account_id' => $account->id, 'operation_key' => (string) Str::uuid()]);
    $base = ['tenant' => $tenant->id, 'actor' => $actor->id, 'payout' => $payout->id, 'order' => $order->id, 'key' => (string) Str::uuid(), 'commerce' => true];
    scheduledPayoutRace([['action' => 'store_send'] + $base, ['action' => 'refund'] + $base]);
    expect(EventPayout::count())->toBe(1)->and(PayoutLiquidityAllocation::count())->toBeLessThanOrEqual(1);
    $refund = App\Models\StoreRefund::firstOrFail();
    expect($refund->status)->toBe('needs_funding')->and($refund->provider_refund_id)->toBeNull();
    expect(App\Models\LedgerTransaction::where('transaction_type', 'payout')->count())->toBe(0);
});
