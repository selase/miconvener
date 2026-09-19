<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Mail\Users\SendAccountDetails;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('adding a team member sends an email with dynamic tenant-resolved login url and sets permissions correctly', function () {
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

    // 1. Verify user was created and attached to tenant
    $newMember = User::where('email', 'john.doe@example.com')->firstOrFail();
    expect($newMember->tenant_id)->toBe($tenant->id);
    expect($tenant->users()->where('users.id', $newMember->id)->exists())->toBeTrue();

    // 2. Verify email was queued with tenant-resolved login URL
    Mail::assertQueued(SendAccountDetails::class, function (SendAccountDetails $mail) use ($tenant) {
        $expectedLoginUrl = $tenant->url('/login');

        expect($mail->hasTo('john.doe@example.com'))->toBeTrue();
        expect($mail->loginUrl)->toBe($expectedLoginUrl);
        expect($mail->loginUrl)->toContain('acme.');
        expect($mail->tenantName)->toBe('Acme Corp');

        $rendered = $mail->render();
        expect($rendered)->toContain('href="'.$expectedLoginUrl.'"');
        expect($rendered)->toContain('Acme Corp on '.config('app.name'));

        return true;
    });

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

test('adding a team member resolves custom domain login url if tenant has active custom domain', function () {
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

    Mail::assertQueued(SendAccountDetails::class, function (SendAccountDetails $mail) {
        expect($mail->loginUrl)->toBe('https://events.customcorp.com/login');
        $rendered = $mail->render();
        expect($rendered)->toContain('href="https://events.customcorp.com/login"');

        return true;
    });
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
});

test('the final active org superadmin cannot be demoted or removed', function () {
    $tenant = Tenant::factory()->create(['slug' => 'last-owner', 'isolation_mode' => 'shared']);
    // An explicit valid number: the factory's phone fails the Phone rule, so
    // this request used to stop at validation and never reach the owner check.
    // The status matters too -- the guard only counts *active* owners.
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
        ->assertSessionHas('error');

    expect($owner->fresh()->hasRole('Org Superadmin'))->toBeTrue();
});
