<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Community\MemberCap;
use App\Services\Dues\DuesScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/** @return array{Tenant, User, MembershipType} */
function cappedMembersTenant(): array
{
    [$tenant, $user] = eventHost('caps');
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    $tenant->features()->create(['feature_key' => 'community_members', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 2]]);
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id]);

    return [$tenant->fresh(), $user, $type];
}

function capMember(Tenant $tenant, MembershipType $type, string $status = 'active'): Member
{
    return Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $type->id, 'status' => $status]);
}

test('manual additions stop at the member cap with a useful plan message', function () {
    [$tenant, $user, $type] = cappedMembersTenant();
    capMember($tenant, $type);
    capMember($tenant, $type, 'lapsed');

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('caps')])
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'caps']), ['name' => 'New Member', 'email' => 'new@example.test', 'membership_type_id' => $type->id])
        ->assertUnprocessable()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Free plan includes 2 members'));

    expect(Member::query()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('pending active and lapsed members count but exited and other tenants do not', function () {
    [$tenant, , $type] = cappedMembersTenant();
    foreach (['pending', 'active', 'lapsed', 'exited'] as $status) {
        capMember($tenant, $type, $status);
    }
    Member::factory()->create();

    expect(app(MemberCap::class)->counted($tenant))->toBe(3);
});

test('exited members free a slot for a manual addition', function () {
    [$tenant, $user, $type] = cappedMembersTenant();
    capMember($tenant, $type);
    capMember($tenant, $type, 'exited');

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('caps')])
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'caps']), ['name' => 'New Member', 'email' => 'new@example.test', 'membership_type_id' => $type->id])
        ->assertCreated();
    expect(app(MemberCap::class)->counted($tenant))->toBe(2);
});

test('rejoining an exited member cannot bypass the cap', function () {
    [$tenant, $user, $type] = cappedMembersTenant();
    capMember($tenant, $type);
    capMember($tenant, $type, 'pending');
    $member = capMember($tenant, $type, 'exited');
    $route = route('tenant.community.members.rejoin', ['subdomain' => 'caps', 'member' => $member->id]);
    $console = $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('caps')]);
    $console->postJson($route)->assertUnprocessable();
    expect($member->fresh()->status)->toBe(MemberStatusEnum::Exited);
    $tenant->features()->where('feature_key', 'community_members')->update(['meta' => ['type' => 'limit', 'value' => 3]]);
    $console->postJson($route)->assertOk();
    expect($member->fresh()->status)->toBe(MemberStatusEnum::Active);
});

test('legacy plans have bounded sensible defaults instead of accidental unlimited membership', function (?string $slug, ?int $expected) {
    $tenant = Tenant::factory()->create(['package_id' => $slug ? Package::factory()->create(['slug' => $slug])->id : null]);
    expect(app(MemberCap::class)->limit($tenant))->toBe($expected);
})->with([[null, 50], ['free', 50], ['starter', 500], ['growth', 5000], ['enterprise', null], ['legacy-custom', 50]]);

test('an explicit unlimited member feature allows bulk additions beyond free defaults', function () {
    [$tenant] = cappedMembersTenant();
    $tenant->features()->where('feature_key', 'community_members')->update(['meta' => ['type' => 'limit', 'value' => -1]]);
    $cap = app(MemberCap::class);
    expect($cap->limit($tenant))->toBeNull();
    $cap->withLock($tenant, fn () => $cap->assertRoomFor($tenant, 10000));
});

test('bulk additions are refused atomically and updates adding no members remain allowed', function () {
    [$tenant, , $type] = cappedMembersTenant();
    capMember($tenant, $type);
    $cap = app(MemberCap::class);
    expect(fn () => $cap->withLock($tenant, fn () => $cap->assertRoomFor($tenant, 2)))->toThrow(DomainException::class, 'Free plan includes 2 members');
    $tenant->features()->where('feature_key', 'community_members')->update(['meta' => ['type' => 'limit', 'value' => 0]]);
    $cap->withLock($tenant, fn () => $cap->assertRoomFor($tenant, 0));
    expect(Member::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('the shared quota lock excludes a second connection and rolls back failed additions', function () {
    [$tenant, , $type] = cappedMembersTenant();
    $other = DB::build(config('database.connections.landlord'));
    $cap = app(MemberCap::class);
    try {
        expect(fn () => $cap->withLock($tenant, function () use ($tenant, $type, $other): void {
            $locked = $other->selectOne('select pg_try_advisory_xact_lock(hashtextextended(?, 0)) as acquired', ['community.members:'.$tenant->id]);
            expect($locked->acquired)->toBeFalse();
            capMember($tenant, $type);
            throw new DomainException('Rollback this addition');
        }))->toThrow(DomainException::class, 'Rollback this addition');
        expect(Member::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
    } finally {
        $other->disconnect();
    }
});

test('package seeding and feature sync apply member limits to existing tenants', function () {
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    foreach (['free' => 50, 'starter' => 500, 'growth' => 5000, 'enterprise' => null] as $slug => $expected) {
        $tenant = Tenant::factory()->create(['package_id' => Package::query()->where('slug', $slug)->firstOrFail()->id]);
        $tenant->features()->where('feature_key', 'community_members')->delete();
        $this->artisan('tenants:sync-features', ['--tenant' => $tenant->id])->assertSuccessful();
        $feature = $tenant->features()->where('feature_key', 'community_members')->firstOrFail();
        expect($feature->enabled)->toBeTrue()->and((int) $feature->meta['value'])->toBe($expected ?? -1)
            ->and(app(MemberCap::class)->limit($tenant))->toBe($expected);
    }
});

test('a downgrade preserves members payment history and ongoing dues while refusing additions', function () {
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    [$tenant, $user, $type] = cappedMembersTenant();
    $tenant->update(['package_id' => Package::query()->where('slug', 'starter')->firstOrFail()->id]);
    $tenant->syncFeaturesFromPackage();
    $type->update(['period' => 'daily', 'amount' => 100]);
    CommunitySettings::for($tenant)->fill(['generate_days_ahead' => 0])->save();
    $member = capMember($tenant, $type);
    $member->update(['joined_on' => '2026-10-14', 'active_since' => '2026-10-14']);
    $paid = DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $type->id, 'status' => DuesChargeStatusEnum::Paid, 'period_start' => '2026-10-13', 'period_end' => '2026-10-13', 'payment_reference' => 'paid-history']);
    Member::factory()->count(50)->create(['tenant_id' => $tenant->id, 'membership_type_id' => $type->id, 'status' => MemberStatusEnum::Pending]);
    $tenant->update(['package_id' => Package::query()->where('slug', 'free')->firstOrFail()->id]);
    $tenant->syncFeaturesFromPackage();
    expect(app(MemberCap::class)->counted($tenant))->toBe(51)
        ->and(app(MemberCap::class)->limit($tenant))->toBe(50);
    expect(app(DuesScheduler::class)->generateFor($tenant->fresh(), CarbonImmutable::parse('2026-10-15')))->toBe(1)
        ->and($member->fresh()->status)->toBe(MemberStatusEnum::Active)
        ->and($paid->fresh()->status)->toBe(DuesChargeStatusEnum::Paid)
        ->and($paid->fresh()->payment_reference)->toBe('paid-history');
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('caps')])
        ->postJson(route('tenant.community.members.store', ['subdomain' => 'caps']), ['name' => 'New Member', 'email' => 'new@example.test', 'membership_type_id' => $type->id])->assertUnprocessable();
});

test('people receives the unpaginated usage and over-cap state', function () {
    [$tenant, $user, $type] = cappedMembersTenant();
    foreach (['active', 'pending', 'lapsed', 'exited'] as $status) {
        capMember($tenant, $type, $status);
    }
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('caps')])
        ->get(route('tenant.community.people.index', ['subdomain' => 'caps']))
        ->assertInertia(fn (Assert $page) => $page->where('memberUsage', ['count' => 3, 'limit' => 2, 'plan' => 'Free', 'over_cap' => true]));
});
