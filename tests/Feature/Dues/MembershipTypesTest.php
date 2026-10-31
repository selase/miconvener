<?php

declare(strict_types=1);

use App\Enum\DuesPeriodEnum;
use App\Models\Member;
use App\Models\MembershipType;
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
function membershipTypesTenant(string $slug = 'acme'): array
{
    [$tenant, $user] = eventHost($slug);
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);

    return [$tenant->fresh(), $user];
}

test('an admin creates a class and sees it listed', function () {
    [$tenant, $user] = membershipTypesTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'ghs', 'period' => 'monthly'])
        ->assertCreated();

    $class = MembershipType::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($class->name)->toBe('Full member')
        ->and($class->amount)->toBe(5000)
        ->and($class->currency)->toBe('GHS')
        ->and($class->period)->toBe(DuesPeriodEnum::Monthly)
        ->and($class->is_active)->toBeTrue();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.community.money.dues.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Community/MoneyDues')
            ->has('membershipTypes', 1)
            ->where('membershipTypes.0.name', 'Full member')
            ->where('membershipTypes.0.member_count', 0));
});

test('a class name already used in the organisation is rejected', function () {
    [$tenant, $user] = membershipTypesTenant();
    MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'GHS', 'period' => 'monthly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('the same class name is fine in another organisation', function () {
    [, $user] = membershipTypesTenant();
    $other = Tenant::factory()->create(['slug' => 'beta']);
    MembershipType::factory()->create(['tenant_id' => $other->id, 'name' => 'Full member']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'GHS', 'period' => 'yearly'])
        ->assertCreated();
});

test('invalid class input is rejected', function () {
    [, $user] = membershipTypesTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => '', 'amount' => 0, 'currency' => 'GH', 'period' => 'fortnightly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'amount', 'currency', 'period']);
});

test('an admin renames, reprices and deactivates a class and keeps its own name on save', function () {
    [$tenant, $user] = membershipTypesTenant();
    $class = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $class->id]), ['name' => 'Full member', 'amount' => 9000, 'currency' => 'GHS', 'period' => 'quarterly', 'is_active' => false])
        ->assertOk();

    $class->refresh();
    expect($class->amount)->toBe(9000)
        ->and($class->period)->toBe(DuesPeriodEnum::Quarterly)
        ->and($class->is_active)->toBeFalse();
});

test('a class with members can be deactivated and there is no delete route', function () {
    [$tenant, $user] = membershipTypesTenant();
    $class = MembershipType::factory()->create(['tenant_id' => $tenant->id]);
    Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $class->id]), ['name' => $class->name, 'amount' => $class->amount, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => false])
        ->assertOk();

    expect($class->fresh()->is_active)->toBeFalse();

    $deleteRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'tenant.community.money.dues.') && in_array('DELETE', $route->methods(), true));
    expect($deleteRoutes)->toBeEmpty();
});

test('another tenant cannot edit this tenant\'s class', function () {
    [, $acmeAdmin] = membershipTypesTenant('acme');
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    $betaClass = MembershipType::factory()->create(['tenant_id' => $beta->id, 'name' => 'Beta class', 'amount' => 1000]);

    $this->actingAs($acmeAdmin)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $betaClass->id]), ['name' => 'Hacked', 'amount' => 1000, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true])
        ->assertNotFound();

    expect($betaClass->fresh()->name)->toBe('Beta class');
});

test('the class list shows only this tenant\'s classes', function () {
    [$tenant, $user] = membershipTypesTenant();
    MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Mine']);
    $beta = Tenant::factory()->create(['slug' => 'beta']);
    MembershipType::factory()->create(['tenant_id' => $beta->id, 'name' => 'Theirs']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.community.money.dues.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page->has('membershipTypes', 1)->where('membershipTypes.0.name', 'Mine'));
});

test('a user without manage members is forbidden', function () {
    [$tenant] = membershipTypesTenant();
    $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($viewer->id);
    $class = MembershipType::factory()->create(['tenant_id' => $tenant->id]);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($viewer)->withServerVariables($host)
        ->get(route('tenant.community.money.dues.index', ['subdomain' => 'acme']))->assertForbidden();
    $this->actingAs($viewer)->withServerVariables($host)
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'X', 'amount' => 100, 'currency' => 'GHS', 'period' => 'monthly'])->assertForbidden();
    $this->actingAs($viewer)->withServerVariables($host)
        ->patchJson(route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $class->id]), ['name' => 'X', 'amount' => 100, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true])->assertForbidden();
});

test('a tenant without dues gets 404 on the class pages', function () {
    [, $user] = eventHost('acme');

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('tenant.community.money.dues.index', ['subdomain' => 'acme']))
        ->assertNotFound();
});

test('a class amount below the minimum charge is rejected on create and edit', function () {
    [$tenant, $user] = membershipTypesTenant();
    $class = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);
    $host = ['HTTP_HOST' => eventSubdomainHost('acme')];

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'Student', 'amount' => 99, 'currency' => 'GHS', 'period' => 'monthly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'The amount must be at least 100 minor units (1.00).']);

    $this->actingAs($user)->withServerVariables($host)
        ->patchJson(route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $class->id]), ['name' => 'Full member', 'amount' => 50, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);

    $this->actingAs($user)->withServerVariables($host)
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'Student', 'amount' => 100, 'currency' => 'GHS', 'period' => 'monthly'])
        ->assertCreated();
});

test('a class with charges keeps its currency and period but can still be renamed and repriced', function () {
    [$tenant, $user] = membershipTypesTenant();
    $class = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member', 'currency' => 'GHS', 'period' => 'monthly']);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $class->id]);
    App\Models\DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $class->id]);
    $update = fn (array $changes) => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $class->id]), [
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
    [$tenant, $user] = membershipTypesTenant();
    $class = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->patchJson(route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $class->id]), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'NGN', 'period' => 'yearly', 'is_active' => true])
        ->assertOk()
        ->assertJsonPath('has_charges', false);

    expect($class->fresh()->currency)->toBe('NGN');
});

test('the rename keeps rows created under the old schema', function () {
    [$tenant] = membershipTypesTenant();
    $migration = require database_path('migrations/landlord/2026_10_15_100000_rename_dues_classes_to_membership_types.php');
    $migration->down();

    $db = Illuminate\Support\Facades\DB::connection('landlord');
    $typeId = (string) Illuminate\Support\Str::uuid7();
    $memberId = (string) Illuminate\Support\Str::uuid7();
    $chargeId = (string) Illuminate\Support\Str::uuid7();
    $token = Illuminate\Support\Str::random(40);
    $db->table('dues_classes')->insert(['id' => $typeId, 'tenant_id' => $tenant->id, 'name' => 'Full member', 'amount' => 5000, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $db->table('members')->insert(['id' => $memberId, 'tenant_id' => $tenant->id, 'dues_class_id' => $typeId, 'name' => 'Ama Mensah', 'phone' => '0244000001', 'status' => 'active', 'pay_token' => $token, 'created_at' => now(), 'updated_at' => now()]);
    $db->table('dues_charges')->insert(['id' => $chargeId, 'tenant_id' => $tenant->id, 'member_id' => $memberId, 'dues_class_id' => $typeId, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_on' => '2026-10-01', 'amount' => 5000, 'currency' => 'GHS', 'status' => 'paid', 'payment_method' => 'online', 'payment_reference' => 'ref_old_1', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    expect(Illuminate\Support\Facades\Schema::connection('landlord')->hasTable('dues_classes'))->toBeFalse();
    $type = MembershipType::query()->findOrFail($typeId);
    $member = Member::query()->findOrFail($memberId);
    $charge = App\Models\DuesCharge::query()->findOrFail($chargeId);
    expect($type->name)->toBe('Full member')
        ->and($member->membership_type_id)->toBe($typeId)
        ->and($member->pay_token)->toBe($token)
        ->and($charge->membership_type_id)->toBe($typeId)
        ->and($charge->status->value)->toBe('paid')
        ->and($charge->payment_reference)->toBe('ref_old_1');

    $this->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->get(route('public.dues.receipt', ['subdomain' => 'acme', 'token' => $token]))
        ->assertOk();

    expect(fn () => MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Full member']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('a membership type can have no dues', function () {
    [$tenant, $user] = membershipTypesTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'Honorary', 'currency' => 'GHS'])
        ->assertCreated();

    $type = MembershipType::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($type->amount)->toBeNull()->and($type->period)->toBeNull()->and($type->hasDues())->toBeFalse();
});

test('amount and period come together', function () {
    [, $user] = membershipTypesTenant();
    $post = fn (array $body) => $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'Full member', 'currency' => 'GHS', ...$body]);

    $post(['amount' => 5000])->assertUnprocessable()->assertJsonValidationErrors(['period']);
    $post(['period' => 'monthly'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
});

test('a member of a type without dues cannot be charged', function () {
    [$tenant, $user] = membershipTypesTenant();
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'amount' => null, 'period' => null]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $type->id]);

    expect(fn () => app(App\Services\Dues\DuesCharges::class)->createFor($member, Carbon\CarbonImmutable::parse('2026-10-01')))
        ->toThrow(DomainException::class, 'This membership type has no dues.');

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.charges.store', ['subdomain' => 'acme']), ['member_id' => $member->id, 'period_start' => '2026-10-01'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This membership type has no dues.');
});

test('an admin can choose daily and weekly dues', function (string $period) {
    [$tenant, $user] = membershipTypesTenant();

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->postJson(route('tenant.community.money.dues.store', ['subdomain' => 'acme']), ['name' => 'Full member', 'amount' => 5000, 'currency' => 'GHS', 'period' => $period])
        ->assertCreated();

    expect(MembershipType::query()->where('tenant_id', $tenant->id)->firstOrFail()->period->value)->toBe($period);
})->with(['daily', 'weekly']);

test('introducing dues requires an explicit nonhistorical start date', function () {
    [$tenant, $user] = membershipTypesTenant();
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-18 12:00', 'Africa/Accra'));
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'amount' => null, 'period' => null]);
    $url = route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $type->id]);
    $data = ['name' => $type->name, 'amount' => 2000, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true];
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')]);
    foreach ([null, '2026-10-17', '2026-02-30'] as $date) {
        $this->patchJson($url, [...$data, 'dues_start_on' => $date])->assertUnprocessable()->assertJsonValidationErrors('dues_start_on');
    }
    $this->patchJson($url, [...$data, 'dues_start_on' => '2026-11-01'])->assertOk()->assertJsonPath('dues_start_on', '2026-11-01');
    $this->patchJson($url, [...$data, 'dues_start_on' => '2026-10-18'])->assertUnprocessable()->assertJsonValidationErrors('dues_start_on');
    expect($type->fresh()->dues_start_on->toDateString())->toBe('2026-11-01');
});

test('reintroducing dues preserves an explicitly selected unchanged future date', function () {
    [$tenant, $user] = membershipTypesTenant();
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-18 12:00', 'Africa/Accra'));
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'amount' => null, 'period' => null]);
    $url = route('tenant.community.money.dues.update', ['subdomain' => 'acme', 'type' => $type->id]);
    $data = ['name' => $type->name, 'amount' => 2000, 'currency' => 'GHS', 'period' => 'monthly', 'is_active' => true, 'dues_start_on' => '2026-12-01'];
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')]);
    $this->patchJson($url, $data)->assertOk();
    $this->patchJson($url, [...$data, 'amount' => null, 'period' => null])->assertOk();
    $this->patchJson($url, $data)->assertOk()->assertJsonPath('dues_start_on', '2026-12-01');
});
