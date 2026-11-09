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
