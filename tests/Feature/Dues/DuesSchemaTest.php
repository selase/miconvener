<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\DuesPeriodEnum;
use App\Models\DuesCharge;
use App\Models\EventPayout;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(fn () => refreshTenantDatabases());

test('a member takes the class amount unless overridden, and a zero override is a waiver', function () {
    $tenant = Tenant::factory()->create();
    $class = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'amount' => 5000]);

    $plain = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id]);
    $discounted = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'amount_override' => 2000]);
    $waived = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'amount_override' => 0]);

    expect($plain->effectiveAmount())->toBe(5000)
        ->and($discounted->effectiveAmount())->toBe(2000)
        ->and($waived->effectiveAmount())->toBe(0);
});

test('every member gets a unique 40 character pay token, and rotating changes it', function () {
    $member = Member::factory()->create();
    $old = $member->pay_token;

    expect($old)->toHaveLength(40);
    expect($member->rotatePayToken())->not->toBe($old)->toHaveLength(40);
    expect($member->fresh()->pay_token)->not->toBe($old);
});

test('a charge is unique per member and period start', function () {
    $member = Member::factory()->create();
    DuesCharge::factory()->create(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'membership_type_id' => $member->membership_type_id, 'period_start' => '2026-11-01']);

    expect(fn () => DuesCharge::factory()->create(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'membership_type_id' => $member->membership_type_id, 'period_start' => '2026-11-01']))
        ->toThrow(QueryException::class);
});

test('class names are unique per tenant', function () {
    $tenant = Tenant::factory()->create();
    MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);

    expect(fn () => MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']))
        ->toThrow(QueryException::class);
});

test('overdue scope returns only open charges past their due date', function () {
    $member = Member::factory()->create();
    $base = ['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'membership_type_id' => $member->membership_type_id];
    DuesCharge::factory()->create($base + ['period_start' => '2026-01-01', 'due_on' => now()->subDay()->toDateString()]);
    DuesCharge::factory()->create($base + ['period_start' => '2026-02-01', 'due_on' => now()->addDay()->toDateString()]);
    DuesCharge::factory()->create($base + ['period_start' => '2026-03-01', 'due_on' => now()->subDay()->toDateString(), 'status' => DuesChargeStatusEnum::Paid]);

    expect(DuesCharge::overdue()->count())->toBe(1)
        ->and(DuesCharge::open()->count())->toBe(2);
});

test('an event payout can carry a currency and belongs to its tenant', function () {
    $payout = EventPayout::factory()->create(['currency' => 'USD']);

    expect($payout->currency)->toBe('USD')
        ->and($payout->tenant->is(Tenant::find($payout->tenant_id)))->toBeTrue()
        ->and(EventPayout::factory()->create()->fresh()->currency)->toBe('GHS');
});

test('dues periods compute the next start and the period end', function () {
    $jan = CarbonImmutable::parse('2026-01-01');
    $settings = new App\Models\CommunitySettings;

    expect(DuesPeriodEnum::Monthly->nextStart($jan, $settings)->toDateString())->toBe('2026-02-01')
        ->and(DuesPeriodEnum::Quarterly->nextStart($jan, $settings)->toDateString())->toBe('2026-04-01')
        ->and(DuesPeriodEnum::Yearly->nextStart($jan, $settings)->toDateString())->toBe('2027-01-01')
        ->and(DuesPeriodEnum::Monthly->periodEnd($jan, $settings)->toDateString())->toBe('2026-01-31')
        ->and(DuesPeriodEnum::Quarterly->periodEnd($jan, $settings)->toDateString())->toBe('2026-03-31')
        ->and(DuesPeriodEnum::Yearly->periodEnd($jan, $settings)->toDateString())->toBe('2026-12-31');
});

test('a dues class lists its members and a member lists its charges', function () {
    $charge = DuesCharge::factory()->create();

    expect($charge->member->membershipType->members)->toHaveCount(1)
        ->and($charge->member->charges)->toHaveCount(1)
        ->and($charge->membershipType->is($charge->member->membershipType))->toBeTrue();
});

test('a charge can be recorded by a real user', function () {
    $tenant = Tenant::factory()->create();
    $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

    $charge = DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'recorded_by' => $user->id]);

    expect($charge->fresh()->recorded_by)->toBe((int) $user->id);
});
