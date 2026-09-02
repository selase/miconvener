<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Clear tenants storage if exists
    if (file_exists(storage_path('tenants'))) {
        $files = glob(storage_path('tenants/*.sqlite'));
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('creating a tenant with shared isolation mode does not create a database', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Superadmin');

    $response = $this->actingAs($admin)->post(route('tenants.store'), [
        'name' => 'Shared Tenant',
        'email' => 'shared@example.com',
        'phone_number' => '1234567890',
        'address' => '123 Shared St',
        'country' => 'USA',
        'city' => 'Shared City',
        'state_or_region' => 'Shared State',
        'zipcode' => '12345',
        'status' => 'active',
        'subdomain' => 'shared',
        'isolation_mode' => 'shared',
        'db_driver' => 'sqlite',
    ]);

    $response->assertRedirect(route('tenants.index'));

    $tenant = Tenant::where('slug', 'shared')->first();
    expect($tenant->isolation_mode)->toBe('shared');
    expect($tenant->meta['database'] ?? null)->toBeNull();
});

test('creating a tenant with dedicated isolation mode creates a sqlite database file', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Superadmin');

    $response = $this->actingAs($admin)->post(route('tenants.store'), [
        'name' => 'Dedicated Tenant',
        'email' => 'dedicated@example.com',
        'phone_number' => '1234567890',
        'address' => '123 Dedicated St',
        'country' => 'USA',
        'city' => 'Dedicated City',
        'state_or_region' => 'Dedicated State',
        'zipcode' => '54321',
        'status' => 'active',
        'subdomain' => 'dedicated',
        'isolation_mode' => 'db_per_tenant',
        'db_driver' => 'sqlite',
    ]);

    $response->assertRedirect(route('tenants.index'));

    $tenant = Tenant::where('slug', 'dedicated')->first();
    expect($tenant->isolation_mode)->toBe('db_per_tenant');
    expect($tenant->meta['database'])->not->toBeNull();
    expect(file_exists($tenant->meta['database']))->toBeTrue();

    // Check if migrations were run
    app(App\Services\Tenancy\TenantDatabaseManager::class)->configure($tenant);
    expect(Schema::connection('tenant')->hasTable('posts'))->toBeTrue();
});
