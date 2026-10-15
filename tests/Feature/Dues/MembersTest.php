<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * A tenant with dues switched on, its admin and one active class.
 *
 * @return array{0: Tenant, 1: User, 2: MembershipType}
 */
function duesMembersTenant(string $slug = 'acme'): array
{
    [$tenant, $user] = eventHost($slug);
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    $class = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'amount' => 5000]);

    return [$tenant->fresh(), $user, $class];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function duesMemberPayload(MembershipType $class, array $overrides = []): array
{
    return array_merge(['name' => 'Ama Mensah', 'email' => 'ama@example.com', 'phone' => '0244000001', 'membership_type_id' => $class->id], $overrides);
}

test('an admin adds a member who gets a pay token', function () {
    [$tenant, $user, $class] = duesMembersTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['joined_on' => '2026-01-15']))
        ->assertCreated()
        ->assertJsonMissingPath('pay_token');

    $member = Member::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($member->name)->toBe('Ama Mensah')
        ->and($member->status)->toBe(MemberStatusEnum::Active)
        ->and($member->pay_token)->toHaveLength(40)
        ->and($member->joined_on->toDateString())->toBe('2026-01-15');
});

test('a member with neither email nor phone is rejected', function () {
    [, $user, $class] = duesMembersTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['email' => null, 'phone' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'phone']);
});

test('a member needs an active class of the same organisation', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    $foreign = MembershipType::factory()->create(['tenant_id' => $beta->id]);
    $inactive = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($foreign))
        ->assertUnprocessable()->assertJsonValidationErrors(['membership_type_id']);
    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($inactive))
        ->assertUnprocessable()->assertJsonValidationErrors(['membership_type_id']);
});

test('a duplicate name and phone in the same organisation is rejected, the same identity in another is fine', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    Member::factory()->create(['tenant_id' => $beta->id, 'name' => 'Ama Mensah', 'phone' => '0244000001']);
    Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'name' => 'Ama Mensah', 'phone' => '0244000002']);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['phone' => '0244000002']))
        ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['phone' => '0244000001']))
        ->assertCreated();
});

test('an email already used by a member without a phone is rejected, but is fine next to a phone', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'email' => 'taken@example.com', 'phone' => null]);
    Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'email' => 'shared@example.com', 'phone' => '0244000009']);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['email' => 'taken@example.com', 'phone' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['email' => 'shared@example.com', 'phone' => '0244000010']))
        ->assertCreated();
});

test('an admin updates a member, keeping their own phone and email', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'phone' => '0244000002', 'email' => 'm@example.com']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.community.members.update', ['subdomain' => 'acme', 'member' => $member->id]), duesMemberPayload($class, ['name' => 'Renamed', 'phone' => '0244000002', 'email' => 'm@example.com', 'amount_override' => 0]))
        ->assertOk();

    $member->refresh();
    expect($member->name)->toBe('Renamed')->and($member->amount_override)->toBe(0);
});

test('the member list is paginated and shows effective amount and open totals', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'amount_override' => 3000]);
    DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $class->id, 'amount' => 3000, 'due_on' => now()->subDays(3)->toDateString()]);
    DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $class->id, 'amount' => 3000, 'status' => DuesChargeStatusEnum::Paid, 'period_start' => now()->subMonth()->startOfMonth()->toDateString()]);
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    Member::factory()->create(['tenant_id' => $beta->id]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.community.people.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Community/People')
            ->has('members.data', 1)
            ->where('members.data.0.id', $member->id)
            ->where('members.data.0.class_name', $class->name)
            ->where('members.data.0.effective_amount', 3000)
            ->where('members.data.0.amount_override', 3000)
            ->where('members.data.0.open_total', 3000)
            ->where('members.data.0.overdue', true)
            ->missing('members.data.0.pay_token'));
});

test('rotating the pay link changes the token', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id]);
    $old = $member->pay_token;

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.members.rotate-link', ['subdomain' => 'acme', 'member' => $member->id]))
        ->assertOk()->assertExactJson(['rotated' => true]);

    expect($member->fresh()->pay_token)->not->toBe($old)->toHaveLength(40);
});

test('exiting a member sets status and exit date and keeps their charges', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id]);
    $charge = DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $class->id]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.members.exit', ['subdomain' => 'acme', 'member' => $member->id]))
        ->assertOk();

    $member->refresh();
    expect($member->status)->toBe(MemberStatusEnum::Exited)
        ->and($member->exited_on->toDateString())->toBe(now()->toDateString())
        ->and(DuesCharge::query()->whereKey($charge->id)->exists())->toBeTrue();
});

test('another tenant\'s member cannot be updated, exited or rotated', function () {
    [, $acmeAdmin, $acmeClass] = duesMembersTenant('acme');
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    $betaMember = Member::factory()->create(['tenant_id' => $beta->id, 'name' => 'Beta person']);
    $oldToken = $betaMember->pay_token;
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($acmeAdmin)->withServerVariables($host)
        ->patchJson(route('tenant.community.members.update', ['subdomain' => 'acme', 'member' => $betaMember->id]), ['name' => 'Hacked', 'email' => 'h@example.com', 'membership_type_id' => $acmeClass->id])
        ->assertNotFound();
    $this->actingAs($acmeAdmin)->withServerVariables($host)
        ->postJson(route('tenant.community.members.exit', ['subdomain' => 'acme', 'member' => $betaMember->id]))->assertNotFound();
    $this->actingAs($acmeAdmin)->withServerVariables($host)
        ->postJson(route('tenant.community.members.rotate-link', ['subdomain' => 'acme', 'member' => $betaMember->id]))->assertNotFound();

    $betaMember->refresh();
    expect($betaMember->name)->toBe('Beta person')
        ->and($betaMember->status)->toBe(MemberStatusEnum::Active)
        ->and($betaMember->pay_token)->toBe($oldToken);
});

test('a user without manage members is forbidden on every member action', function () {
    [$tenant, , $class] = duesMembersTenant();
    $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($viewer->id);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id]);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];
    $params = ['subdomain' => 'acme', 'member' => $member->id];

    $this->actingAs($viewer)->withServerVariables($host)->get(route('tenant.community.people.index', ['subdomain' => 'acme']))->assertForbidden();
    $this->actingAs($viewer)->withServerVariables($host)->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class))->assertForbidden();
    $this->actingAs($viewer)->withServerVariables($host)->patchJson(route('tenant.community.members.update', $params), duesMemberPayload($class))->assertForbidden();
    $this->actingAs($viewer)->withServerVariables($host)->postJson(route('tenant.community.members.exit', $params))->assertForbidden();
    $this->actingAs($viewer)->withServerVariables($host)->postJson(route('tenant.community.members.rotate-link', $params))->assertForbidden();
});

test('a member of a deactivated class can still be edited, but cannot be moved into another inactive class', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'phone' => '0244000002']);
    $class->update(['is_active' => false]);
    $other = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];
    $params = ['subdomain' => 'acme', 'member' => $member->id];

    $this->actingAs($user)->withServerVariables($host)
        ->patchJson(route('tenant.community.members.update', $params), duesMemberPayload($class, ['name' => 'Still here', 'phone' => '0244000002', 'amount_override' => 100]))
        ->assertOk();
    $this->actingAs($user)->withServerVariables($host)
        ->patchJson(route('tenant.community.members.update', $params), duesMemberPayload($other, ['phone' => '0244000002']))
        ->assertUnprocessable()->assertJsonValidationErrors(['membership_type_id']);

    expect($member->fresh()->name)->toBe('Still here')->and($member->fresh()->membership_type_id)->toBe($class->id);
});

test('exiting an already exited member keeps the original exit date', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'status' => MemberStatusEnum::Exited, 'exited_on' => '2026-01-10']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.members.exit', ['subdomain' => 'acme', 'member' => $member->id]))
        ->assertOk();

    expect($member->fresh()->exited_on->toDateString())->toBe('2026-01-10');
});

test('a tenant without dues gets 404 on member store', function () {
    [, $user] = eventHost('acme');

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), ['name' => 'X', 'email' => 'x@example.com', 'membership_type_id' => 'x'])
        ->assertNotFound();
});

test('an override between zero and the minimum charge is rejected', function () {
    [, $user, $class] = duesMembersTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['amount_override' => 50]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount_override' => 'The amount must be 0 (waived) or at least 100 minor units (1.00).']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['amount_override' => 100]))
        ->assertCreated();
});

test('an override of zero waives the member\'s dues and is accepted on create and edit', function () {
    [$tenant, $user, $class] = duesMembersTenant();
    $console = fn () => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')]);

    $id = $console()->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), duesMemberPayload($class, ['amount_override' => 0]))
        ->assertCreated()
        ->json('id');

    expect(Member::query()->findOrFail($id)->amount_override)->toBe(0);

    $other = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id, 'phone' => '0244000099', 'amount_override' => 3000]);
    $console()->patchJson(route('tenant.community.members.update', ['subdomain' => 'acme', 'member' => $other->id]), duesMemberPayload($class, ['phone' => '0244000099', 'email' => null, 'amount_override' => 0]))
        ->assertOk();

    expect($other->fresh()->amount_override)->toBe(0)->and($other->fresh()->effectiveAmount())->toBe(0);
});
