<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('inviting a team member beyond the tenant\'s team-seat limit is rejected', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme3', 'isolation_mode' => 'shared']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $admin->assignRole('Org Superadmin');
    $tenant->users()->attach($admin->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'team_seats',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 1],
    ]);

    $host = 'acme3.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($admin)->post("http://{$host}/users", [
        'first_name' => 'New',
        'last_name' => 'Member',
        'email' => 'newmember@example.com',
        'phone_no' => '+233201234567',
        'status' => 'active',
        'role' => \Spatie\Permission\Models\Role::where('name', 'Org Superadmin')->first()?->id,
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors();
    expect(User::where('email', 'newmember@example.com')->exists())->toBeFalse();
});
