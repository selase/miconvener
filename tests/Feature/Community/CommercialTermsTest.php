<?php

declare(strict_types=1);

use App\Enum\CommercialStreamEnum;
use App\Models\CommercialTermsChange;
use App\Models\CommercialTermsOverride;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Finance\CommercialTerms;
use App\Services\Finance\ResolvedTerms;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function termsSuperadmin(): User
{
    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole('Superadmin');

    return $user;
}

test('dues default to one percent on a paid plan and two percent on Free', function () {
    $resolver = app(CommercialTerms::class);
    $paid = Tenant::factory()->create(['package_id' => Package::factory()->create()->id]);
    $free = Tenant::factory()->create(['package_id' => Package::factory()->create(['is_free' => true, 'price' => 0])->id]);
    expect($resolver->for($paid, CommercialStreamEnum::Dues)->rate)->toBe(1.0)
        ->and($resolver->for($free, CommercialStreamEnum::Dues)->rate)->toBe(2.0)
        ->and($resolver->for($free, CommercialStreamEnum::Dues)->source)->toBe('plan');
});

test('an override wins and zero is an explicit waiver', function () {
    $tenant = Tenant::factory()->create();
    $resolver = app(CommercialTerms::class);
    $resolver->setOverride($tenant, CommercialStreamEnum::Dues, 0.0, null, 'Charity waiver', termsSuperadmin());
    $terms = $resolver->for($tenant, CommercialStreamEnum::Dues);
    expect($terms->rate)->toBe(0.0)->and($terms->isWaiver)->toBeTrue()->and($terms->source)->toBe('override')
        ->and($resolver->commission($terms, 10000))->toBe(0);
});

test('removing an override restores plan terms and audits every change', function () {
    $tenant = Tenant::factory()->create(['package_id' => Package::factory()->create()->id]);
    $by = termsSuperadmin();
    $resolver = app(CommercialTerms::class);
    $resolver->setOverride($tenant, CommercialStreamEnum::Dues, 3.0, 50, 'Agreement', $by);
    $resolver->setOverride($tenant, CommercialStreamEnum::Dues, null, null, 'Agreement ended', $by);
    expect($resolver->for($tenant, CommercialStreamEnum::Dues)->rate)->toBe(1.0)
        ->and(CommercialTermsOverride::query()->count())->toBe(0);
    $audit = CommercialTermsChange::query()->orderBy('id')->get();
    expect($audit)->toHaveCount(2)->and((float) $audit[0]->new_rate)->toBe(3.0)
        ->and($audit[1]->new_rate)->toBeNull()->and((float) $audit[1]->old_rate)->toBe(3.0)
        ->and($audit[1]->old_cap)->toBe(50)->and($audit[1]->changed_by)->toBe((int) $by->id);
});

test('commission rounds half up respects a cap and never goes negative', function () {
    $resolver = app(CommercialTerms::class);
    expect($resolver->commission(new ResolvedTerms(1.0, null, 'organizer', 'plan'), 150))->toBe(2)
        ->and($resolver->commission(new ResolvedTerms(1.0, 50, 'organizer', 'override'), 10000000))->toBe(50)
        ->and($resolver->commission(new ResolvedTerms(1.0, null, 'organizer', 'plan'), -100))->toBe(0);
});

test('only the application superadmin can change terms', function () {
    [$tenant, $owner] = eventHost('commercial-owner');
    $payload = ['stream' => 'dues', 'rate' => '0', 'cap_amount' => null, 'reason' => 'Charity waiver'];
    $this->actingAs($owner)->post(route('tenants.commercial-terms.update', $tenant->uuid), $payload)->assertForbidden();
    $this->actingAs(termsSuperadmin())->post(route('tenants.commercial-terms.update', $tenant->uuid), $payload)->assertRedirect();
    expect(CommercialTermsOverride::query()->where('tenant_id', $tenant->id)->firstOrFail()->rate)->toBe('0.00');
});

test('commercial terms require a reason and valid rate and cap', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAs(termsSuperadmin())->post(route('tenants.commercial-terms.update', $tenant->uuid), [
        'stream' => 'dues', 'rate' => '101', 'cap_amount' => -1, 'reason' => '',
    ])->assertSessionHasErrors(['rate', 'cap_amount', 'reason']);
    expect(CommercialTermsOverride::query()->count())->toBe(0);
});

test('paid plan dues stay at one percent and no package receives the Free two percent default', function () {
    config(['services.dues.platform_fee_percentage' => 7]);
    $resolver = app(CommercialTerms::class);
    $paid = Tenant::factory()->create(['package_id' => Package::factory()->create()->id]);
    $unassigned = Tenant::factory()->create(['package_id' => null]);
    expect($resolver->for($paid, CommercialStreamEnum::Dues)->rate)->toBe(1.0)
        ->and($resolver->for($unassigned, CommercialStreamEnum::Dues)->rate)->toBe(2.0)
        ->and($resolver->for($unassigned, CommercialStreamEnum::Dues)->source)->toBe('platform');
});

test('the resolver refuses service calls by an organisation owner', function () {
    [$tenant, $owner] = eventHost('terms-service-owner');
    expect(fn () => app(CommercialTerms::class)->setOverride($tenant, CommercialStreamEnum::Dues, 0.0, null, 'Unapproved waiver', $owner))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect(CommercialTermsOverride::query()->count())->toBe(0)->and(CommercialTermsChange::query()->count())->toBe(0);
});

test('the superadmin tenant page displays all streams and only editable community terms', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAs(termsSuperadmin())->get(route('tenants.show', $tenant->uuid))
        ->assertOk()->assertSee('Commercial terms')->assertSee('Save dues terms')->assertSee('Save giving terms')
        ->assertSee('Save contributions terms')->assertSee('Managed through existing ticket terms.');
    $this->get(route('tenants.show', $tenant->uuid))
        ->assertSee('Future configuration only: these terms do not change existing contribution payments or enable giving.');
});

test('explicit tenant terms remain authoritative when another tenant is active', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $resolver = app(CommercialTerms::class);
    $admin = termsSuperadmin();
    $resolver->setOverride($tenant, CommercialStreamEnum::Dues, 0.0, null, 'Approved waiver', $admin);
    app(App\Services\Tenancy\TenantContext::class)->setTenant($other);
    expect($resolver->for($tenant, CommercialStreamEnum::Dues)->rate)->toBe(0.0);
    $resolver->setOverride($tenant, CommercialStreamEnum::Dues, 3.0, null, 'Updated agreement', $admin);
    expect($resolver->for($tenant, CommercialStreamEnum::Dues)->rate)->toBe(3.0)
        ->and(CommercialTermsChange::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('a signed out visitor cannot set commercial terms', function () {
    $tenant = Tenant::factory()->create();
    $this->post(route('tenants.commercial-terms.update', $tenant->uuid), [
        'stream' => 'dues', 'rate' => 0, 'cap_amount' => null, 'reason' => 'Unapproved',
    ])->assertRedirect();
    expect(CommercialTermsOverride::query()->count())->toBe(0)->and(CommercialTermsChange::query()->count())->toBe(0);
});
