<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;

test('superadmin can create package with features', function () {
    $superadmin = User::factory()->create();
    $role = Role::create(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    $feature = Feature::create(['name' => 'F1', 'slug' => 'f1', 'type' => 'limit']);

    $response = $this->actingAs($superadmin)
        ->post(route('packages.store'), [
            'name' => 'Gold Plan',
            'slug' => 'gold-plan',
            'price' => 99.00,
            'interval' => 'month',
            'billing_model' => 'flat_rate',
            'is_active' => '1',
            'features' => [
                $feature->id => [
                    'enabled' => '1', // checked
                    'value' => '50',
                ],
            ],
        ]);

    $response->assertRedirect(route('packages.index'));

    $this->assertDatabaseHas('packages', ['slug' => 'gold-plan']);

    $package = Package::where('slug', 'gold-plan')->first();
    expect($package->features)->toHaveCount(1);
    expect($package->features->first()->pivot->value)->toBe('50');
});

test('package index returns datatables json', function () {
    $superadmin = User::factory()->create();
    $role = Role::create(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    Package::create([
        'name' => 'Silver Plan',
        'slug' => 'silver-plan',
        'price' => 49.00,
        'interval' => 'month',
    ]);

    $response = $this->actingAs($superadmin)
        ->getJson(route('packages.index'), ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertStatus(200)
        ->assertJsonFragment(['name' => 'Silver Plan']);
});
