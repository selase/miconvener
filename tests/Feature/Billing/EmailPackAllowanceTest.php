<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Services\Billing\EmailAllowance;
use App\Services\Tenancy\FeatureMeteringService;
use Database\Seeders\EventPackageSeeder;

/**
 * Email packs are bought once. Email is paid from the plan's monthly
 * credits first, then from packs, and a pack's balance is never restored by
 * the monthly reset -- the same rule SMS packs follow.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    $this->seed(EventPackageSeeder::class);
});

function starterTenantWithEmailPack(int $packSize): Tenant
{
    $tenant = Tenant::factory()->create([
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', 'starter')->firstOrFail()->id, // 3,000 emails a month
    ]);
    $tenant->syncFeaturesFromPackage();
    TenantAddon::factory()->create([
        'tenant_id' => $tenant->id,
        'addon_type' => TenantAddon::TYPE_EMAIL_PACK,
        'quantity' => $packSize,
        'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
        'status' => TenantAddon::STATUS_ACTIVE,
    ]);

    return $tenant;
}

test('emails beyond the monthly plan come out of the pack, once', function (): void {
    $tenant = starterTenantWithEmailPack(5000);
    $allowance = app(EmailAllowance::class);

    expect($tenant->featureLimitValue('email_credits'))->toBe(8000);

    $tenant->recordUsage('email_credits', 3100);

    expect($allowance->packRemaining($tenant))->toBe(4900)
        ->and($allowance->remaining($tenant))->toBe(4900)
        ->and($tenant->canUse('email_credits', 4900))->toBeTrue()
        ->and($tenant->canUse('email_credits', 4901))->toBeFalse();
});

test('a new month refills the plan but not the pack', function (): void {
    $tenant = starterTenantWithEmailPack(5000);
    $tenant->recordUsage('email_credits', 3100);

    app(FeatureMeteringService::class)->resetUsage($tenant, 'email_credits');

    expect(app(EmailAllowance::class)->remaining($tenant))->toBe(7900)
        ->and($tenant->featureLimitValue('email_credits'))->toBe(7900);
});

test('other metered features still count as before', function (): void {
    $tenant = starterTenantWithEmailPack(5000);

    $tenant->recordUsage('event_registrations', 7);

    expect((int) $tenant->usage()->where('feature_slug', 'event_registrations')->value('used_count'))->toBe(7);
});
