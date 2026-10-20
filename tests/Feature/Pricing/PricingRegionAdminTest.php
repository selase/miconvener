<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pricing\GhanaEligibility;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/**
 * Platform staff decide who gets the Ghana price when the evidence is not
 * enough on its own. It is the same permission as ticket terms, every change
 * needs a reason, and every change is recorded.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Foundation', 'isolation_mode' => 'shared']);
    $this->url = fn (): string => route('tenants.pricing-region.update', $this->tenant->uuid);
    $this->staff = function (string $role, array $extra = []): User {
        $user = User::factory()->create(['tenant_id' => null]);
        setPermissionsTeamId(null);
        $model = Role::query()->where('name', $role)->whereNull('tenant_id')->firstOrFail();
        foreach ($extra as $permission) {
            $model->givePermissionTo(Permission::findByName($permission));
        }
        $user->assignRole($model);

        return $user;
    };
});

test('a superadmin grants Ghana pricing with a reason, and it is recorded', function (): void {
    $staff = ($this->staff)('Superadmin');

    $this->actingAs($staff)->post(($this->url)(), ['action' => 'grant', 'reason' => 'A registered Ghana charity'])->assertSessionHas('success');

    $tenant = $this->tenant->fresh();
    expect($tenant->isGhanaEligible())->toBeTrue()
        ->and($tenant->pricing_region_evidence)->toMatchArray(['source' => GhanaEligibility::SOURCE_SUPERADMIN, 'detail' => ['reason' => 'A registered Ghana charity']])
        ->and($tenant->pricing_region_evidence['by'])->toEqual($staff->id)
        ->and(Activity::query()->where('subject_id', $this->tenant->id)->where('description', 'like', '%Ghana price granted%')->exists())->toBeTrue();
});

test('a superadmin removes it with a reason, and the organisation pays the USD price from then on', function (): void {
    $staff = ($this->staff)('Superadmin');
    app(GhanaEligibility::class)->grant($this->tenant, GhanaEligibility::SOURCE_GHANA_PAYMENT, []);

    $this->actingAs($staff)->post(($this->url)(), ['action' => 'remove', 'reason' => 'Moved abroad'])->assertSessionHas('success');

    $tenant = $this->tenant->fresh();
    expect($tenant->isGhanaEligible())->toBeFalse()
        ->and($tenant->pricing_region)->toBe('INTL')
        ->and($tenant->pricing_region_evidence)->toMatchArray(['source' => GhanaEligibility::SOURCE_REVOKED, 'detail' => ['reason' => 'Moved abroad']])
        ->and(Activity::query()->where('subject_id', $this->tenant->id)->where('description', 'like', '%Ghana price removed%')->exists())->toBeTrue();
});

test('a reason is required, and the action must be one of the two', function (): void {
    $staff = ($this->staff)('Superadmin');

    $this->actingAs($staff)->post(($this->url)(), ['action' => 'grant', 'reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($staff)->post(($this->url)(), ['action' => 'sideways', 'reason' => 'x'])->assertSessionHasErrors('action');
    expect($this->tenant->fresh()->pricing_region)->toBeNull();
});

test('granting an organisation that already has it says so and changes nothing', function (): void {
    $staff = ($this->staff)('Superadmin');
    app(GhanaEligibility::class)->grant($this->tenant, GhanaEligibility::SOURCE_PAYOUT_ACCOUNT, ['account_id' => 'a']);

    $this->actingAs($staff)->post(($this->url)(), ['action' => 'grant', 'reason' => 'Again'])->assertSessionHas('info');

    expect($this->tenant->fresh()->pricing_region_evidence['source'])->toBe('payout_account');
});

test('a superadmin can restore an organisation another superadmin removed', function (): void {
    $staff = ($this->staff)('Superadmin');
    app(GhanaEligibility::class)->revoke($this->tenant, $staff, 'Mistake');

    $this->actingAs($staff)->post(($this->url)(), ['action' => 'grant', 'reason' => 'It was a mistake'])->assertSessionHas('success');

    expect($this->tenant->fresh()->isGhanaEligible())->toBeTrue();
});

test('platform finance needs the manage commercial terms permission, and an organisation can never', function (): void {
    $payload = ['action' => 'grant', 'reason' => 'Known'];

    $this->actingAs(($this->staff)('Platform Finance'))->post(($this->url)(), $payload)->assertForbidden();
    $this->actingAs(($this->staff)('Platform Finance', ['manage commercial terms']))->post(($this->url)(), $payload)->assertSessionHas('success');

    $owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $owner->assignRole('Org Superadmin');
    $other = Tenant::factory()->create();

    $this->actingAs($owner)->post(route('tenants.pricing-region.update', $other->uuid), $payload)->assertForbidden();
    $this->actingAs($owner)->post(($this->url)(), $payload)->assertForbidden();
});

test('the organisation page shows the pricing region with its evidence to staff who may change it', function (): void {
    $staff = ($this->staff)('Superadmin');
    app(GhanaEligibility::class)->grant($this->tenant, GhanaEligibility::SOURCE_SUPERADMIN, ['reason' => 'A registered Ghana charity'], $staff);

    $this->actingAs($staff)->get(route('tenants.show', $this->tenant->uuid))->assertOk()
        ->assertSee('Pricing region', false)
        ->assertSee('Ghana price', false)
        ->assertSee('A registered Ghana charity', false)
        ->assertSee(($this->url)(), false);
});
