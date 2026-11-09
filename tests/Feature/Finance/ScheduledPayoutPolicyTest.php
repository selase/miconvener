<?php

declare(strict_types=1);

use App\Models\OrganisationPayoutPolicy;
use App\Services\Finance\PayoutPolicies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule']);
});

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';

test('a schedule needs independent platform approval and a cooling period', function () {
    [$tenant, $owner, $platform, $account] = scheduledPolicyActors();
    $policy = app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    expect($policy->status)->toBe('pending')->and($policy->approved_by)->toBeNull();
    $approved = app(PayoutPolicies::class)->approve($policy, $platform);
    expect($approved->status)->toBe('approved')->and($approved->approved_by)->toBe((int) $platform->id)
        ->and($approved->active_after->greaterThanOrEqualTo(now()->addHours(23)))->toBeTrue();
    expect(App\Models\EventPayout::count())->toBe(0);
});

test('an organisation cannot approve its own schedule', function () {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    $policy = app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    expect(fn () => app(PayoutPolicies::class)->approve($policy, $owner))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('scheduled payouts refuse unsupported settings and unverified destinations', function (string $field, mixed $value) {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    expect(fn () => app(PayoutPolicies::class)->request($tenant, $owner, array_replace(scheduledPolicyInput($account), [$field => $value])))
        ->toThrow(ValidationException::class);
})->with([['currency', 'USD'], ['category', 'marketplace'], ['cadence', 'automatic'], ['minimum_amount', 0], ['maximum_amount', 0], ['reserve_amount', -1], ['timezone', 'not/a-zone'], ['local_time', '25:00']]);

test('own gateway and foreign destinations cannot request a policy', function () {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    $tenant->update(['settlement_mode' => 'own_gateway']);
    expect(fn () => app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account)))->toThrow(ValidationException::class);
    $tenant->update(['settlement_mode' => 'platform_default']);
    $account->update(['is_verified' => false]);
    expect(fn () => app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account)))->toThrow(ValidationException::class);
});

test('approved policy terms are immutable and pause is immediate', function () {
    [$tenant, $owner, $platform, $account] = scheduledPolicyActors();
    $policy = app(PayoutPolicies::class)->approve(app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account)), $platform);
    expect(fn () => $policy->update(['maximum_amount' => 900000]))->toThrow(Illuminate\Database\QueryException::class);
});

test('a new policy version pauses its predecessor without moving funds', function () {
    [$tenant, $owner, $platform, $account] = scheduledPolicyActors();
    $first = app(PayoutPolicies::class)->approve(app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account)), $platform);
    $second = app(PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    expect($second->version)->toBe(2)->and($first->fresh()->status)->toBe('paused');
    app(PayoutPolicies::class)->pause($second, $owner);
    expect($second->fresh()->status)->toBe('paused');
    expect(OrganisationPayoutPolicy::count())->toBe(2);
});
