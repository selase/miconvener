<?php

declare(strict_types=1);

use App\Enum\DuesPeriodEnum;
use App\Models\DuesClass;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * A tenant with dues switched on, plus its admin.
 *
 * @return array{0: Tenant, 1: User}
 */
function duesClassesTenant(string $slug = 'acme'): array
{
    [$tenant, $user] = eventHost($slug);
    Artisan::call('dues:enable', ['tenant' => $tenant->slug]);

    return [$tenant->fresh(), $user];
}

test('an admin creates a class and sees it listed', function () {
    [$tenant, $user] = duesClassesTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.classes.store', ['subdomain' => 'acme']), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'ghs', 'period' => 'monthly'])
        ->assertCreated();

    $class = DuesClass::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($class->name)->toBe('Full member')
        ->and($class->amount)->toBe(5000)
        ->and($class->currency)->toBe('GHS')
        ->and($class->period)->toBe(DuesPeriodEnum::Monthly)
        ->and($class->is_active)->toBeTrue();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.dues.classes.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Dues/Classes')
            ->has('classes', 1)
            ->where('classes.0.name', 'Full member')
            ->where('classes.0.member_count', 0));
});

test('a class name already used in the organisation is rejected', function () {
    [$tenant, $user] = duesClassesTenant();
    DuesClass::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.classes.store', ['subdomain' => 'acme']), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'GHS', 'period' => 'monthly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('the same class name is fine in another organisation', function () {
    [, $user] = duesClassesTenant();
    $other = Tenant::factory()->create(['slug' => 'beta']);
    DuesClass::factory()->create(['tenant_id' => $other->id, 'name' => 'Full member']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.classes.store', ['subdomain' => 'acme']), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'GHS', 'period' => 'yearly'])
        ->assertCreated();
});

test('invalid class input is rejected', function () {
    [, $user] = duesClassesTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.dues.classes.store', ['subdomain' => 'acme']), ['name' => '', 'amount' => 0, 'currency' => 'GH', 'period' => 'weekly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'amount', 'currency', 'period']);
});

test('an admin renames, reprices and deactivates a class and keeps its own name on save', function () {
    [$tenant, $user] = duesClassesTenant();
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.dues.classes.update', ['subdomain' => 'acme', 'class' => $class->id]), ['name' => 'Full member', 'amount' => 9000, 'currency' => 'GHS', 'period' => 'quarterly', 'is_active' => false])
        ->assertOk();

    $class->refresh();
    expect($class->amount)->toBe(9000)
        ->and($class->period)->toBe(DuesPeriodEnum::Quarterly)
        ->and($class->is_active)->toBeFalse();
});

test('a class with members can be deactivated and there is no delete route', function () {
    [$tenant, $user] = duesClassesTenant();
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id]);
    Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.dues.classes.update', ['subdomain' => 'acme', 'class' => $class->id]), ['name' => $class->name, 'amount' => $class->amount, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => false])
        ->assertOk();

    expect($class->fresh()->is_active)->toBeFalse();

    $deleteRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'tenant.dues.classes.') && in_array('DELETE', $route->methods(), true));
    expect($deleteRoutes)->toBeEmpty();
});

test('another tenant cannot edit this tenant\'s class', function () {
    [, $acmeAdmin] = duesClassesTenant('acme');
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    $betaClass = DuesClass::factory()->create(['tenant_id' => $beta->id, 'name' => 'Beta class', 'amount' => 1000]);

    $this->actingAs($acmeAdmin)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.dues.classes.update', ['subdomain' => 'acme', 'class' => $betaClass->id]), ['name' => 'Hacked', 'amount' => 1000, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true])
        ->assertNotFound();

    expect($betaClass->fresh()->name)->toBe('Beta class');
});

test('the class list shows only this tenant\'s classes', function () {
    [$tenant, $user] = duesClassesTenant();
    DuesClass::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Mine']);
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    DuesClass::factory()->create(['tenant_id' => $beta->id, 'name' => 'Theirs']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.dues.classes.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page->has('classes', 1)->where('classes.0.name', 'Mine'));
});

test('a user without manage members is forbidden', function () {
    [$tenant] = duesClassesTenant();
    $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($viewer->id);
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id]);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($viewer)->withServerVariables($host)
        ->get(route('tenant.dues.classes.index', ['subdomain' => 'acme']))->assertForbidden();
    $this->actingAs($viewer)->withServerVariables($host)
        ->postJson(route('tenant.dues.classes.store', ['subdomain' => 'acme']), ['name' => 'X', 'amount' => 100, 'currency' => 'GHS', 'period' => 'monthly'])->assertForbidden();
    $this->actingAs($viewer)->withServerVariables($host)
        ->patchJson(route('tenant.dues.classes.update', ['subdomain' => 'acme', 'class' => $class->id]), ['name' => 'X', 'amount' => 100, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true])->assertForbidden();
});

test('a tenant without dues gets 404 on the class pages', function () {
    [, $user] = eventHost('acme');

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.dues.classes.index', ['subdomain' => 'acme']))
        ->assertNotFound();
});

test('a class amount below the minimum charge is rejected on create and edit', function () {
    [$tenant, $user] = duesClassesTenant();
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.dues.classes.store', ['subdomain' => 'acme']), ['name' => 'Student', 'amount' => 99, 'currency' => 'GHS', 'period' => 'monthly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'The amount must be at least 100 minor units (1.00).']);

    $this->actingAs($user)->withServerVariables($host)
        ->patchJson(route('tenant.dues.classes.update', ['subdomain' => 'acme', 'class' => $class->id]), ['name' => 'Full member', 'amount' => 50, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.dues.classes.store', ['subdomain' => 'acme']), ['name' => 'Student', 'amount' => 100, 'currency' => 'GHS', 'period' => 'monthly'])
        ->assertCreated();
});

test('a class with charges keeps its currency and period but can still be renamed and repriced', function () {
    [$tenant, $user] = duesClassesTenant();
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member', 'currency' => 'GHS', 'period' => 'monthly']);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id]);
    App\Models\DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'dues_class_id' => $class->id]);
    $update = fn (array $changes) => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.dues.classes.update', ['subdomain' => 'acme', 'class' => $class->id]), [
            'name' => 'Full member', 'amount' => 5000, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true, ...$changes,
        ]);

    $update(['currency' => 'NGN', 'period' => 'yearly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'currency' => 'This class already has charges, so its currency cannot change. Create a new class instead.',
            'period' => 'This class already has charges, so its billing period cannot change. Create a new class instead.',
        ]);

    expect($class->fresh()->currency)->toBe('GHS')->and($class->fresh()->period)->toBe(DuesPeriodEnum::Monthly);

    $update(['name' => 'Full member (2027)', 'amount' => 6000])->assertOk()->assertJsonPath('has_charges', true);
    expect($class->fresh()->amount)->toBe(6000);
});

test('a class without charges can change its currency and period', function () {
    [$tenant, $user] = duesClassesTenant();
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.dues.classes.update', ['subdomain' => 'acme', 'class' => $class->id]), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'NGN', 'period' => 'yearly', 'is_active' => true])
        ->assertOk()
        ->assertJsonPath('has_charges', false);

    expect($class->fresh()->currency)->toBe('NGN');
});
