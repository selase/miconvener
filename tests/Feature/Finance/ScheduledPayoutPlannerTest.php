<?php

declare(strict_types=1);

use App\Models\EventPayout;
use App\Models\ScheduledPayoutProposal;
use App\Services\Finance\PayoutPolicies;
use App\Services\Finance\ScheduledPayoutPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule']);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC'));
});

test('scheduling creates one proposal without reserving funds or sending money', function () {
    Http::preventStrayRequests();
    [$policy] = fundedScheduledPolicy();
    $planner = app(ScheduledPayoutPlanner::class);
    $first = $planner->prepare($policy, CarbonImmutable::now());
    $planner->prepare($policy, CarbonImmutable::now());
    expect($first)->toHaveCount(1)->and(ScheduledPayoutProposal::count())->toBe(1)
        ->and($first[0]->amount)->toBe(10000)->and($first[0]->snapshot['available'])->toBe(14500)
        ->and(EventPayout::count())->toBe(0);
    Http::assertNothingSent();
});

test('a stale reconciliation stops proposals and preserves all balances', function () {
    [$policy] = fundedScheduledPolicy();
    $this->travel(2)->days();
    expect(app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now()))->toBe([]);
    expect(EventPayout::count())->toBe(0);
});

test('missed runs create only the current window and paused policies create none', function () {
    [$policy, , $owner] = fundedScheduledPolicy();
    expect(app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now()))->toHaveCount(1);
    app(PayoutPolicies::class)->pause($policy, $owner);
    expect(app(ScheduledPayoutPlanner::class)->prepare($policy->fresh(), CarbonImmutable::now()))->toBe([]);
    expect(ScheduledPayoutProposal::first()->status)->toBe('superseded');
});

test('exact reconciliation age is expired', function () {
    [$policy] = fundedScheduledPolicy();
    $this->travel(24)->hours();
    expect(app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now()))->toBe([]);
});

test('a scope checkpoint without account-wide reconciliation cannot authorise proposals', function () {
    [$policy] = fundedScheduledPolicy(false);
    expect(app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now()))->toBe([]);
});
