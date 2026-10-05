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

test('the menu leaves out pages for features MiConvener does not run', function (): void {
    $this->actingAs($this->superadmin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.billing.analytics.usage'), false)
        ->assertDontSee(route('llm-usage.index'), false)
        ->assertDontSee(route('admin.billing.invoices.index').'"', false)
        ->assertDontSee(route('admin.billing.rate-cards.index'), false);
});

/*
 * The DataTables endpoints return HTML the browser renders as-is. Names,
 * emails and logged values are typed by users (and the browser name comes
 * from the User-Agent header), so they must arrive escaped.
 */
test('the admin tables escape what users typed', function (): void {
    $script = '<script>alert(1)</script>';
    $tenant = Tenant::factory()->create(['name' => "Evil {$script}"]);
    $member = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => "Mal {$script}"]);
    $tenant->users()->attach($member->id);
    App\Models\UserLoginHistory::query()->create([
        'user_id' => $member->id,
        'tenant_id' => $tenant->id,
        'ip_address' => '127.0.0.1',
        'session_id' => 'sess-1',
        'login_at' => now(),
        'browser' => $script,
        'platform' => $script,
    ]);
    activity()->causedBy($member)->withProperties(['title' => "</textarea>{$script}"])->log('Renamed the event');

    $responses = [
        $this->actingAs($this->superadmin)->post(route('tenants.all'), adminTableRequest('', 1)),
        $this->actingAs($this->superadmin)->post(route('users.all'), adminTableRequest()),
        $this->actingAs($this->superadmin)->post(route('tenants.team.all', $tenant->uuid), adminTableRequest()),
        $this->actingAs($this->superadmin)->post(route('audit-trail.login-history.all'), adminTableRequest('', 0)),
        $this->actingAs($this->superadmin)->post(route('audit-trail.activity-logs.all'), adminTableRequest('', 0)),
    ];

    foreach ($responses as $table => $response) {
        $response->assertOk();
        $data = (string) json_encode($response->json('data'), JSON_UNESCAPED_SLASHES);

        expect(str_contains($data, '<script>'))->toBeFalse("Table {$table} returned unescaped HTML: ".mb_substr($data, max(0, (int) mb_strpos($data, '<script>') - 120), 200));
    }
});

test('an organisation in the list links to its page', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Linked Org']);

    $row = collect($this->actingAs($this->superadmin)->post(route('tenants.all'), adminTableRequest('Linked', 1))->json('data'))->first();

    expect($row['name'])->toContain('href="'.route('tenants.show', $tenant->uuid).'"')
        ->and($row['name'])->not->toContain('javascript:void(0)');
});

test('the console no longer loads a calendar plugin that does not exist', function (): void {
    $this->actingAs($this->superadmin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('fullcalendar', false)
        ->assertSee(now()->year.'©', false);
});

test('the profile page shows the real account and no sample content', function (): void {
    $this->actingAs($this->superadmin)
        ->get(route('profile.index', $this->superadmin->uuid))
        ->assertOk()
        ->assertSee($this->superadmin->email)
        ->assertDontSee('smith@kpmg.com')
        ->assertDontSee('Assigned Tickets')
        ->assertDontSee("User's Tasks", false);
});

test('Enterprise is listed as priced per organisation', function (): void {
    $this->seed(Database\Seeders\EventPackageSeeder::class);

    $rows = collect($this->actingAs($this->superadmin)->get(route('packages.index'), ['X-Requested-With' => 'XMLHttpRequest'])->json('data'));

    expect($rows->firstWhere('name', 'Enterprise')['price'])->toBe('Agreed per organisation');
});
