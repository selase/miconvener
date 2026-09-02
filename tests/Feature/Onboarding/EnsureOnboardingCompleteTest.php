<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\User;
use Illuminate\Support\Facades\Session;

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->tenant = setActiveTenantForTest($this->user, [
        'status' => TenantStatusEnum::ACTIVE,
        'onboarding_completed_at' => null,
    ]);

    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);

    $this->user->assignRole('Org Admin');
});

test('it does not force redirect but sets session flag when onboarding is incomplete', function () {
    $this->actingAs($this->user);

    // setActiveTenantForTest already puts it in session
    $response = $this->get('/dashboard');

    $response->assertStatus(200);
    $response->assertSessionHas('onboarding_incomplete', true);
});

test('it does not set flag when onboarding is complete', function () {
    $this->tenant->update(['onboarding_completed_at' => now()]);

    $this->actingAs($this->user);

    $response = $this->get('/dashboard');

    $response->assertStatus(200);
    $response->assertSessionMissing('onboarding_incomplete');
});

test('it skips check for superadmins', function () {
    $superAdmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole('Superadmin');

    $this->actingAs($superAdmin);

    $response = $this->get('/dashboard');

    $response->assertStatus(200);
    $response->assertSessionMissing('onboarding_incomplete');
});
