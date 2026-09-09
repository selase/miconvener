<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Livewire\Tenant\SubscriptionManager;
use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantFeatureUsage;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/**
 * A plan change is agreed to in a dialog and felt weeks later. These warnings
 * are measured against the tenant's real data so the consequences that actually
 * bite are visible at the moment of the decision.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->seed(EventPackageSeeder::class);
});

function downgradingTenant(string $slug): array
{
    $tenant = Tenant::factory()->create([
        'slug' => $slug,
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', 'growth')->firstOrFail()->id,
    ]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    app(TenantContext::class)->setTenant($tenant);

    return [$tenant, $user];
}

test('cancelling to Free warns when registrations already exceed the free ceiling', function () {
    [$tenant, $user] = downgradingTenant('warn-regs');

    TenantFeatureUsage::create([
        'tenant_id' => $tenant->id,
        'feature_slug' => 'event_registrations',
        'period_start' => null,
        'period_end' => null,
        'used_count' => 180,
    ]);

    $warnings = Livewire::actingAs($user)
        ->test(SubscriptionManager::class)
        ->call('openCancelConfirmation')
        ->get('pendingChangeWarnings');

    expect($warnings)->toBeArray();
    expect(implode(' ', $warnings))->toContain('180 registrations this month');
    expect(implode(' ', $warnings))->toContain('turned away');
});

test('cancelling to Free warns about published events selling paid tickets', function () {
    [$tenant, $user] = downgradingTenant('warn-tickets');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'ticket_price' => 0]);
    EventTicketType::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Early Bird',
        'price' => 5000,
        'capacity' => 100,
        'is_active' => true,
    ]);

    $warnings = Livewire::actingAs($user)
        ->test(SubscriptionManager::class)
        ->call('openCancelConfirmation')
        ->get('pendingChangeWarnings');

    expect(implode(' ', $warnings))->toContain('sell paid tickets');
    // The honest part: we do not break an event mid-sale.
    expect(implode(' ', $warnings))->toContain('keep selling');
});

test('a tenant with nothing at stake sees no warnings', function () {
    [$tenant, $user] = downgradingTenant('warn-none');

    $warnings = Livewire::actingAs($user)
        ->test(SubscriptionManager::class)
        ->call('openCancelConfirmation')
        ->get('pendingChangeWarnings');

    expect($warnings)->toBe([]);
});
