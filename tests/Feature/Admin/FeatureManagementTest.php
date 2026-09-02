<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Role;
use App\Models\User;

test('superadmin can create a feature', function () {
    $superadmin = User::factory()->create();
    $role = Role::create(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    $response = $this->actingAs($superadmin)
        ->post(route('features.store'), [
            'name' => 'New Feature',
            'slug' => 'new-feature',
            'type' => 'boolean',
            'description' => 'A test feature',
        ]);

    $response->assertRedirect(route('features.index'));
    $this->assertDatabaseHas('features', ['slug' => 'new-feature']);
});

test('feature index returns datatables json', function () {
    $superadmin = User::factory()->create();
    $role = Role::create(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    Feature::create([
        'name' => 'Existing Feature',
        'slug' => 'existing-feature',
        'type' => 'boolean',
    ]);

    $response = $this->actingAs($superadmin)
        ->getJson(route('features.index'), ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertStatus(200)
        ->assertJsonFragment(['name' => 'Existing Feature']);
});
