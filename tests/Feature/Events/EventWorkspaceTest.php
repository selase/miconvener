<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\EventSections;
use Illuminate\Support\Facades\Artisan;

/**
 * The event workspace: 25 sections grouped by job, each shown only to people
 * who can open it.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * @param  list<string>|string  $roleOrPermissions  a system role name, or permissions for a custom role
 * @return array{0: Tenant, 1: User, 2: Event, 3: string}
 */
function workspaceSetup(array|string $roleOrPermissions, string $slug = 'workspace'): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    setPermissionsTeamId($tenant->id);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    if (is_string($roleOrPermissions)) {
        $user->assignRole($roleOrPermissions);
    } else {
        $role = Role::create(['name' => 'Custom', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
        $role->givePermissionTo($roleOrPermissions);
        $user->assignRole($role);
    }
    $tenant->users()->attach($user->id);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $user, $event, "{$slug}.".mb_ltrim((string) config('session.domain'), '.')];
}

function menuSlugs(array $menu): array
{
    return collect($menu)->flatMap(fn (array $group): array => collect($group['sections'])->pluck('slug')->all())->all();
}

test('an owner sees every section, grouped by job', function () {
    [, $user, $event, $host] = workspaceSetup('Org Superadmin');

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Events/Show')
            ->where('section', 'overview')
            ->where('sections', function ($menu): bool {
                $groups = collect($menu)->pluck('name')->all();

                return $groups === [null, 'Registration', 'Programme', 'Engagement', 'Event day', 'Logistics', 'Results']
                    && count(menuSlugs($menu->all())) === count(EventSections::slugs());
            }));
});

test('someone who can only read events sees only what they can open', function () {
    [, $user, $event, $host] = workspaceSetup(['read event']);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sections', function ($menu): bool {
            $slugs = menuSlugs($menu->all());

            // Each of these needs more than "read event".
            foreach (['check-in', 'abstracts', 'automations', 'surveys', 'planning', 'finance', 'certificates', 'attendee-groups'] as $gated) {
                if (in_array($gated, $slugs, true)) {
                    return false;
                }
            }

            return in_array('guests', $slugs, true) && in_array('schedule', $slugs, true);
        }));
});

test('an empty group disappears rather than showing a heading with nothing under it', function () {
    [, $user, $event, $host] = workspaceSetup(['read event']);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('sections', fn ($menu): bool => collect($menu)->every(fn (array $group): bool => $group['sections'] !== [])));
});

test('the requested section is opened when the user can open it', function () {
    [, $user, $event, $host] = workspaceSetup('Org Superadmin');

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}?section=guests", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('section', 'guests'));
});

test('an unknown or forbidden section falls back to the overview', function () {
    [, $user, $event, $host] = workspaceSetup(['read event']);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}?section=not-a-section", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('section', 'overview'));

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}?section=check-in", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('section', 'overview'));
});

test('materials is hidden on a plan without it, unless the event already has some', function () {
    [$tenant, $user, $event, $host] = workspaceSetup('Org Superadmin');
    $tenant->features()->create(['feature_key' => 'event_materials', 'enabled' => false]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('sections', fn ($menu): bool => ! in_array('materials', menuSlugs($menu->all()), true)));

    $event->materials()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Opening keynote slides',
        'file_path' => 'materials/keynote.pdf',
        'file_size' => 1024,
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('sections', fn ($menu): bool => in_array('materials', menuSlugs($menu->all()), true)));
});

test('finance is hidden from a tenant that has never handled ticket money', function () {
    [$tenant, $user, $event, $host] = workspaceSetup('Org Superadmin');
    $tenant->features()->create(['feature_key' => 'paid_tickets', 'enabled' => false]);
    $event->update(['ticket_price' => 0]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('sections', fn ($menu): bool => ! in_array('finance', menuSlugs($menu->all()), true)));
});
