<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantFeatureUsage;
use App\Models\User;
use App\Services\Billing\PlanChangeWarnings;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * A plan change is agreed to in a moment and felt weeks later. These warnings
 * are measured against the tenant's real data so the consequences that actually
 * bite are visible at the moment of the decision, on the page where the choice
 * is made.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->seed(EventPackageSeeder::class);
});

/**
 * @return array{0: Tenant, 1: User, 2: string}
 */
function downgradingTenant(string $slug): array
{
    $tenant = Tenant::factory()->create([
        'slug' => $slug,
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', 'growth')->firstOrFail()->id,
    ]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    app(TenantContext::class)->setTenant($tenant);

    return [$tenant, $user, "{$slug}.".mb_ltrim((string) config('session.domain'), '.')];
}

function warningsForFree(Tenant $tenant): array
{
    return app(PlanChangeWarnings::class)->for($tenant, Package::where('slug', 'free')->firstOrFail());
}

test('moving to Free warns when registrations already exceed the free ceiling', function () {
    [$tenant] = downgradingTenant('warn-regs');

    TenantFeatureUsage::create([
        'tenant_id' => $tenant->id,
        'feature_slug' => 'event_registrations',
        'period_start' => null,
        'period_end' => null,
        'used_count' => 180,
    ]);

    $warnings = warningsForFree($tenant);

    expect(implode(' ', $warnings))->toContain('180 registrations this month');
    expect(implode(' ', $warnings))->toContain('turned away');
});

test('moving to Free warns about published events selling paid tickets', function () {
    [$tenant] = downgradingTenant('warn-tickets');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'ticket_price' => 0]);
    EventTicketType::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Early Bird',
        'price' => 5000,
        'capacity' => 100,
        'is_active' => true,
    ]);

    $warnings = warningsForFree($tenant);

    expect(implode(' ', $warnings))->toContain('sell paid tickets');
    // The honest part: we do not break an event mid-sale.
    expect(implode(' ', $warnings))->toContain('keep selling');
});

test('a tenant with nothing at stake sees no warnings', function () {
    [$tenant] = downgradingTenant('warn-none');

    expect(warningsForFree($tenant))->toBe([]);
});

test('the plans page carries each plan its warnings, so the choice is made with them in view', function () {
    [$tenant, $user, $host] = downgradingTenant('warn-page');

    TenantFeatureUsage::create([
        'tenant_id' => $tenant->id,
        'feature_slug' => 'event_registrations',
        'period_start' => null,
        'period_end' => null,
        'used_count' => 180,
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/pricing", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('packages', function ($packages): bool {
                $free = collect($packages)->firstWhere('slug', 'free');
                $growth = collect($packages)->firstWhere('slug', 'growth');

                // Free costs them something and says so; their current plan
                // warns about nothing, since staying put changes nothing.
                return str_contains(implode(' ', $free['warnings']), '180 registrations this month')
                    && $growth['warnings'] === [];
            }));
});
