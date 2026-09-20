<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * An unknown role id is a bad request, not a broken server.
 *
 * Role::findById() throws RoleDoesNotExist rather than returning null, so the
 * `if (! $role)` guard written after it never ran and the exception escaped the
 * validator as a 500. Anyone submitting the form with a stale role id saw a
 * crash instead of "the selected role is invalid".
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    Mail::fake();

    $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    $this->admin->assignRole('Superadmin');
});

/**
 * @return array<string, mixed>
 */
function newUserPayload(array $roles): array
{
    return [
        'first_name' => 'Kofi',
        'last_name' => 'Boateng',
        'email' => 'kofi@example.com',
        'phone_no' => '+233201234567',
        'status' => User::STATUS_ACTIVE,
        'roles' => $roles,
    ];
}

test('creating a user with an unknown role fails validation instead of crashing', function () {
    $this->actingAs($this->admin)
        ->post('/user-management/users', newUserPayload([999999]))
        ->assertSessionHasErrors('roles');

    expect(User::where('email', 'kofi@example.com')->exists())->toBeFalse();
});

test('updating a user with an unknown role fails validation instead of crashing', function () {
    $target = User::factory()->create(['status' => User::STATUS_ACTIVE]);

    $this->actingAs($this->admin)
        ->put("/user-management/users/{$target->uuid}", [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'email' => $target->email,
            'phone_no' => '+233201234567',
            'status' => User::STATUS_ACTIVE,
            'roles' => [999999],
        ])
        ->assertSessionHasErrors('roles');
});

test('a real role is still accepted', function () {
    $role = \Spatie\Permission\Models\Role::where('name', 'Org Superadmin')->firstOrFail();

    $this->actingAs($this->admin)
        ->post('/user-management/users', newUserPayload([$role->id]))
        ->assertSessionHasNoErrors();

    expect(User::where('email', 'kofi@example.com')->exists())->toBeTrue();
});
