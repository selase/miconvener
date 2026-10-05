<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The superadmin console's tables load through DataTables endpoints. Their
 * search was written for MySQL: comparing integer ids with string morph keys
 * and LIKE-matching timestamps both fail on PostgreSQL, so typing in a search
 * box returned a server error (found 2026-10-05).
 */
beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['tenant_id' => null, 'first_name' => 'Selase']);
    setPermissionsTeamId(null);
    $this->superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
});

/**
 * @return array<string, mixed>
 */
function adminTableRequest(string $search = '', int $sortColumn = 1): array
{
    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'order' => [['column' => $sortColumn, 'dir' => 'asc']],
        'search' => ['value' => $search, 'regex' => 'false'],
    ];
}

test('searching users finds them by name and by role', function (): void {
    $this->actingAs($this->superadmin)
        ->post(route('users.all'), adminTableRequest('selase'))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);

    $this->actingAs($this->superadmin)
        ->post(route('users.all'), adminTableRequest('superadmin'))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);
});

test('searching an organisation team works', function (): void {
    $tenant = Tenant::factory()->create();
    $member = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Efua']);
    $tenant->users()->attach($member->id);

    $this->actingAs($this->superadmin)
        ->post(route('tenants.team.all', $tenant->uuid), adminTableRequest('efua'))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);
});

test('activity logs can be searched, and sorted by who did it', function (): void {
    activity()->causedBy($this->superadmin)->log('Changed the plan prices');

    $this->actingAs($this->superadmin)
        ->post(route('audit-trail.activity-logs.all'), adminTableRequest('plan prices'))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);

    $this->actingAs($this->superadmin)
        ->post(route('audit-trail.activity-logs.all'), adminTableRequest('selase'))
        ->assertOk();

    $this->actingAs($this->superadmin)
        ->post(route('audit-trail.activity-logs.all'), adminTableRequest('', 4))
        ->assertOk();
});

test('an organisation that does not exist is not found rather than an error', function (): void {
    $this->actingAs($this->superadmin)
        ->get(route('tenants.show', '00000000-0000-0000-0000-000000000000'))
        ->assertNotFound();
});

test('the business verification queue is in the menu with its pending count', function (): void {
    Shop::factory()->create(['verification_status' => Shop::VERIFICATION_PENDING]);

    $this->actingAs($this->superadmin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.marketplace-verifications.index'), false)
        ->assertSee('Business Verifications');
});
