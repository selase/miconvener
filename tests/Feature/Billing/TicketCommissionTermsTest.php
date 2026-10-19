<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

/**
 * Ticket commission has no cap on any plan (owner, 2026-10-19). MiConvener can
 * still give one organisation a cap, or a different rate, as a deal; that is a
 * permission platform staff can be granted, never something an organisation
 * can do for itself.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
});

function platformStaff(string $role, array $extraPermissions = []): User
{
    $user = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $model = Role::query()->where('name', $role)->whereNull('tenant_id')->firstOrFail();
    foreach ($extraPermissions as $permission) {
        $model->givePermissionTo(Permission::findByName($permission));
    }
    $user->assignRole($model);

    return $user;
}

test('no plan caps the commission on ticket sales', function (): void {
    expect(Package::query()->whereNotNull('default_platform_fee_cap_amount')->pluck('slug')->all())->toBe([])
        ->and(config('services.platform.default_fee_cap_amount'))->toBeNull();
});

test('a superadmin gives one organisation a ticket cap and rate, and the change is recorded', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);

    $this->actingAs(platformStaff('Superadmin'))
        ->post(route('tenants.ticket-terms.update', $tenant->uuid), ['rate' => '2.5', 'cap' => '30', 'reason' => 'Volume deal for the national congress'])
        ->assertSessionHas('success');

    expect((float) $tenant->fresh()->platform_fee_percentage)->toBe(2.5)
        ->and($tenant->fresh()->platform_fee_cap_amount)->toBe(3000)
        ->and(Spatie\Activitylog\Models\Activity::query()->where('subject_id', $tenant->id)->where('description', 'like', '%ticket terms%')->exists())->toBeTrue();
});

test('blank values clear the organisation deal so the plan applies again', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'platform_fee_percentage' => 2.5, 'platform_fee_cap_amount' => 3000]);

    $this->actingAs(platformStaff('Superadmin'))
        ->post(route('tenants.ticket-terms.update', $tenant->uuid), ['rate' => '', 'cap' => '', 'reason' => 'Deal ended'])
        ->assertSessionHas('success');

    expect($tenant->fresh()->platform_fee_percentage)->toBeNull()->and($tenant->fresh()->platform_fee_cap_amount)->toBeNull();
});

test('platform finance needs the manage commercial terms permission to change ticket terms', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $payload = ['rate' => '2', 'cap' => '25', 'reason' => 'Deal'];

    $this->actingAs(platformStaff('Platform Finance'))
        ->post(route('tenants.ticket-terms.update', $tenant->uuid), $payload)
        ->assertForbidden();

    $this->actingAs(platformStaff('Platform Finance', ['manage commercial terms']))
        ->post(route('tenants.ticket-terms.update', $tenant->uuid), $payload)
        ->assertSessionHas('success');

    expect($tenant->fresh()->platform_fee_cap_amount)->toBe(2500);
});

test('an organisation can never grant itself the permission or use it', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $tenant->users()->attach($owner->id);

    expect(App\Models\Permission::TENANT_SAFE)->not->toContain('manage commercial terms');

    $this->actingAs($owner)
        ->post(route('tenants.ticket-terms.update', $tenant->uuid), ['rate' => '0', 'cap' => '1', 'reason' => 'Free'])
        ->assertForbidden();

    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');
    $this->actingAs($owner)
        ->post("http://{$host}/settings/payments/platform-fee", ['platform_fee_percentage' => 0], ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('the organisation page offers the ticket terms form only to staff who may use it', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);

    $this->actingAs(platformStaff('Superadmin'))
        ->get(route('tenants.show', $tenant->uuid))
        ->assertOk()
        ->assertSee(route('tenants.ticket-terms.update', $tenant->uuid), false);

    $this->actingAs(platformStaff('Platform Finance', ['manage commercial terms']))
        ->get(route('tenants.show', $tenant->uuid))
        ->assertOk()
        ->assertSee(route('tenants.ticket-terms.update', $tenant->uuid), false);
});
