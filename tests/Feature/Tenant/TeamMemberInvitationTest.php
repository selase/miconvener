<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Mail\Users\OrganisationInvitationMail;
use App\Models\OrganisationInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\OrganisationInvitations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('inviting a team member defers identity and scoped permissions until verified acceptance', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme', 'isolation_mode' => 'shared']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);

    setPermissionsTeamId($tenant->id);
    $admin->assignRole('Org Admin');
    $tenant->users()->attach($admin->id);

    $role = Role::where('name', 'Org Admin')->whereNull('tenant_id')->firstOrFail();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $subdomainHost = "acme.{$baseDomain}";

    $response = $this->actingAs($admin)
        ->post("http://{$subdomainHost}/users", [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone_no' => '+233201234567',
            'role' => $role->id,
            'status' => 'active',
        ], ['HTTP_HOST' => $subdomainHost]);

    $response->assertRedirect(route('tenant.users.index', ['subdomain' => 'acme']));

    $invitation = OrganisationInvitation::query()->where('email', 'john.doe@example.com')->sole();
    expect($invitation->tenant_id)->toBe($tenant->id)
        ->and($invitation->role_id)->toBe($role->id)
        ->and($invitation->status)->toBe('pending')
        ->and($invitation->delivery_status)->toBe('pending')
        ->and($invitation->invited_by)->toBe((int) $admin->id)
        ->and(User::where('email', $invitation->email)->exists())->toBeFalse()
        ->and($tenant->users()->count())->toBe(1);
    $token = Crypt::decryptString($invitation->delivery_payload);
    expect($invitation->token_hash)->toBe(hash('sha256', $token));
    $mail = new OrganisationInvitationMail($invitation->id, $invitation->revision);
    expect($mail->afterCommit)->toBeTrue();
    expect($mail->render())->toContain('Acme Corp', mb_rtrim((string) config('app.url'), '/').'/organisation-invitations/'.$invitation->id.'#'.$token);

    $newMember = User::factory()->create(['email' => $invitation->email, 'email_verified_at' => now()]);
    $membership = app(OrganisationInvitations::class)->accept($token, $newMember);
    expect($membership->status)->toBe('active')
        ->and($invitation->fresh()->status)->toBe('accepted')
        ->and($tenant->users()->where('users.id', $newMember->id)->exists())->toBeTrue();

    // 3. Verify permissions are properly scoped to the tenant
    setPermissionsTeamId($tenant->id);
    $newMemberRoles = $newMember->roles()->get();
    expect($newMemberRoles->pluck('name'))->toContain('Org Admin');

    $modelHasRole = DB::table('model_has_roles')
        ->where('model_id', $newMember->id)
        ->where('tenant_id', $tenant->id)
        ->first();
    expect($modelHasRole)->not->toBeNull();

    // Org Admin permissions in this tenant
    expect($newMember->can('create event'))->toBeTrue();
    expect($newMember->can('read user'))->toBeTrue();
    expect($newMember->can('access dashboard'))->toBeTrue();

    // 4. Verify user has NO permissions in another tenant
    $otherTenant = Tenant::factory()->create(['slug' => 'othercorp', 'isolation_mode' => 'shared']);
    setPermissionsTeamId($otherTenant->id);
    $otherMember = $newMember->fresh();
    expect($otherMember->roles()->count())->toBe(0);
    expect($otherMember->hasRole('Org Admin'))->toBeFalse();
    expect($otherMember->hasPermissionTo('create event'))->toBeFalse();
});

test('an organisation with an active custom domain still creates a pending consent invitation', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create([
        'name' => 'Custom Tenant',
        'slug' => 'customcorp',
        'isolation_mode' => 'shared',
        'custom_domain' => 'events.customcorp.com',
        'custom_domain_status' => 'active',
    ]);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);

    setPermissionsTeamId($tenant->id);
    $admin->assignRole('Org Admin');
    $tenant->users()->attach($admin->id);

    $role = Role::where('name', 'Org Admin')->whereNull('tenant_id')->firstOrFail();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $subdomainHost = "customcorp.{$baseDomain}";

    $this->actingAs($admin)
        ->post("http://{$subdomainHost}/users", [
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@example.com',
            'phone_no' => '+233201234568',
            'role' => $role->id,
            'status' => 'active',
        ], ['HTTP_HOST' => $subdomainHost])
        ->assertRedirect();

    $invitation = OrganisationInvitation::query()->where('email', 'alice@example.com')->sole();
    expect($invitation->tenant_id)->toBe($tenant->id)
        ->and($invitation->status)->toBe('pending')
        ->and($invitation->delivery_status)->toBe('pending')
        ->and($invitation->role_id)->toBe($role->id)
        ->and($invitation->delivery_payload)->not->toBeNull()
        ->and(User::where('email', $invitation->email)->exists())->toBeFalse()
        ->and($tenant->users()->count())->toBe(1);
    $mail = new OrganisationInvitationMail($invitation->id, $invitation->revision);
    expect($mail->afterCommit)->toBeTrue();
    expect($mail->render())->toContain('Custom Tenant', mb_rtrim((string) config('app.url'), '/').'/organisation-invitations/'.$invitation->id.'#'.Crypt::decryptString($invitation->delivery_payload));

});

test('org admin cannot see or assign either superadmin role', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'role-boundary', 'isolation_mode' => 'shared']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $admin->assignRole('Org Admin');
    $tenant->users()->attach($admin->id);

    $host = 'role-boundary.'.mb_ltrim((string) config('session.domain'), '.');
    $orgSuperadminRole = Role::whereNull('tenant_id')->where('name', 'Org Superadmin')->firstOrFail();

    $this->actingAs($admin)
        ->get("http://{$host}/users", ['HTTP_HOST' => $host])
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles) => $roles->pluck('name')->contains('Org Admin')
                && ! $roles->pluck('name')->contains('Org Superadmin')
                && ! $roles->pluck('name')->contains('Superadmin')));

    $this->actingAs($admin)
        ->post("http://{$host}/users", [
            'first_name' => 'Escalated',
            'last_name' => 'Member',
            'email' => 'escalated@example.com',
            'phone_no' => '+233201234569',
            'role' => $orgSuperadminRole->id,
            'status' => 'active',
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('role');

    expect(User::where('email', 'escalated@example.com')->exists())->toBeFalse();
    expect(OrganisationInvitation::query()->where('email', 'escalated@example.com')->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

test('org admin cannot edit or remove an org superadmin', function () {
    $tenant = Tenant::factory()->create(['slug' => 'protected-owner', 'isolation_mode' => 'shared']);
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $admin->assignRole('Org Admin');
    $tenant->users()->attach([$owner->id, $admin->id]);
    $secondOwner = User::factory()->create(['tenant_id' => $tenant->id]);
    $secondOwner->assignRole('Org Superadmin');
    $tenant->users()->attach($secondOwner->id);

    $host = 'protected-owner.'.mb_ltrim((string) config('session.domain'), '.');
    $orgAdminRole = Role::whereNull('tenant_id')->where('name', 'Org Admin')->firstOrFail();

    $this->actingAs($admin)
        ->put("http://{$host}/users/{$owner->uuid}", [
            'first_name' => $owner->first_name,
            'last_name' => $owner->last_name,
            'email' => $owner->email,
            'phone_no' => $owner->phone_no,
            'role' => $orgAdminRole->id,
            'status' => 'active',
        ], ['HTTP_HOST' => $host])
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete("http://{$host}/users/{$owner->uuid}", [], ['HTTP_HOST' => $host])
        ->assertForbidden();

    expect($owner->fresh()->hasRole('Org Superadmin'))->toBeTrue();
    expect($tenant->users()->whereKey($owner->id)->firstOrFail()->pivot->status)->toBe('active');
});

test('the final active org superadmin cannot be demoted or removed', function () {
    $tenant = Tenant::factory()->create(['slug' => 'last-owner', 'isolation_mode' => 'shared']);
    // The owner guard requires both an active identity and active membership.
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'phone_no' => '+233201234570',
        'status' => User::STATUS_ACTIVE,
    ]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $tenant->users()->attach($owner->id);

    $host = 'last-owner.'.mb_ltrim((string) config('session.domain'), '.');
    $orgAdminRole = Role::whereNull('tenant_id')->where('name', 'Org Admin')->firstOrFail();

    $this->actingAs($owner)
        ->put("http://{$host}/users/{$owner->uuid}", [
            'first_name' => $owner->first_name,
            'last_name' => $owner->last_name,
            'email' => $owner->email,
            'phone_no' => $owner->phone_no,
            'role' => $orgAdminRole->id,
            'status' => 'active',
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('role');

    $this->actingAs($owner)
        ->delete("http://{$host}/users/{$owner->uuid}", [], ['HTTP_HOST' => $host])
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    expect($owner->fresh()->hasRole('Org Superadmin'))->toBeTrue();
    expect($tenant->users()->whereKey($owner->id)->firstOrFail()->pivot->status)->toBe('active');
});

test('inviting a team member does not provision or validate global phone identity', function (string $typed) {
    Mail::fake();

    $tenant = Tenant::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme', 'isolation_mode' => 'shared']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $admin->assignRole('Org Admin');
    $tenant->users()->attach($admin->id);
    $role = Role::where('name', 'Org Admin')->whereNull('tenant_id')->firstOrFail();
    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($admin)->post("http://{$host}/users", [
        'first_name' => 'Richard', 'last_name' => 'Kwawu', 'email' => 'richard@example.com',
        'phone_no' => $typed, 'role' => $role->id, 'status' => 'active',
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasNoErrors();
    expect(User::where('email', 'richard@example.com')->exists())->toBeFalse();
    $invitation = OrganisationInvitation::query()->where('email', 'richard@example.com')->sole();
    expect($invitation->status)->toBe('pending')->and($invitation->role_id)->toBe($role->id);
})->with([
    'US with 00 prefix' => ['0013018201664'],
    'US with plus and spaces' => ['+1 301 820 1664'],
    'UK' => ['+44 7911 123456'],
    'Ghana local' => ['0201234567'],
    'not a number' => ['hello'],
]);

test('team role selections reject arrays on create and update', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create(['slug' => 'role-input', 'isolation_mode' => 'shared']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $subject = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach([$admin->id, $subject->id]);
    setPermissionsTeamId($tenant->id);
    $admin->assignRole('Org Superadmin');
    $subject->assignRole('Org Admin');
    $host = 'role-input.'.mb_ltrim((string) config('session.domain'), '.');
    $this->actingAs($admin)->postJson("http://{$host}/users", [
        'email' => 'invalid-role@example.com', 'role' => [1],
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->putJson("http://{$host}/users/{$subject->uuid}", [
        'role' => [1], 'status' => 'active',
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('role');
    Mail::assertNothingOutgoing();
});
