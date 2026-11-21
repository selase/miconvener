<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config(['cache.stores.portal_sessions' => ['driver' => 'array']]);
    Cache::forgetDriver('portal_sessions');
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
    Package::factory()->create(['name' => 'Free', 'slug' => 'free', 'is_free' => true, 'price' => 0]);
    $this->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class);
});

function workspaceInput(array $overrides = []): array
{
    return $overrides + ['organization_name' => 'Second organisation', 'slug' => 'second-org', 'request_key' => (string) Str::uuid(), 'plan' => 'free'];
}

test('workspace index works without memberships and ignores stale tenant sessions', function (): void {
    $user = User::factory()->create();
    $stale = Tenant::factory()->create();
    $this->actingAs($user)->withSession(['active_tenant_id' => $stale->id])
        ->get(route('workspaces.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Workspaces/Index')->has('workspaces', 0)->where('tenant', null));
});

test('workspace index only returns active memberships as explicit summaries', function (): void {
    $user = User::factory()->create();
    $active = Tenant::factory()->create();
    $revoked = Tenant::factory()->create();
    $user->allTenants()->attach($active->id);
    $user->allTenants()->attach($revoked->id, ['status' => 'revoked']);
    $this->actingAs($user)->get(route('workspaces.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('workspaces', 1)->where('workspaces.0.id', $active->id)->missing('workspaces.0.meta'));
});

test('existing account creates a shared free organisation without changing global identity', function (): void {
    $old = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $old->id]);
    $old->users()->attach($user->id);
    $before = $user->fresh()->only(['tenant_id', 'email', 'password', 'status']);
    $this->actingAs($user)->post(route('workspaces.store'), workspaceInput())
        ->assertRedirect(route('tenant.onboarding.wizard', ['subdomain' => 'second-org']));
    $tenant = Tenant::where('slug', 'second-org')->firstOrFail();
    expect($user->fresh()->only(['tenant_id', 'email', 'password', 'status']))
        ->toBe($before)
        ->and($tenant->isolation_mode)->toBe('shared')
        ->and($tenant->package->isFree())->toBeTrue()
        ->and($user->fresh()->tenants()->count())->toBe(2);
    setPermissionsTeamId($tenant->id);
    expect($user->fresh()->hasRole('Org Superadmin'))->toBeTrue();
    setPermissionsTeamId($old->id);
    expect($user->fresh()->hasRole('Org Superadmin'))->toBeFalse();
});

test('creation retries return the same persisted organisation and reject changed payloads', function (): void {
    $user = User::factory()->create();
    $input = workspaceInput();
    $this->actingAs($user)->post(route('workspaces.store'), $input)->assertRedirect();
    $this->post(route('workspaces.store'), $input)->assertRedirect();
    expect(Tenant::where('slug', 'second-org')->count())->toBe(1)
        ->and($user->fresh()->tenants()->count())->toBe(1);
    $this->post(route('workspaces.store'), [...$input, 'organization_name' => 'Changed'])
        ->assertSessionHasErrors('request_key');
});

test('creation does not accept paid plans reserved addresses or another users handle', function (): void {
    $this->actingAs(User::factory()->create());
    $this->post(route('workspaces.store'), workspaceInput(['plan' => 'starter']))->assertSessionHasErrors('plan');
    $this->post(route('workspaces.store'), workspaceInput(['slug' => 'admin']))->assertSessionHasErrors('slug');
    Tenant::factory()->create(['slug' => 'taken-org']);
    $this->post(route('workspaces.store'), workspaceInput(['slug' => 'taken-org']))->assertSessionHasErrors('slug');
});

test('workspace entry points require a verified active account', function (): void {
    $this->get(route('workspaces.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('workspaces.create'))->assertRedirect(route('verification.notice'));
    $this->get(route('workspaces.index'))->assertRedirect(route('verification.notice'));
    $this->post(route('workspaces.store'), workspaceInput())->assertForbidden();
    $this->actingAs(User::factory()->create(['status' => 'inactive']))
        ->post(route('workspaces.store'), workspaceInput())->assertRedirect(route('login'));
});

test('new organisation region follows the visitor rather than a submitted region', function (): void {
    $this->actingAs(User::factory()->create())->withHeader('CF-IPCountry', 'US')
        ->post(route('workspaces.store'), workspaceInput(['pricing_region' => 'GH']))->assertRedirect();
    expect(Tenant::where('slug', 'second-org')->firstOrFail()->pricing_region)->toBe('INTL');
});

test('creation restores the permission team and same key is scoped to its creator', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $input = workspaceInput();
    $previousTeam = (string) Str::uuid();
    setPermissionsTeamId($previousTeam);
    app(CreateWorkspace::class)->create($a, $input, 'USD');
    expect(getPermissionsTeamId())->toBe($previousTeam);
    app(CreateWorkspace::class)->create($b, [...$input, 'slug' => 'another-org'], 'GHS');
    expect(Tenant::whereIn('slug', ['second-org', 'another-org'])->count())->toBe(2);
});

test('the creation form provides a stable request key and account details', function (): void {
    $this->actingAs(User::factory()->create())->get(route('workspaces.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Workspaces/Create')
            ->where('requestKey', fn (string $key): bool => Str::isUuid($key))->where('recovering', false));
});

test('a failed completion persists recovery state and rolls back incomplete ownership', function (): void {
    $user = User::factory()->create();
    $input = workspaceInput();
    $fail = true;
    Tenant::updating(function (Tenant $tenant) use (&$fail): void {
        if ($fail && ($tenant->meta['workspace_creation']['state'] ?? null) === 'ready') {
            throw new RuntimeException('Simulated interruption after provisioning');
        }
    });
    $this->actingAs($user)->post(route('workspaces.store'), $input)->assertSessionHasErrors('request_key');
    $tenant = Tenant::where('slug', 'second-org')->firstOrFail();
    expect($tenant->meta['workspace_creation']['state'])->toBe('failed')
        ->and($user->fresh()->tenants()->count())->toBe(0);
    $this->get(route('workspaces.index'))->assertInertia(fn (Assert $page) => $page->has('pendingWorkspaces', 1));
    $this->get(route('workspaces.create', ['request_key' => $input['request_key']]))
        ->assertInertia(fn (Assert $page) => $page->where('recovering', true)->where('requestKey', $input['request_key']));
    $fail = false;
    $this->post(route('workspaces.store'), $input)->assertRedirect();
    expect($tenant->fresh()->meta['workspace_creation']['state'])->toBe('ready')
        ->and($user->fresh()->tenants()->count())->toBe(1);
});

test('a concurrent retry cannot start another provisioning run', function (): void {
    $user = User::factory()->create();
    $input = workspaceInput();
    $lock = Cache::store('portal_sessions')->lock('workspace-create:'.$user->id.':'.$input['request_key'], 600);
    $lock->get();
    try {
        $this->actingAs($user)->post(route('workspaces.store'), $input)->assertSessionHasErrors('request_key');
        expect(Tenant::where('slug', 'second-org')->exists())->toBeFalse();
    } finally {
        $lock->release();
    }
});

test('a completed request cannot restore a revoked owner membership', function (): void {
    $user = User::factory()->create();
    $input = workspaceInput();
    $this->actingAs($user)->post(route('workspaces.store'), $input)->assertRedirect();
    $tenant = Tenant::where('slug', 'second-org')->firstOrFail();
    $user->allTenants()->updateExistingPivot($tenant->id, ['status' => 'revoked']);
    $this->post(route('workspaces.store'), $input)->assertForbidden();
    expect($user->fresh()->tenants()->count())->toBe(0);
});

test('workspace state transitions preserve metadata written after the caller loaded the tenant', function (): void {
    $tenant = Tenant::factory()->create(['meta' => ['workspace_creation' => ['state' => 'pending', 'request_key' => 'saved-key']]]);
    $fresh = $tenant->fresh();
    $fresh->forceFill(['meta' => [...$fresh->meta, 'platform_note' => 'Keep this concurrent update']])->save();

    (new ReflectionMethod(CreateWorkspace::class, 'state'))->invoke(app(CreateWorkspace::class), $tenant, 'provisioning');

    expect($tenant->fresh()->meta['platform_note'] ?? null)->toBe('Keep this concurrent update')
        ->and($tenant->fresh()->meta['workspace_creation']['request_key'])->toBe('saved-key')
        ->and($tenant->fresh()->meta['workspace_creation']['state'])->toBe('provisioning');
});

test('workspace finalization acquires the tenant lock before the existing user lock', function (): void {
    $locks = [];
    Illuminate\Support\Facades\DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'for update') && preg_match('/from "(users|tenants)"/', $query->sql, $match)) {
            $locks[] = $match[1];
        }
    });
    app(CreateWorkspace::class)->create(User::factory()->create(), workspaceInput(), 'USD');

    $userLocks = array_keys($locks, 'users', true);
    expect($userLocks)->toHaveCount(2)
        ->and($locks[$userLocks[1] - 1])->toBe('tenants');
});

test('disabled organisation rollout permits workspace listing but blocks creation through every entry point', function (): void {
    config(['organisation_access.enabled' => false]);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $user->allTenants()->attach($tenant->id);
    $this->actingAs($user)->get(route('workspaces.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('workspaces', 1)->where('canCreateOrganisation', false));
    $this->get(route('workspaces.create'))->assertServiceUnavailable();
    $this->post(route('workspaces.store'), workspaceInput())->assertServiceUnavailable();
    try {
        app(CreateWorkspace::class)->create($user, workspaceInput(), 'USD');
        test()->fail('Disabled rollout must block direct service calls.');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503);
    }
    expect(Tenant::where('slug', 'second-org')->exists())->toBeFalse()
        ->and($user->fresh()->tenants()->count())->toBe(1);
});

test('Inertia organisation creation performs a full cross-origin onboarding navigation', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withHeader('X-Inertia', 'true')->post(route('workspaces.store'), workspaceInput())->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('tenant.onboarding.wizard', ['subdomain' => 'second-org']));
});
