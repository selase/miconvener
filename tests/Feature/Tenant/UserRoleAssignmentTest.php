<?php

declare(strict_types=1);

use App\Models\OrganisationInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\OrganisationInvitations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
    Mail::fake();
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($this->admin->id);
    setPermissionsTeamId($this->tenant->id);
    $this->admin->assignRole('Org Admin');
});

test('tenant administrator cannot assign superadmin role through central or tenant routes', function (): void {
    $role = Role::query()->whereNull('tenant_id')->where('name', 'Superadmin')->firstOrFail();
    $this->actingAs($this->admin)->post(route('users.store'), [
        'first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@doe.com',
        'phone_no' => '+233201234567', 'role' => $role->id, 'roles' => [$role->id],
        'status' => 'active', 'tenant_id' => $this->tenant->id,
    ])->assertForbidden();
    $this->post($this->tenant->url('/users'), ['email' => 'john@doe.com', 'role' => $role->id])
        ->assertSessionHasErrors('role');
    expect(User::query()->where('email', 'john@doe.com')->exists())->toBeFalse()
        ->and(OrganisationInvitation::query()->count())->toBe(0);
});

test('tenant administrator can assign an organisation member role through an accepted invitation', function (): void {
    $role = Role::create(['name' => 'Org Member', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]);
    $this->actingAs($this->admin)->post($this->tenant->url('/users'), ['email' => 'jane@doe.com', 'role' => $role->id])
        ->assertRedirect();
    expect(User::query()->where('email', 'jane@doe.com')->exists())->toBeFalse();
    $invitation = OrganisationInvitation::query()->sole();
    expect($invitation->role_id)->toBe($role->id)->and($invitation->tenant_id)->toBe($this->tenant->id);
    $member = User::factory()->create(['email' => 'jane@doe.com']);
    app(OrganisationInvitations::class)->accept(Crypt::decryptString($invitation->delivery_payload), $member);
    setPermissionsTeamId($this->tenant->id);
    expect($member->fresh()->tenants()->whereKey($this->tenant->id)->exists())->toBeTrue()
        ->and($member->fresh()->hasRole('Org Member'))->toBeTrue();
});
