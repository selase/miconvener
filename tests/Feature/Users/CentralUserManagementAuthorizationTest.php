<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    setPermissionsTeamId(null);
    Mail::fake();
    Notification::fake();
    $this->platform = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    $this->platform->assignRole('Superadmin');
    $this->target = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    $this->organisation = Tenant::factory()->create(['isolation_mode' => 'shared']);
});

function centralIdentityPayload(User $user, string $status = User::STATUS_ACTIVE): array
{
    return [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
        'phone_no' => '+233201234567',
        'status' => $status,
        'roles' => [Role::query()->where('name', 'Org Admin')->firstOrFail()->id],
    ];
}

it('denies every central identity and team action to ordinary and tenant administrators', function (bool $owner): void {
    $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    if ($owner) {
        $this->organisation->users()->attach($actor);
        setPermissionsTeamId($this->organisation->id);
        $actor->assignRole('Org Superadmin');
    }
    $this->actingAs($actor);
    $user = $this->target->uuid;
    $tenant = $this->organisation->uuid;
    foreach ([
        ['GET', '/user-management/users'],
        ['GET', '/user-management/users/create'],
        ['POST', '/user-management/users/all'],
        ['POST', '/user-management/users'],
        ['GET', "/user-management/users/{$user}"],
        ['GET', "/user-management/users/{$user}/edit"],
        ['PUT', "/user-management/users/{$user}"],
        ['DELETE', "/user-management/users/{$user}"],
        ['PUT', "/user-management/users/{$user}/resend-password"],
        ['PUT', "/user-management/users/{$actor->uuid}"],
        ['GET', "/user-management/users/{$this->platform->uuid}/edit"],
        ['PUT', "/user-management/users/{$this->platform->uuid}/resend-password"],
        ['GET', "/tenants/{$tenant}/team"],
        ['POST', "/tenants/{$tenant}/team/all"],
        ['GET', "/tenants/{$tenant}/team/create"],
        ['POST', "/tenants/{$tenant}/team"],
        ['GET', "/tenants/{$tenant}/team/{$user}"],
        ['GET', "/tenants/{$tenant}/team/{$user}/edit"],
        ['PUT', "/tenants/{$tenant}/team/{$user}"],
        ['DELETE', "/tenants/{$tenant}/team/{$user}"],
    ] as [$method, $url]) {
        $this->json($method, $url, centralIdentityPayload($this->target))->assertForbidden();
    }
    expect($this->target->fresh())->not->toBeNull();
    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
})->with(['ordinary' => false, 'tenant owner' => true]);

it('refuses platform global deletion while any membership exists', function (): void {
    $this->organisation->users()->attach($this->target);
    $this->actingAs($this->platform)->deleteJson("/user-management/users/{$this->target->uuid}")->assertForbidden();
    expect($this->target->fresh())->not->toBeNull();
    expect($this->target->tenants()->count())->toBe(1);
});

it('refuses platform global deactivation while any membership exists', function (): void {
    $this->organisation->users()->attach($this->target);
    $this->actingAs($this->platform)->putJson("/user-management/users/{$this->target->uuid}", centralIdentityPayload($this->target, User::STATUS_INACTIVE))->assertForbidden();
    expect($this->target->fresh()->status)->toBe(User::STATUS_ACTIVE);
});

it('allows platform editing and deleting an identity without memberships', function (): void {
    $this->actingAs($this->platform)->getJson("/user-management/users/{$this->target->uuid}/edit")->assertSuccessful();
    $this->putJson("/user-management/users/{$this->target->uuid}", centralIdentityPayload($this->target, User::STATUS_INACTIVE))->assertSuccessful();
    expect($this->target->fresh()->status)->toBe(User::STATUS_INACTIVE);
    $this->deleteJson("/user-management/users/{$this->target->uuid}")->assertSuccessful();
    expect(User::query()->find($this->target->id))->toBeNull();
});

it('sends a native password reset link without rotating credentials or mailing plaintext', function (): void {
    $password = $this->target->password;
    $this->actingAs($this->platform)->putJson("/user-management/users/{$this->target->uuid}/resend-password")->assertSuccessful();
    expect($this->target->fresh()->password)->toBe($password);
    Notification::assertSentTo($this->target, ResetPassword::class);
    Mail::assertNothingOutgoing();
});

it('keeps workspace switch routes outside the platform management boundary', function (): void {
    foreach (['tenants.change', 'tenants.reset', 'tenant.switch'] as $name) {
        $route = app('router')->getRoutes()->getByName($name);
        if ($route !== null) {
            expect($route->gatherMiddleware())->not->toContain('can:access-superadmin-dashboard');
        }
    }
});

it('rejects platform and foreign roles on legacy tenant team creation', function (string $roleKind): void {
    $role = $roleKind === 'foreign'
        ? Role::query()->create(['name' => 'Foreign role', 'guard_name' => 'web', 'tenant_id' => Tenant::factory()->create()->id])
        : Role::query()->where('name', $roleKind)->firstOrFail();
    $this->actingAs($this->platform)->postJson("/tenants/{$this->organisation->uuid}/team", [
        'first_name' => 'New',
        'last_name' => 'Member',
        'email' => 'blocked-team@example.com',
        'phone_number' => '+233201234567',
        'roles' => [$role->id],
        'status' => User::STATUS_ACTIVE,
    ])->assertUnprocessable()->assertJsonValidationErrors('roles');
    expect(User::query()->where('email', 'blocked-team@example.com')->exists())->toBeFalse();
    Mail::assertNothingOutgoing();
})->with(['Superadmin', 'Platform Finance', 'Platform Marketing', 'foreign']);

it('protects identities with suspended or revoked memberships', function (string $status): void {
    $this->organisation->users()->attach($this->target, ['status' => $status]);
    $this->actingAs($this->platform)->deleteJson("/user-management/users/{$this->target->uuid}")->assertForbidden();
    $this->putJson("/user-management/users/{$this->target->uuid}", centralIdentityPayload($this->target, User::STATUS_INACTIVE))->assertForbidden();
    expect($this->target->fresh()->status)->toBe(User::STATUS_ACTIVE);
})->with(['suspended', 'revoked']);

it('throttles repeated recovery requests without rotating credentials', function (): void {
    $password = $this->target->password;
    $this->actingAs($this->platform)->putJson("/user-management/users/{$this->target->uuid}/resend-password")->assertSuccessful();
    $this->putJson("/user-management/users/{$this->target->uuid}/resend-password")->assertTooManyRequests();
    expect($this->target->fresh()->password)->toBe($password);
    Notification::assertSentToTimes($this->target, ResetPassword::class, 1);
});

it('rejects unlisted global roles on legacy tenant team creation', function (): void {
    $role = Role::query()->create(['name' => 'Other platform role', 'guard_name' => 'web', 'tenant_id' => null]);
    $this->actingAs($this->platform)->postJson("/tenants/{$this->organisation->uuid}/team", [
        'first_name' => 'New',
        'last_name' => 'Member',
        'email' => 'blocked-global-role@example.com',
        'phone_number' => '+233201234567',
        'roles' => [$role->id],
        'status' => User::STATUS_ACTIVE,
    ])->assertUnprocessable()->assertJsonValidationErrors('roles');
    expect(User::query()->where('email', 'blocked-global-role@example.com')->exists())->toBeFalse();
});

it('allows a platform administrator to preserve a target workspace custom role during identity editing', function (): void {
    $this->target->update(['tenant_id' => $this->organisation->id]);
    $this->organisation->users()->attach($this->target);
    $role = Role::query()->create(['name' => 'Workspace assistant', 'guard_name' => 'web', 'tenant_id' => $this->organisation->id]);
    setPermissionsTeamId($this->organisation->id);
    $this->target->assignRole($role);
    setPermissionsTeamId(null);
    $payload = centralIdentityPayload($this->target);
    $payload['roles'] = [$role->id];
    $this->actingAs($this->platform)->putJson("/user-management/users/{$this->target->uuid}", $payload)->assertSuccessful();
    setPermissionsTeamId($this->organisation->id);
    expect($this->target->fresh()->hasRole($role))->toBeTrue();
});

it('requires explicit recovery to change the email of an identity with any membership', function (string $membershipStatus): void {
    $this->target->forceFill(['security_version' => 7])->save();
    $this->organisation->users()->attach($this->target, ['status' => $membershipStatus]);
    $originalEmail = $this->target->email;
    $verifiedAt = $this->target->email_verified_at;
    $securityVersion = $this->target->security_version;
    $token = $this->target->createToken('existing-access')->accessToken;
    $payload = centralIdentityPayload($this->target);
    $payload['email'] = 'replacement@example.com';

    $this->actingAs($this->platform)->putJson("/user-management/users/{$this->target->uuid}", $payload)->assertForbidden();

    $this->target->refresh();
    expect($this->target->email)->toBe($originalEmail)
        ->and($this->target->email_verified_at->equalTo($verifiedAt))->toBeTrue()
        ->and($this->target->security_version)->toBe($securityVersion)
        ->and($this->target->tokens()->whereKey($token->id)->exists())->toBeTrue();
})->with(['active', 'suspended', 'revoked']);

it('invalidates verification and credentials when platform staff change a tenantless identity email', function (): void {
    $this->target->forceFill(['security_version' => 7, 'remember_token' => 'existing-remember-token'])->save();
    $oldIdentity = clone $this->target;
    $resetToken = Illuminate\Support\Facades\Password::createToken($this->target);
    $this->target->createToken('existing-access');
    $password = $this->target->password;
    $payload = centralIdentityPayload($this->target);
    $payload['email'] = 'replacement@example.com';

    $this->actingAs($this->platform)->putJson("/user-management/users/{$this->target->uuid}", $payload)->assertSuccessful();

    $this->target->refresh();
    expect($this->target->email)->toBe('replacement@example.com')
        ->and($this->target->email_verified_at)->toBeNull()
        ->and($this->target->security_version)->toBe(8)
        ->and($this->target->remember_token)->not->toBe('existing-remember-token')
        ->and($this->target->password)->toBe($password)
        ->and($this->target->tokens()->exists())->toBeFalse()
        ->and(Illuminate\Support\Facades\Password::tokenExists($oldIdentity, $resetToken))->toBeFalse();
});

it('preserves verification and credentials when a tenantless identity email is unchanged', function (): void {
    $this->target->forceFill(['security_version' => 7, 'remember_token' => 'existing-remember-token'])->save();
    $verifiedAt = $this->target->email_verified_at;
    $token = $this->target->createToken('existing-access')->accessToken;

    $this->actingAs($this->platform)->putJson("/user-management/users/{$this->target->uuid}", centralIdentityPayload($this->target))->assertSuccessful();

    $this->target->refresh();
    expect($this->target->email_verified_at->equalTo($verifiedAt))->toBeTrue()
        ->and($this->target->security_version)->toBe(7)
        ->and($this->target->remember_token)->toBe('existing-remember-token')
        ->and($this->target->tokens()->whereKey($token->id)->exists())->toBeTrue();
});

it('central account creation uses a consent invitation for an existing organisation identity', function (): void {
    $count = User::query()->count();
    $password = $this->target->password;
    $this->actingAs($this->platform)->postJson('/user-management/users', centralIdentityPayload($this->target) + ['tenant_id' => $this->organisation->id])->assertSuccessful();
    expect(User::query()->count())->toBe($count)
        ->and($this->target->fresh()->password)->toBe($password)
        ->and($this->target->tenants()->whereKey($this->organisation->id)->exists())->toBeFalse()
        ->and(App\Models\OrganisationInvitation::query()->where('tenant_id', $this->organisation->id)->where('email', $this->target->email)->where('status', 'pending')->exists())->toBeTrue();
});

it('central identity editing cannot demote an organisations final owner', function (): void {
    $this->target->forceFill(['tenant_id' => $this->organisation->id])->save();
    $this->target->tenants()->attach($this->organisation->id);
    setPermissionsTeamId($this->organisation->id);
    $this->target->assignRole('Org Superadmin');
    setPermissionsTeamId(null);
    $this->actingAs($this->platform)->putJson('/user-management/users/'.$this->target->uuid, centralIdentityPayload($this->target))->assertForbidden();
    setPermissionsTeamId($this->organisation->id);
    expect($this->target->fresh()->hasRole('Org Superadmin'))->toBeTrue();
});

it('platform tenant invitations accept only the chosen organisations custom role', function (): void {
    $role = Role::query()->create(['name' => 'Desk assistant', 'guard_name' => 'web', 'tenant_id' => $this->organisation->id]);
    $payload = centralIdentityPayload($this->target) + ['tenant_id' => $this->organisation->id];
    $payload['roles'] = [$role->id];
    $this->actingAs($this->platform)->postJson('/user-management/users', $payload)->assertSuccessful();
    expect(App\Models\OrganisationInvitation::query()->where('tenant_id', $this->organisation->id)->where('email', $this->target->email)->value('role_id'))->toBe($role->id);
});

it('global suspension preserves both memberships when every active organisation retains another owner', function (): void {
    $other = Tenant::factory()->create();
    $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    $this->target->forceFill(['tenant_id' => $this->organisation->id])->save();
    foreach ([$this->organisation, $other] as $tenant) {
        $tenant->users()->attach([$this->target->id, $owner->id]);
        setPermissionsTeamId($tenant->id);
        $owner->unsetRelation('roles')->assignRole('Org Superadmin');
        $this->target->unsetRelation('roles')->assignRole('Org Admin');
    }
    $this->target->createToken('before-global-suspension');
    $password = $this->target->password;
    setPermissionsTeamId(null);
    $this->actingAs($this->platform)->putJson('/user-management/users/'.$this->target->uuid, centralIdentityPayload($this->target, User::STATUS_INACTIVE))->assertSuccessful();
    $user = $this->target->fresh();
    expect($user->status)->toBe(User::STATUS_INACTIVE)->and($user->security_version)->toBe(1)
        ->and($user->password)->toBe($password)->and($user->tokens()->count())->toBe(0)->and($user->allTenants()->count())->toBe(2);
    foreach ([$this->organisation, $other] as $tenant) {
        expect(app(App\Services\Tenancy\OrganisationMemberships::class)->isActive($user, $tenant))->toBeFalse();
    }
    $this->putJson('/user-management/users/'.$user->uuid, centralIdentityPayload($user))->assertSuccessful();
    expect($user->fresh()->security_version)->toBe(1);
    setPermissionsTeamId($other->id);
    expect($user->fresh()->hasRole('Org Admin'))->toBeTrue();
});

it('an explicitly paused organisation permits global suspension of its sole owner', function (): void {
    $this->organisation->forceFill(['status' => App\Enum\TenantStatusEnum::DEACTIVATED])->save();
    $this->target->forceFill(['tenant_id' => $this->organisation->id])->save();
    $this->target->tenants()->attach($this->organisation->id);
    setPermissionsTeamId($this->organisation->id);
    $this->target->assignRole('Org Superadmin');
    $payload = centralIdentityPayload($this->target, User::STATUS_INACTIVE);
    $payload['roles'] = [Role::query()->where('name', 'Org Superadmin')->firstOrFail()->id];
    setPermissionsTeamId(null);
    $this->actingAs($this->platform)->putJson('/user-management/users/'.$this->target->uuid, $payload)->assertSuccessful();
    expect($this->target->fresh()->status)->toBe(User::STATUS_INACTIVE)->and($this->target->allTenants()->count())->toBe(1);
});

it('rejects malformed central role selections without a server error', function (mixed $roles): void {
    $payload = centralIdentityPayload($this->target);
    $payload['roles'] = $roles;
    $this->actingAs($this->platform)
        ->putJson("/user-management/users/{$this->target->uuid}", $payload)
        ->assertUnprocessable();
})->with(['string' => ['invalid'], 'nested' => [[[1]]]]);
