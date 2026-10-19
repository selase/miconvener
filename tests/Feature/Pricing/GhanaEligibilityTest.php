<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pricing\GhanaEligibility;

/**
 * An organisation gets the Ghana price only with evidence of being in Ghana:
 * a verified Ghana payout account, a Ghana mobile-money or Ghana-issued-card
 * payment, or a superadmin's word. The evidence and who recorded it are kept.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    $this->tenant = Tenant::factory()->create();
    $this->eligibility = app(GhanaEligibility::class);
    $this->platformPayment = fn (array $meta, array $attributes = []): Transaction => Transaction::factory()->create($attributes + [
        'tenant_id' => $this->tenant->id, 'provider' => 'paystack', 'currency' => 'ghs',
        'meta' => ['type' => 'plan_subscription'] + $meta,
    ]);
});

test('a new organisation is not eligible', function (): void {
    expect($this->tenant->isGhanaEligible())->toBeFalse()
        ->and($this->eligibility->evaluate($this->tenant))->toBeNull();
});

test('a verified ghana payout account is evidence', function (): void {
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => true]);

    expect($this->eligibility->evaluate($this->tenant))->toMatchArray(['source' => 'payout_account'])
        ->and($this->eligibility->evaluate($this->tenant)['detail']['account_id'])->toBe($account->id);
});

test('an unverified payout account is not', function (): void {
    TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => false]);

    expect($this->eligibility->evaluate($this->tenant))->toBeNull();
});

test('a mobile money payment is evidence', function (): void {
    ($this->platformPayment)(['payment_origin' => ['channel' => 'mobile_money', 'country_code' => 'GH']]);

    expect($this->eligibility->evaluate($this->tenant))->toMatchArray(['source' => 'ghana_payment']);
});

test('a card issued in ghana is evidence and a card issued elsewhere is not', function (): void {
    ($this->platformPayment)(['payment_origin' => ['channel' => 'card', 'country_code' => 'NG']]);
    expect($this->eligibility->evaluate($this->tenant))->toBeNull();

    ($this->platformPayment)(['payment_origin' => ['channel' => 'card', 'country_code' => 'GH']]);
    expect($this->eligibility->evaluate($this->tenant)['detail'])->toMatchArray(['channel' => 'card', 'country_code' => 'GH']);
});

test('payments recorded before the origin was stored count through their authorization', function (): void {
    ($this->platformPayment)(['authorization' => ['channel' => 'mobile_money', 'country_code' => 'GH']]);

    expect($this->eligibility->evaluate($this->tenant))->toMatchArray(['source' => 'ghana_payment']);
});

test('only what the organisation paid the platform counts, not its own ticket sales', function (): void {
    Transaction::factory()->create(['tenant_id' => $this->tenant->id, 'provider' => 'paystack', 'meta' => ['type' => 'event_ticket', 'payment_origin' => ['channel' => 'mobile_money', 'country_code' => 'GH']]]);
    Transaction::factory()->create(['tenant_id' => $this->tenant->id, 'provider' => 'paystack', 'status' => 'failed', 'meta' => ['type' => 'plan_subscription', 'payment_origin' => ['channel' => 'mobile_money', 'country_code' => 'GH']]]);

    expect($this->eligibility->evaluate($this->tenant))->toBeNull();
});

test('granting records the evidence, who and when, and makes the organisation eligible', function (): void {
    expect($this->eligibility->grant($this->tenant, GhanaEligibility::SOURCE_GHANA_PAYMENT, ['channel' => 'mobile_money']))->toBeTrue();

    $fresh = $this->tenant->fresh();
    expect($fresh->isGhanaEligible())->toBeTrue()
        ->and($fresh->pricing_region)->toBe('GH')
        ->and($fresh->pricing_region_evidence)->toMatchArray(['source' => 'ghana_payment', 'detail' => ['channel' => 'mobile_money'], 'by' => null])
        ->and($fresh->pricing_region_evidence['at'])->not->toBeNull();
});

test('a superadmin grants and removes with a reason, and both are logged', function (): void {
    $staff = User::factory()->create(['tenant_id' => null]);

    $this->eligibility->grant($this->tenant, GhanaEligibility::SOURCE_SUPERADMIN, ['reason' => 'Known Ghana charity'], $staff);
    expect($this->tenant->fresh()->pricing_region_evidence['by'])->toEqual($staff->id);

    $this->eligibility->revoke($this->tenant->fresh(), $staff, 'Moved abroad');

    $fresh = $this->tenant->fresh();
    expect($fresh->isGhanaEligible())->toBeFalse()
        ->and($fresh->pricing_region)->toBe('INTL')
        ->and($fresh->pricing_region_evidence)->toMatchArray(['source' => 'revoked', 'detail' => ['reason' => 'Moved abroad']])
        ->and(Spatie\Activitylog\Models\Activity::query()->where('subject_id', $this->tenant->id)->where('description', 'like', '%Ghana%')->count())->toBe(2);
});

test('an automatic grant does not undo a superadmin removal, but a superadmin can restore it', function (): void {
    $staff = User::factory()->create(['tenant_id' => null]);
    $this->eligibility->revoke($this->tenant, $staff, 'Abuse');

    expect($this->eligibility->grant($this->tenant->fresh(), GhanaEligibility::SOURCE_GHANA_PAYMENT, []))->toBeFalse()
        ->and($this->tenant->fresh()->isGhanaEligible())->toBeFalse();

    expect($this->eligibility->grant($this->tenant->fresh(), GhanaEligibility::SOURCE_SUPERADMIN, ['reason' => 'Resolved'], $staff))->toBeTrue()
        ->and($this->tenant->fresh()->isGhanaEligible())->toBeTrue();
});

test('granting again keeps the first evidence', function (): void {
    $this->eligibility->grant($this->tenant, GhanaEligibility::SOURCE_PAYOUT_ACCOUNT, ['account_id' => 'a']);
    $this->eligibility->grant($this->tenant->fresh(), GhanaEligibility::SOURCE_GHANA_PAYMENT, ['channel' => 'card']);

    expect($this->tenant->fresh()->pricing_region_evidence['source'])->toBe('payout_account');
});

test('ensure grants an organisation whose evidence has appeared, once', function (): void {
    expect($this->eligibility->ensure($this->tenant))->toBeFalse();

    TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => true]);
    Illuminate\Support\Facades\Cache::flush();

    expect($this->eligibility->ensure($this->tenant))->toBeTrue()
        ->and($this->tenant->fresh()->pricing_region_evidence['source'])->toBe('payout_account')
        ->and($this->eligibility->ensure($this->tenant->fresh()))->toBeTrue();
});

test('an organisation settled in USD is upgraded by a verified Ghana payout account but not by a payment', function (): void {
    $this->tenant->update(['pricing_region' => 'INTL']);
    ($this->platformPayment)(['payment_origin' => ['channel' => 'mobile_money', 'country_code' => 'GH']]);

    expect($this->eligibility->ensure($this->tenant->fresh()))->toBeFalse()
        ->and($this->tenant->fresh()->pricing_region)->toBe('INTL');

    TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => true]);
    Illuminate\Support\Facades\Cache::flush();

    expect($this->eligibility->ensure($this->tenant->fresh()))->toBeTrue();
});

test('ensure does not look for evidence on every call when there is none', function (): void {
    expect($this->eligibility->ensure($this->tenant))->toBeFalse();

    TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => true]);

    expect($this->eligibility->ensure($this->tenant->fresh()))->toBeFalse();
});

test('a superadmin removal is not undone by ensure', function (): void {
    TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => true]);
    $this->eligibility->revoke($this->tenant, User::factory()->create(['tenant_id' => null]), 'Abuse');

    expect($this->eligibility->ensure($this->tenant->fresh()))->toBeFalse();
});
