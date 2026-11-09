<?php

declare(strict_types=1);

use App\Models\EventPayout;
use App\Models\LedgerTransaction;
use App\Services\Finance\ScheduledPayoutApproval;
use App\Services\Finance\ScheduledPayoutPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule']);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC'));
});

test('human approval reserves once and never sends or posts a payout', function () {
    Http::preventStrayRequests();
    [$policy, , $owner] = fundedScheduledPolicy();
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $before = LedgerTransaction::count();
    $key = (string) Str::uuid();
    $payout = app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, $key);
    $same = app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, $key);
    expect($same->id)->toBe($payout->id)->and(EventPayout::count())->toBe(1)->and($payout->status)->toBe('scheduled')
        ->and($proposal->fresh()->status)->toBe('approved')->and(LedgerTransaction::count())->toBe($before);
    Http::assertNothingSent();
});

test('an expired proposal cannot reserve money', function () {
    [$policy, , $owner] = fundedScheduledPolicy();
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $this->travelTo($proposal->expires_at);
    expect(fn () => app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(EventPayout::count())->toBe(0);
});

test('paused or changed destinations prevent an approved payout being submitted', function () {
    [$policy, , $owner, , $account] = fundedScheduledPolicy();
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $payout = app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, (string) Str::uuid());
    $account->update(['account_name' => 'A different recipient']);
    Http::preventStrayRequests();
    expect(fn () => app(ScheduledPayoutApproval::class)->submit($proposal, $owner))->toThrow(ValidationException::class);
    expect($payout->fresh()->provider_reference)->toBeNull();
    Http::assertNothingSent();
});

test('an expired unsent approval can be cancelled without touching ledger money', function () {
    [$policy, , $owner] = fundedScheduledPolicy();
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $payout = app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, (string) Str::uuid());
    $this->travel(30)->minutes();
    app(ScheduledPayoutApproval::class)->cancel($proposal, $owner);
    expect($payout->fresh()->status)->toBe('cancelled')->and($proposal->fresh()->status)->toBe('cancelled');
});

test('a human needs current payout permission even after approving before', function () {
    [$policy, $tenant, $owner] = fundedScheduledPolicy();
    $proposal = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    app(ScheduledPayoutApproval::class)->reserve($proposal, $owner, (string) Str::uuid());
    $owner->syncRoles([]);
    expect(fn () => app(ScheduledPayoutApproval::class)->submit($proposal, $owner))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

test('refreshing an unsent proposal preserves its original expiry and audit history', function () {
    [$policy, , $owner] = fundedScheduledPolicy();
    $first = app(ScheduledPayoutPlanner::class)->prepare($policy, CarbonImmutable::now())[0];
    $fresh = app(ScheduledPayoutPlanner::class)->refreshProposal($first, $owner);
    expect($fresh->id)->not->toBe($first->id)->and($fresh->revision)->toBe(2)
        ->and($fresh->expires_at->equalTo($first->expires_at))->toBeTrue()->and($first->fresh()->status)->toBe('cancelled');
});
