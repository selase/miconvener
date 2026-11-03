<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Events\Profiles\ProfileEligibility;
use App\Services\Events\Profiles\ProfilePublication;
use Database\Seeders\PermissionsSeeder;

require_once __DIR__.'/../../Support/PublicAttendeeProfileScenario.php';

function attendeeProfileStaff($tenant): User
{
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $role = Role::firstOrCreate(['name' => 'Profile reviewer', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $role->givePermissionTo('manage public attendee profiles');
    $user->assignRole($role);

    return $user;
}

it('requires a dedicated tenant permission and exact event ownership', function (): void {
    [$tenant, $event] = attendeeProfileScenario();
    $this->seed(PermissionsSeeder::class);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->getJson(route('tenant.events.public-profiles.index', ['subdomain' => $tenant->slug, 'event' => $event->id]))->assertForbidden();
});

it('lets authorised staff disable an event switch and hide but never publish an attendee', function (): void {
    [$tenant, $event, $registration, $session] = attendeeProfileScenario();
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    $this->seed(PermissionsSeeder::class);
    $user = attendeeProfileStaff($tenant);
    $base = ['subdomain' => $tenant->slug, 'event' => $event->id];
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    $this->postJson(route('tenant.events.public-profiles.hide', $base + ['profile' => $profile->id]), ['reason' => 'Requested privacy review'])->assertOk();
    expect($profile->fresh()->state)->toBe('hidden')->and(app(ProfileEligibility::class)->visible($profile))->toBeFalse();
    expect(fn () => app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput()))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->patchJson(route('tenant.events.public-profiles.switch', $base), ['enabled' => false])->assertOk();
    expect($event->fresh()->public_attendee_profiles)->toBeFalse();
});

it('never makes platform moderation a tenant-safe permission', function (): void {
    $this->seed(PermissionsSeeder::class);
    expect(Permission::TENANT_SAFE)->not->toContain('moderate public attendee profiles');
    expect(Permission::query()->where('name', 'moderate public attendee profiles')->exists())->toBeTrue();
});

it('refuses a foreign event even with the local profile permission', function (): void {
    [$tenant] = attendeeProfileScenario();
    $foreign = App\Models\Event::factory()->create();
    $this->seed(PermissionsSeeder::class);
    $user = attendeeProfileStaff($tenant);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->getJson(route('tenant.events.public-profiles.index', ['subdomain' => $tenant->slug, 'event' => $foreign->id]))->assertNotFound();
});

it('releases a hold only through platform moderation without republishing old consent', function (): void {
    [$tenant, , $registration, $session] = attendeeProfileScenario();
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    $this->seed([Database\Seeders\RoleSeeder::class, PermissionsSeeder::class]);
    $staff = attendeeProfileStaff($tenant);
    app(App\Services\Events\Profiles\ProfileModeration::class)->change($profile, $staff, 'Review requested');
    $url = route('admin.attendee-profiles.release', ['tenant' => $tenant->id, 'profile' => $profile->id]);
    $this->actingAs($staff)->post($url, ['reason' => 'All reviewed'])->assertForbidden();
    setPermissionsTeamId(null);
    $platform = User::factory()->create();
    $platform->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
    $this->actingAs($platform)->post($url, ['reason' => 'Owner has resolved the concern'])->assertRedirect();
    expect($profile->fresh()->state)->toBe('private')->and($profile->fresh()->moderated_at)->toBeNull()
        ->and(app(ProfileEligibility::class)->visible($profile))->toBeFalse();
});
