<?php

declare(strict_types=1);

use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

it('creates the system event organiser through the deploy seeder and preserves revoked defaults and identifiers', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionsSeeder::class);
    Role::query()->whereNull('tenant_id')->where('name', 'Event Organiser')->delete();
    $this->seed(PermissionsSeeder::class);
    $role = Role::query()->whereNull('tenant_id')->where('name', 'Event Organiser')->firstOrFail();
    expect($role->isSystemRole())->toBeTrue()
        ->and($role->hasPermissionTo('update event'))->toBeTrue()
        ->and($role->hasPermissionTo('issue certificates'))->toBeTrue()
        ->and($role->hasPermissionTo('create notification-rule'))->toBeTrue();
    foreach (['create event', 'delete event', 'read finance', 'manage billing', 'manage members', 'manage venue', 'read user', 'manage organization settings', 'manage store orders'] as $permission) {
        expect($role->hasPermissionTo($permission))->toBeFalse();
    }
    $uuid = $role->uuid;
    $role->revokePermissionTo('update event');
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($role->fresh()->uuid)->toBe($uuid)
        ->and($role->fresh()->hasPermissionTo('update event'))->toBeFalse();
});

it('limits assignment semantics to real built-in roles', function () {
    $this->seed(RoleSeeder::class);
    $system = Role::query()->whereNull('tenant_id')->where('name', 'Event Organiser')->firstOrFail();
    $custom = new Role;
    $custom->name = 'Event Organiser';
    $custom->tenant_id = 'tenant-custom';
    expect($system->isEventScoped())->toBeTrue()
        ->and($custom->isEventScoped())->toBeFalse()
        ->and($custom->isSystemRole())->toBeFalse();
});

it('synchronises team assignments only for the system organiser role', function (bool $custom) {
    Illuminate\Support\Facades\Mail::fake();
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionsSeeder::class);
    $tenant = App\Models\Tenant::factory()->create(['slug' => 'organiser-team', 'isolation_mode' => 'shared']);
    $event = App\Models\Event::factory()->create(['tenant_id' => $tenant->id]);
    $owner = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $tenant->users()->attach($owner->id);
    $role = Role::query()->whereNull('tenant_id')->where('name', 'Event Organiser')->firstOrFail();
    if ($custom) {
        $role = Role::query()->create(['name' => 'Event Organiser', 'tenant_id' => $tenant->id, 'guard_name' => 'web', 'slug' => 'custom_organiser']);
    }
    $host = 'http://organiser-team.'.mb_ltrim((string) config('session.domain'), '.');
    $payload = [
        'first_name' => 'Kojo', 'last_name' => 'Organiser', 'email' => 'organiser@example.test',
        'phone_no' => '0241234567', 'role' => $role->id, 'status' => 'active',
    ];
    $this->actingAs($owner)->post($host.'/users', $payload + ['event_ids' => [$event->id]])->assertSessionHasNoErrors();
    $organiser = App\Models\User::query()->where('email', 'organiser@example.test')->firstOrFail();
    expect(App\Models\EventStaffAssignment::query()->where('user_id', $organiser->id)->pluck('event_id')->all())->toBe($custom ? [] : [$event->id]);
    $this->actingAs($owner)->put($host.'/users/'.$organiser->uuid, $payload + ['event_ids' => [$event->id]])->assertSessionHasNoErrors();
    expect(App\Models\EventStaffAssignment::query()->where('user_id', $organiser->id)->pluck('event_id')->all())->toBe($custom ? [] : [$event->id]);
    $this->actingAs($owner)->put($host.'/users/'.$organiser->uuid, $payload + ['event_ids' => []])->assertSessionHasNoErrors();
    expect(App\Models\EventStaffAssignment::query()->where('user_id', $organiser->id)->count())->toBe(0);
})->with([false, true]);
