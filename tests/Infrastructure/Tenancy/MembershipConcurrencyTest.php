<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\OrganisationInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\OrganisationInvitations;
use App\Services\Tenancy\OrganisationMemberships;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

trait GuardsMembershipRaceDatabase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['landlord'];

    protected function beforeTruncatingDatabase(): void
    {
        $connection = DB::connection('landlord');
        if (! app()->environment('testing') || config('database.default') !== 'landlord'
            || $connection->getDriverName() !== 'pgsql'
            || ! Tests\Support\IsolatedPostgresDatabase::permits((string) $connection->getDatabaseName())) {
            throw new RuntimeException('Membership races require a isolated PostgreSQL testing database.');
        }
        if (! Tests\Support\IsolatedPostgresDatabase::permits((string) $connection->selectOne('select current_database() as name')->name)) {
            throw new RuntimeException('The connected database must be an isolated testing database.');
        }
    }
}

uses(GuardsMembershipRaceDatabase::class);

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

/** @return array{tenant: Tenant, owners: list<User>, role: Role} */
function membershipRaceScenario(): array
{
    $tenant = Tenant::factory()->create(['status' => TenantStatusEnum::ACTIVE, 'isolation_mode' => 'shared']);
    $owners = [];
    setPermissionsTeamId($tenant->id);
    foreach (range(1, 2) as $index) {
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tenant->allUsers()->attach($owner->id, ['status' => 'active', 'accepted_at' => now()]);
        $owner->assignRole('Org Superadmin');
        $owners[] = $owner;
    }

    return ['tenant' => $tenant, 'owners' => $owners, 'role' => Role::query()->whereNull('tenant_id')->where('name', 'Org Admin')->firstOrFail()];
}

/** @return array<string, mixed> */
function membershipRaceJob(array $scenario, string $action, User $actor, ?User $subject = null): array
{
    return ['tenant' => $scenario['tenant']->id, 'action' => $action, 'actor' => $actor->id,
        'subject' => $subject?->id, 'role' => $scenario['role']->id, 'status' => 'active'];
}

/**
 * Every process must demonstrably queue on the tenant (or supplied identity)
 * SELECT FOR UPDATE before release. Observing each waiter fixes the
 * order without guessing how long PHP bootstrap or a database operation takes.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @return list<array<string, mixed>>
 */
function raceMembershipWorkers(Tenant $tenant, array $jobs, ?User $lockedUser = null, ?Closure $beforeRelease = null): array
{
    $connection = DB::connection('landlord');
    expect($connection->transactionLevel())->toBe(0, 'Workers need committed fixtures, not a parent test transaction.');
    $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => base_path('bootstrap/cache/membership-race-no-config.php'),
        'MULTI_ORGANISATION_ACCESS_ENABLED' => config('organisation_access.enabled') ? 'true' : 'false',
        'DATABASE_URL' => '', 'DB_DATABASE' => $connection->getDatabaseName(),
        'DB_HOST' => (string) $connection->getConfig('host'), 'DB_PORT' => (string) $connection->getConfig('port'),
        'DB_USERNAME' => (string) $connection->getConfig('username'), 'DB_PASSWORD' => (string) $connection->getConfig('password'),
        'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $workers = [];
    $results = [];
    $connection->beginTransaction();
    try {
        if ($lockedUser === null) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        } else {
            User::query()->whereKey($lockedUser->id)->lockForUpdate()->firstOrFail();
        }
        foreach ($jobs as $job) {
            $name = 'membership-race-'.bin2hex(random_bytes(12));
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/tenancy/membership-worker.php')], base_path(), $environment);
            $worker->setTimeout(60)->setInput(json_encode($job + ['application_name' => $name], JSON_THROW_ON_ERROR));
            $workers[] = $worker;
            $worker->start();
            $deadline = microtime(true) + 20;
            $waiting = null;
            do {
                $connection->select('select pg_stat_clear_snapshot()');
                $waiting = $connection->selectOne("select pid, query from pg_stat_activity where datname = current_database() and application_name = ? and wait_event_type = 'Lock' and cardinality(pg_blocking_pids(pid)) > 0", [$name]);
                if ($waiting !== null || ! $worker->isRunning()) {
                    break;
                }
            } while (microtime(true) < $deadline);
            expect($waiting)->not->toBeNull('Worker did not queue on the expected row lock: '.$worker->getErrorOutput().$worker->getOutput());
            expect(mb_strtolower($waiting->query))->toContain($lockedUser === null ? '"tenants"' : '"users"');
            if ($job['action'] !== 'disable_mfa') {
                expect(mb_strtolower($waiting->query))->toContain('for update');
            }
        }
        $beforeRelease?->__invoke();
        $connection->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            $results[] = json_decode(mb_trim($worker->getOutput()), true, 16, JSON_THROW_ON_ERROR);
        }
        expect(array_unique(array_column($results, 'pid')))->toHaveCount(count($jobs));
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    return $results;
}

function membershipRaceActiveOwners(Tenant $tenant): array
{
    return app(OrganisationMemberships::class)->inTenant($tenant, [], fn (): array => $tenant->users()->get()
        ->filter(fn (User $user): bool => app(OrganisationMemberships::class)->isActive($user, $tenant) && $user->hasRole('Org Superadmin'))
        ->pluck('id')->sort()->values()->all());
}

it('serializes owners changing each other and preserves exactly one active owner', function (string $action, bool $reverse): void {
    $scenario = membershipRaceScenario();
    [$first, $second] = $reverse ? array_reverse($scenario['owners']) : $scenario['owners'];
    $results = raceMembershipWorkers($scenario['tenant'], [
        membershipRaceJob($scenario, $action, $first, $second),
        membershipRaceJob($scenario, $action, $second, $first),
    ]);

    expect($results[0]['outcome'])->toBe('OK')
        ->and($results[1]['outcome'])->toBeIn(['FORBIDDEN', 'VALIDATION'])
        ->and(membershipRaceActiveOwners($scenario['tenant']))->toBe([$first->id]);
    $memberships = TenantMembership::query()->where('tenant_id', $scenario['tenant']->id)->get()->keyBy('user_id');
    expect($memberships)->toHaveCount(2)
        ->and($memberships[$first->id]->status)->toBe('active')
        ->and($memberships[$second->id]->status)->toBe($action === 'revoke' ? 'revoked' : 'active')
        ->and($memberships[$second->id]->changed_by)->toEqual($first->id);
    if ($action === 'revoke') {
        expect($memberships[$second->id]->revoked_at)->not->toBeNull();
    }
    app(OrganisationMemberships::class)->inTenant($scenario['tenant'], [$second], function () use ($second, $action): void {
        expect($second->roles()->pluck('name')->all())->toBe($action === 'revoke' ? [] : ['Org Admin']);
    });
    expect(User::query()->whereIn('id', [$first->id, $second->id])->count())->toBe(2);
})->with([
    'revoke A then B' => ['revoke', false], 'revoke B then A' => ['revoke', true],
    'demote A then B' => ['update', false], 'demote B then A' => ['update', true],
]);

it('never revives a revoked membership when update races removal', function (bool $revokeFirst): void {
    $scenario = membershipRaceScenario();
    [$revoker, $editor] = $scenario['owners'];
    $subject = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    $scenario['tenant']->allUsers()->attach($subject->id, ['status' => 'active', 'accepted_at' => now()]);
    $subject->assignRole('Org Admin');
    $subject->givePermissionTo('read user');
    expect($subject->permissions()->count())->toBe(1);
    $identity = $subject->only(['email', 'password', 'status']);
    $jobs = [membershipRaceJob($scenario, 'revoke', $revoker, $subject), membershipRaceJob($scenario, 'update', $editor, $subject)];
    $results = raceMembershipWorkers($scenario['tenant'], $revokeFirst ? $jobs : array_reverse($jobs));

    expect(array_column($results, 'outcome'))->toBe($revokeFirst ? ['OK', 'VALIDATION'] : ['OK', 'OK']);
    $membership = TenantMembership::query()->where('tenant_id', $scenario['tenant']->id)->where('user_id', $subject->id)->sole();
    expect($membership->status)->toBe('revoked')->and($membership->revoked_at)->not->toBeNull()
        ->and($membership->changed_by)->toEqual($revoker->id)
        ->and(app(OrganisationMemberships::class)->isActive($subject, $scenario['tenant']))->toBeFalse()
        ->and($subject->fresh()->only(array_keys($identity)))->toBe($identity);
    app(OrganisationMemberships::class)->inTenant($scenario['tenant'], [$subject], function () use ($subject): void {
        expect($subject->roles()->count())->toBe(0)->and($subject->permissions()->count())->toBe(0);
    });
    expect(membershipRaceActiveOwners($scenario['tenant']))->toHaveCount(2);
})->with(['revoke wins first' => true, 'update commits first' => false]);

it('reserves the last seat for exactly one competing invitation', function (bool $reverse): void {
    $scenario = membershipRaceScenario();
    $scenario['tenant']->features()->updateOrCreate(['feature_key' => 'team_seats'], ['enabled' => true, 'meta' => ['type' => 'limit', 'value' => 3]]);
    expect($scenario['tenant']->featureLimitValue('team_seats'))->toBe(3);
    $emails = ['first-seat@example.test', 'second-seat@example.test'];
    if ($reverse) {
        $emails = array_reverse($emails);
    }
    $jobs = [];
    foreach ($emails as $index => $email) {
        $jobs[] = membershipRaceJob($scenario, 'invite', $scenario['owners'][$index]) + ['email' => $email];
    }
    $results = raceMembershipWorkers($scenario['tenant'], $jobs);

    expect(array_column($results, 'outcome'))->toBe(['OK', 'VALIDATION'])
        ->and($results[1]['errors'])->toHaveKey('email');
    $invitation = OrganisationInvitation::query()->where('tenant_id', $scenario['tenant']->id)->sole();
    expect($invitation->email)->toBe($emails[0])->and($invitation->status)->toBe('pending')
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and(app(OrganisationInvitations::class)->reservedSeats($scenario['tenant']))->toBe(1)
        ->and(TenantMembership::query()->where('tenant_id', $scenario['tenant']->id)->count())->toBe(2)
        ->and(User::query()->whereIn('email', $emails)->count())->toBe(0);
})->with(['first address first' => false, 'second address first' => true]);

it('serializes global identity deletion against admission of a sole organisation owner', function (bool $admissionFirst): void {
    $tenant = Tenant::factory()->create(['status' => TenantStatusEnum::ACTIVE, 'isolation_mode' => 'shared']);
    $platformActor = User::factory()->create(['tenant_id' => null, 'status' => User::STATUS_ACTIVE]);
    $subject = User::factory()->create(['tenant_id' => null, 'status' => User::STATUS_ACTIVE]);
    setPermissionsTeamId(null);
    $platformActor->assignRole('Superadmin');
    $scenario = ['tenant' => $tenant, 'role' => Role::query()->whereNull('tenant_id')->where('name', 'Org Superadmin')->firstOrFail()];
    $admit = membershipRaceJob($scenario, 'admit', $platformActor, $subject);
    $delete = membershipRaceJob($scenario, 'delete_identity', $platformActor, $subject);
    expect($subject->allTenants()->count())->toBe(0);

    $results = raceMembershipWorkers($tenant, $admissionFirst ? [$admit, $delete] : [$delete, $admit], $subject);

    expect(array_column($results, 'preloaded_membership_count'))->toBe([0, 0])
        ->and(array_column($results, 'outcome'))->toBe($admissionFirst ? ['OK', 'FORBIDDEN'] : ['OK', 'NOT_FOUND']);
    $memberships = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $subject->id);
    if ($admissionFirst) {
        expect(User::query()->whereKey($subject->id)->exists())->toBeTrue()
            ->and($memberships->sole()->status)->toBe('active')
            ->and(membershipRaceActiveOwners($tenant))->toBe([$subject->id]);
    } else {
        expect($results[1]['missing_model'])->toBe(User::class)
            ->and(User::query()->whereKey($subject->id)->exists())->toBeFalse()
            ->and($memberships->exists())->toBeFalse()
            ->and(membershipRaceActiveOwners($tenant))->toBe([])
            ->and(DB::connection('landlord')->table('model_has_roles')->where('model_type', User::class)->where('model_id', $subject->id)->where('tenant_id', $tenant->id)->exists())->toBeFalse();
    }
    expect($platformActor->fresh())->not->toBeNull();
})->with(['admission commits before deletion rechecks' => true, 'deletion commits before admission rechecks' => false]);

it('MFA disabling cannot race admission to an organisation requiring MFA', function (): void {
    $scenario = membershipRaceScenario();
    $scenario['tenant']->update(['require_2fa' => true]);
    $actor = User::factory()->create(['two_factor_secret' => 'B7S6S7S6S7S6S7S6', 'two_factor_confirmed_at' => now()]);
    $results = raceMembershipWorkers($scenario['tenant'], [membershipRaceJob($scenario, 'disable_mfa', $actor)], $actor, function () use ($scenario, $actor): void {
        $scenario['tenant']->allUsers()->attach($actor->id, ['status' => 'active', 'accepted_at' => now()]);
    });
    expect($results[0]['outcome'])->toBe('FORBIDDEN')
        ->and($actor->fresh()->two_factor_secret)->not->toBeNull();
});
