<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Models\DuesCharge;
use App\Models\DuesClass;
use App\Models\LedgerTransaction;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dues\DuesCharges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * A tenant with dues switched on, its admin and one active member on a 5000 monthly class.
 *
 * @return array{0: Tenant, 1: User, 2: Member}
 */
function duesChargeTenant(string $slug = 'acme'): array
{
    [$tenant, $user] = eventHost($slug);
    Artisan::call('dues:enable', ['tenant' => $tenant->slug]);
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'amount' => 5000]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id]);

    return [$tenant->fresh(), $user, $member];
}

function duesOpenCharge(Member $member, array $attributes = []): DuesCharge
{
    return DuesCharge::factory()->create(array_merge(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'dues_class_id' => $member->dues_class_id], $attributes));
}

test('creating a charge freezes the amount at the time', function () {
    [, , $member] = duesChargeTenant();
    $charge = app(DuesCharges::class)->createFor($member, CarbonImmutable::parse('2026-03-01'));

    $member->duesClass->update(['amount' => 9000]);

    expect($charge->amount)->toBe(5000)
        ->and($charge->fresh()->amount)->toBe(5000)
        ->and($charge->status)->toBe(DuesChargeStatusEnum::Open)
        ->and($charge->currency)->toBe('GHS')
        ->and($charge->period_start->toDateString())->toBe('2026-03-01')
        ->and($charge->period_end->toDateString())->toBe('2026-03-31')
        ->and($charge->due_on->toDateString())->toBe('2026-03-01');
});

test('a quarterly and a yearly class compute their period end', function () {
    [$tenant, , $member] = duesChargeTenant();
    $quarterly = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'period' => 'quarterly']);
    $yearly = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'period' => 'yearly']);

    $q = app(DuesCharges::class)->createFor(Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $quarterly->id]), CarbonImmutable::parse('2026-01-01'));
    $y = app(DuesCharges::class)->createFor(Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $yearly->id]), CarbonImmutable::parse('2026-01-01'));

    expect($q->period_end->toDateString())->toBe('2026-03-31')
        ->and($y->period_end->toDateString())->toBe('2026-12-31');
});

test('a period start on the 31st is anchored to the first of the month', function () {
    [, , $member] = duesChargeTenant();
    $charge = app(DuesCharges::class)->createFor($member, CarbonImmutable::parse('2026-01-31'));

    expect($charge->period_start->toDateString())->toBe('2026-01-01')
        ->and($charge->period_end->toDateString())->toBe('2026-01-31')
        ->and($charge->due_on->toDateString())->toBe('2026-01-01');
});

test('a zero override produces a waived charge', function () {
    [, , $member] = duesChargeTenant();
    $member->update(['amount_override' => 0]);

    $charge = app(DuesCharges::class)->createFor($member->fresh(), CarbonImmutable::parse('2026-03-01'));

    expect($charge->status)->toBe(DuesChargeStatusEnum::Waived)->and($charge->amount)->toBe(0);
});

test('creating a charge twice for the same period returns the same charge', function () {
    [, , $member] = duesChargeTenant();

    $first = app(DuesCharges::class)->createFor($member, CarbonImmutable::parse('2026-03-01'));
    $second = app(DuesCharges::class)->createFor($member, CarbonImmutable::parse('2026-03-15'));

    expect($second->id)->toBe($first->id)
        ->and(DuesCharge::query()->where('member_id', $member->id)->count())->toBe(1);
});

test('an exited member cannot be charged', function () {
    [, , $member] = duesChargeTenant();
    $member->update(['status' => MemberStatusEnum::Exited]);

    expect(fn () => app(DuesCharges::class)->createFor($member->fresh(), CarbonImmutable::parse('2026-03-01')))
        ->toThrow(DomainException::class, 'This member has left and cannot be charged.');
});

test('an admin creates a charge over the route', function () {
    [$tenant, $user, $member] = duesChargeTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.charges.store', ['subdomain' => 'acme']), ['member_id' => $member->id, 'period_start' => '2026-04-10'])
        ->assertCreated();

    $charge = DuesCharge::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($charge->period_start->toDateString())->toBe('2026-04-01')->and($charge->amount)->toBe(5000);
});

test('the charge route turns an exited member into a 422 and rejects a foreign member', function () {
    [, $user, $member] = duesChargeTenant();
    $member->update(['status' => MemberStatusEnum::Exited]);
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    $foreign = Member::factory()->create(['tenant_id' => $beta->id]);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.dues.charges.store', ['subdomain' => 'acme']), ['member_id' => $member->id, 'period_start' => '2026-04-01'])
        ->assertUnprocessable()->assertJsonPath('message', 'This member has left and cannot be charged.');
    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.dues.charges.store', ['subdomain' => 'acme']), ['member_id' => $foreign->id, 'period_start' => '2026-04-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['member_id']);
    expect(DuesCharge::query()->count())->toBe(0);
});

test('an admin waives and voids open charges with a note', function () {
    [, $user, $member] = duesChargeTenant();
    $toWaive = duesOpenCharge($member);
    $toVoid = duesOpenCharge($member, ['period_start' => '2026-01-01', 'period_end' => '2026-01-31']);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.dues.charges.waive', ['subdomain' => 'acme', 'charge' => $toWaive->id]), ['note' => 'Hardship'])->assertOk();
    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.dues.charges.void', ['subdomain' => 'acme', 'charge' => $toVoid->id]), ['note' => 'Raised in error'])->assertOk();

    expect($toWaive->fresh()->status)->toBe(DuesChargeStatusEnum::Waived)
        ->and($toWaive->fresh()->note)->toBe('Hardship')
        ->and($toVoid->fresh()->status)->toBe(DuesChargeStatusEnum::Void)
        ->and($toVoid->fresh()->note)->toBe('Raised in error');
});

test('recording an offline payment marks paid, posts no ledger entry and takes no commission', function () {
    [$tenant, $user, $member] = duesChargeTenant();
    $charge = duesOpenCharge($member);
    $before = LedgerTransaction::query()->count();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.charges.record-offline', ['subdomain' => 'acme', 'charge' => $charge->id]), ['note' => 'Cash at meeting'])
        ->assertOk();

    $charge->refresh();
    expect($charge->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and($charge->payment_method)->toBe('offline')
        ->and($charge->paid_at)->not->toBeNull()
        ->and($charge->recorded_by)->toBe((int) $user->id)
        ->and($charge->commission_amount)->toBeNull()
        ->and($charge->note)->toBe('Cash at meeting')
        ->and(LedgerTransaction::query()->count())->toBe($before);
});

test('only an open charge can be waived, voided or recorded', function () {
    [, $user, $member] = duesChargeTenant();
    $paid = duesOpenCharge($member, ['status' => DuesChargeStatusEnum::Paid]);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    foreach (['waive', 'void', 'record-offline'] as $action) {
        $this->actingAs($user)->withServerVariables($host)
            ->postJson(route("tenant.dues.charges.{$action}", ['subdomain' => 'acme', 'charge' => $paid->id]))
            ->assertUnprocessable();
    }

    expect($paid->fresh()->status)->toBe(DuesChargeStatusEnum::Paid);
});

test('an exited member can still have existing open charges waived, voided or recorded', function () {
    [, $user, $member] = duesChargeTenant();
    $a = duesOpenCharge($member);
    $b = duesOpenCharge($member, ['period_start' => '2026-01-01', 'period_end' => '2026-01-31']);
    $c = duesOpenCharge($member, ['period_start' => '2026-02-01', 'period_end' => '2026-02-28']);
    $member->update(['status' => MemberStatusEnum::Exited]);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($user)->withServerVariables($host)->postJson(route('tenant.dues.charges.waive', ['subdomain' => 'acme', 'charge' => $a->id]))->assertOk();
    $this->actingAs($user)->withServerVariables($host)->postJson(route('tenant.dues.charges.void', ['subdomain' => 'acme', 'charge' => $b->id]))->assertOk();
    $this->actingAs($user)->withServerVariables($host)->postJson(route('tenant.dues.charges.record-offline', ['subdomain' => 'acme', 'charge' => $c->id]))->assertOk();
});

test('a charge of another tenant cannot be acted on', function () {
    [, $user] = duesChargeTenant('acme');
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    $betaMember = Member::factory()->create(['tenant_id' => $beta->id]);
    $betaCharge = duesOpenCharge($betaMember);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    foreach (['waive', 'void', 'record-offline'] as $action) {
        $this->actingAs($user)->withServerVariables($host)
            ->postJson(route("tenant.dues.charges.{$action}", ['subdomain' => 'acme', 'charge' => $betaCharge->id]))
            ->assertNotFound();
    }

    expect($betaCharge->fresh()->status)->toBe(DuesChargeStatusEnum::Open);
});

test('a user without manage members is forbidden on every charge action', function () {
    [$tenant, , $member] = duesChargeTenant();
    $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($viewer->id);
    $charge = duesOpenCharge($member);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($viewer)->withServerVariables($host)
        ->postJson(route('tenant.dues.charges.store', ['subdomain' => 'acme']), ['member_id' => $member->id, 'period_start' => '2026-04-01'])->assertForbidden();
    foreach (['waive', 'void', 'record-offline'] as $action) {
        $this->actingAs($viewer)->withServerVariables($host)
            ->postJson(route("tenant.dues.charges.{$action}", ['subdomain' => 'acme', 'charge' => $charge->id]))->assertForbidden();
    }
});
