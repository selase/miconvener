<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\OrganisationMemberships;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('revoking one membership preserves identity and access in another organisation', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create(['tenant_id' => $a->id]);
    $a->users()->attach([$owner->id, $member->id]);
    $b->users()->attach($member->id);
    setPermissionsTeamId($a->id);
    $owner->assignRole('Org Superadmin');
    $member->assignRole('Org Admin');
    setPermissionsTeamId($b->id);
    $member->unsetRelation('roles')->assignRole('Org Superadmin');
    app(OrganisationMemberships::class)->revoke($a, $owner, $member);
    expect($member->fresh())->not->toBeNull()
        ->and($member->fresh()->tenants()->whereKey($a->id)->exists())->toBeFalse()
        ->and($member->fresh()->tenants()->whereKey($b->id)->exists())->toBeTrue();
    setPermissionsTeamId($b->id);
    expect($member->fresh()->hasRole('Org Superadmin'))->toBeTrue();
});

test('suspension blocks membership without changing global status or credentials', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant->users()->attach([$owner->id, $member->id]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $role = Role::where('name', 'Org Admin')->firstOrFail();
    $password = $member->password;
    $globalStatus = $member->status;
    app(OrganisationMemberships::class)->updateAccess($tenant, $owner, $member, $role, [], 'suspended');
    expect(app(OrganisationMemberships::class)->isActive($member, $tenant))->toBeFalse()
        ->and($member->fresh()->status)->toBe($globalStatus)
        ->and($member->fresh()->password)->toBe($password);
});

test('the final active owner cannot be suspended or revoked', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $tenant->users()->attach($owner->id);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    expect(fn () => app(OrganisationMemberships::class)->revoke($tenant, $owner, $owner))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('foreign actors and platform roles are refused', function (): void {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->create();
    $member = User::factory()->create();
    $tenant->users()->attach($member->id);
    $role = Role::where('name', 'Org Admin')->firstOrFail();
    expect(fn () => app(OrganisationMemberships::class)->updateAccess($tenant, $actor, $member, $role, [], 'active'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('team membership updates cannot rewrite a shared identity', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'shared-team']);
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $member = User::factory()->create();
    $tenant->users()->attach([$owner->id, $member->id]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $role = Role::where('name', 'Org Admin')->firstOrFail();
    $email = $member->email;
    $this->actingAs($owner)->put('http://shared-team.miconvener.test/users/'.$member->uuid, [
        'first_name' => 'Hijacked', 'last_name' => 'Identity', 'email' => 'changed@example.org',
        'phone_no' => '+233201234567', 'role' => $role->id, 'status' => 'active',
    ])->assertRedirect();
    expect($member->fresh()->email)->toBe($email)
        ->and($member->fresh()->first_name)->not->toBe('Hijacked');
});

test('membership reconciliation reports primary-only identities without attaching them', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->artisan('organisations:audit-memberships', ['--tenant' => $tenant->id, '--json' => true])->assertExitCode(1);
    expect($user->allTenants()->count())->toBe(0);
});

test('staff broadcasts do not retain private details in an already-authorized subscription', function (): void {
    $session = new App\Models\EventSession;
    expect((new App\Events\SessionAttendanceUpdated($session, 'check_in', 'Private Name'))->broadcastWith())->toBe(['refresh' => true]);
    $request = new App\Models\EventServiceRequest;
    expect((new App\Events\ServiceRequestRaised($request))->broadcastWith())->toBe(['refresh' => true]);
});

test('tenant membership never authorizes whole-user impersonation', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'impersonation-owner']);
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $subject = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach([$owner->id, $subject->id]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $permission = App\Models\Permission::firstOrCreate(['name' => 'impersonate user', 'guard_name' => 'web']);
    $owner->givePermissionTo($permission);
    $this->actingAs($owner)->withSession(['active_tenant_id' => $tenant->id])->get(route('impersonation.impersonate', ['user' => $subject->id]))->assertForbidden();
});

test('schema rollback cannot silently restore suspended or revoked access', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->allTenants()->attach($tenant->id, ['status' => 'revoked']);
    $migration = require database_path('migrations/landlord/2026_11_20_155153_add_membership_lifecycle_to_tenant_user_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and($user->fresh()->tenants()->count())->toBe(0);
});

test('identity emails are canonical and cannot create a second case-variant account', function (): void {
    $user = User::factory()->create(['email' => '  Shared.Person@Example.org  ']);
    expect($user->fresh()->email)->toBe('shared.person@example.org');
    expect(fn () => User::factory()->create(['email' => 'SHARED.PERSON@example.org']))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('legacy host provisioning cannot rewrite identity or print credentials', function (): void {
    $user = User::factory()->create(['email' => 'host@example.org']);
    $before = $user->refresh()->getAttributes();
    $this->artisan('events:provision-host', ['slug' => 'legacy-host', 'email' => $user->email])->assertExitCode(1);
    expect($user->fresh()->getAttributes())->toBe($before)
        ->and(Tenant::query()->where('slug', 'legacy-host')->exists())->toBeFalse();
});

test('legacy queued credential mail never renders its retained password', function (): void {
    $secret = 'do-not-email-this-password';
    $setup = new App\Mail\Users\SendAccountDetails('Person', 'person@example.org', $secret);
    $reset = new App\Mail\Users\ResendAccountPassword(['user' => 'Person', 'email' => 'person@example.org', 'password' => $secret]);
    expect($setup->render())->not->toContain($secret)
        ->and($reset->render())->not->toContain($secret);
});

test('global identity audit does not serialize authentication secrets', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => 'DO-NOT-LOG-OTP-SECRET', 'password' => bcrypt('DO-NOT-LOG-PASSWORD')])->save();
    $logs = Spatie\Activitylog\Models\Activity::query()->where('subject_type', User::class)->where('subject_id', $user->id)->get()->toJson();
    expect($logs)->not->toContain('DO-NOT-LOG-OTP-SECRET')->not->toContain($user->password)->not->toContain('two_factor_secret');
});

test('an identity without memberships can open its platform account settings', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('workspaces.account'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Account/Index')->where('account.email', $user->email)->where('accountRoutePrefix', 'workspaces.account'));
});

test('rollout defaults cannot write revoked memberships before all readers are upgraded', function (): void {
    config(['organisation_access.enabled' => false]);
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant->users()->attach([$owner->id, $member->id]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    expect(fn () => app(OrganisationMemberships::class)->revoke($tenant, $owner, $member))->toThrow(Illuminate\Validation\ValidationException::class)
        ->and($member->fresh()->tenants()->whereKey($tenant->id)->exists())->toBeTrue();
});

test('inactive platform privilege cannot mutate a membership through a service or job', function (): void {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->create(['status' => User::STATUS_INACTIVE]);
    $member = User::factory()->create();
    $tenant->users()->attach($member->id);
    setPermissionsTeamId(null);
    $actor->assignRole('Superadmin');
    expect(fn () => app(OrganisationMemberships::class)->revoke($tenant, $actor, $member))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and($member->fresh()->tenants()->whereKey($tenant->id)->exists())->toBeTrue();
});

test('API identity payload does not publish a host organisations roles or features without membership', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);
    $request = Illuminate\Http\Request::create('/api/auth/login');
    $request->setUserResolver(fn () => $user);
    $data = (new App\Http\Resources\UserProfileResource($user))->toArray($request);
    expect($data['tenant'])->toBeNull()->and($data['roles'])->toBe([])->and($data['features'])->toBe([]);
});

test('impersonation cannot supply another persons consent to an organisation invitation', function (): void {
    $operator = User::factory()->create();
    setPermissionsTeamId(null);
    $operator->assignRole('Superadmin');
    $subject = User::factory()->create();
    $this->actingAs($subject)->withSession([
        'original_user' => $operator->id, 'original_security_version' => 0,
        App\Http\Middleware\StaffAccountSecurity::SESSION_KEY => ['user_id' => (string) $subject->id, 'version' => 0],
    ])->get('http://miconvener.test/organisation-invitations/'.Illuminate\Support\Str::uuid())->assertForbidden();
});

test('explicit membership scope binds child context and restores a different host context', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actor = User::factory()->create();
    $subject = User::factory()->create();
    $actor->tenants()->attach($b->id);
    setPermissionsTeamId($b->id);
    $actor->assignRole('Org Superadmin');
    $event = App\Models\Event::factory()->create(['tenant_id' => $b->id]);
    $role = Role::query()->where('name', 'Event Organiser')->firstOrFail();
    app(App\Services\Tenancy\TenantContext::class)->setTenant($a);
    setPermissionsTeamId($a->id);
    app(OrganisationMemberships::class)->admit($b, $actor, $subject, $role, [$event->id]);
    expect(App\Models\EventStaffAssignment::query()->where('tenant_id', $b->id)->where('user_id', $subject->id)->where('event_id', $event->id)->exists())->toBeTrue()
        ->and(app(App\Services\Tenancy\TenantContext::class)->activeTenantId())->toBe($a->id)
        ->and(getPermissionsTeamId())->toBe($a->id);
});

test('email normalization leaves non-string input for validation rather than throwing', function (string $path, bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    $this->postJson($path, ['email' => ['unexpected', 'array'], 'password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('email');
})->with([['/login', false], ['/forgot-password', false], ['/account-identity/email-changes', true]]);

test('API logout revokes only its real personal credential and does not pretend to close a browser session', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('logout-test');
    $this->postJson(route('api.auth.logout'), [], ['Authorization' => 'Bearer '.$token->plainTextToken])->assertNoContent();
    expect($user->tokens()->count())->toBe(0);
});

test('API logout directs browser sessions to the account menu', function (): void {
    $this->actingAs(User::factory()->create(), 'web')->postJson(route('api.auth.logout'))->assertStatus(400);
});

test('adding membership lifecycle preserves existing memberships credentials and scoped roles', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $password = $user->password;
    $roles = $user->roles()->pluck('roles.id')->all();
    $before = Illuminate\Support\Facades\DB::connection('landlord')->table('tenant_user')->where('user_id', $user->id)->first();
    $migration = require database_path('migrations/landlord/2026_11_20_155153_add_membership_lifecycle_to_tenant_user_table.php');
    $migration->down();
    $migration->up();
    $after = Illuminate\Support\Facades\DB::connection('landlord')->table('tenant_user')->where('user_id', $user->id)->first();
    expect($after->tenant_id)->toBe($before->tenant_id)
        ->and($after->user_id)->toBe($before->user_id)
        ->and($after->created_at)->toBe($before->created_at)
        ->and($after->status)->toBe('active')
        ->and($user->fresh()->password)->toBe($password)
        ->and($user->fresh()->tenant_id)->toBe($tenant->id)
        ->and($user->roles()->pluck('roles.id')->all())->toBe($roles);
});

test('canonical email migration refuses collisions without changing either identity or access', function (): void {
    $migration = require database_path('migrations/landlord/2026_11_20_160632_normalize_unique_user_email_identity.php');
    $migration->down();
    $tenant = Tenant::factory()->create();
    $first = User::factory()->create(['email' => 'collision@example.test']);
    $second = User::factory()->create(['email' => 'second@example.test']);
    $tenant->users()->attach([$first->id, $second->id]);
    $connection = Illuminate\Support\Facades\DB::connection('landlord');
    $connection->table('users')->where('id', $second->id)->update(['email' => ' COLLISION@example.test ']);
    $identities = $connection->table('users')->orderBy('id')->get()->toArray();
    $memberships = $connection->table('tenant_user')->orderBy('user_id')->get()->toArray();
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'no accounts were merged')
        ->and($connection->table('users')->orderBy('id')->get()->toArray())->toEqual($identities)
        ->and($connection->table('tenant_user')->orderBy('user_id')->get()->toArray())->toEqual($memberships);
});

test('canonical email migration preserves passwords and memberships when normalising a legacy address', function (): void {
    $migration = require database_path('migrations/landlord/2026_11_20_160632_normalize_unique_user_email_identity.php');
    $migration->down();
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['email' => 'legacy@example.test']);
    $tenant->users()->attach($user->id);
    $connection = Illuminate\Support\Facades\DB::connection('landlord');
    $connection->table('users')->where('id', $user->id)->update(['email' => ' Legacy@example.test ']);
    $password = $user->password;
    $migration->up();
    expect($user->fresh()->email)->toBe('legacy@example.test')
        ->and($user->fresh()->password)->toBe($password)
        ->and($tenant->users()->whereKey($user->id)->exists())->toBeTrue();
});

test('read only membership audit works before lifecycle migrations to support a safe release preflight', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($owner->id);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $migration = require database_path('migrations/landlord/2026_11_20_155153_add_membership_lifecycle_to_tenant_user_table.php');
    $migration->down();
    $before = Illuminate\Support\Facades\DB::connection('landlord')->table('tenant_user')->get()->toArray();
    $this->artisan('organisations:audit-memberships', ['--tenant' => $tenant->id, '--json' => true])->assertSuccessful();
    expect(Illuminate\Support\Facades\DB::connection('landlord')->table('tenant_user')->get()->toArray())->toEqual($before);
});
