<?php

declare(strict_types=1);

use App\Models\ScheduledPayoutNotice;
use App\Services\Finance\PayoutPolicies;
use App\Services\Finance\ScheduledPayoutNotices;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule']);
    Mail::fake();
    Illuminate\Support\Facades\Queue::fake();
});

test('payout notices are durable and deduplicated without sending money', function () {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    $policy = app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    $service = app(ScheduledPayoutNotices::class);
    $service->enqueue($policy, 'requested', $policy->id);
    $service->enqueue($policy, 'requested', $policy->id);
    expect(ScheduledPayoutNotice::count())->toBe(1);
    Mail::assertNothingSent();
});

test('delivery is claimed once and does not change payout state', function () {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    $policy = app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    $notice = ScheduledPayoutNotice::firstOrFail();
    app(ScheduledPayoutNotices::class)->deliver($notice->id);
    app(ScheduledPayoutNotices::class)->deliver($notice->id);
    Mail::assertSent(App\Mail\ScheduledPayoutNoticeMail::class, 1);
    expect($notice->fresh()->status)->toBe('sent')->and(App\Models\EventPayout::count())->toBe(0);
});

test('a queue outage preserves the committed policy and its durable notice', function () {
    Illuminate\Support\Facades\Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Test queue unavailable'));
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    $policy = app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    expect($policy->fresh()->status)->toBe('pending')
        ->and(ScheduledPayoutNotice::sole()->status)->toBe('queued')
        ->and(App\Models\EventPayout::count())->toBe(0);
});

test('an email transport failure retains a retry without changing money', function () {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    $notice = ScheduledPayoutNotice::sole();
    Mail::shouldReceive('to')->once()->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Test mail transport unavailable'));
    app(ScheduledPayoutNotices::class)->deliver($notice->id);
    expect($notice->fresh()->status)->toBe('retry')->and($notice->fresh()->attempts)->toBe(1)
        ->and($notice->fresh()->next_attempt_at)->not->toBeNull()->and(App\Models\EventPayout::count())->toBe(0);
});

test('the notice sweep recovers an abandoned worker claim without sending a payout', function () {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    $notice = ScheduledPayoutNotice::sole();
    $notice->update(['status' => 'sending', 'attempts' => 1, 'claimed_at' => now()->subMinutes(11)]);
    Illuminate\Support\Facades\Queue::fake();
    $this->artisan('payouts:deliver-notices')->assertSuccessful();
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\DeliverScheduledPayoutNotice::class, 1);
    app(ScheduledPayoutNotices::class)->deliver($notice->id);
    expect($notice->fresh()->status)->toBe('sent')->and($notice->fresh()->attempts)->toBe(2)
        ->and(App\Models\EventPayout::count())->toBe(0);
    Mail::assertSent(App\Mail\ScheduledPayoutNoticeMail::class, 1);
});
