<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Tenant;
use App\Services\Community\MessageEntitlements;

test('message entitlements follow the standard plan matrix', function (string $slug, bool $schedule, bool $groups) {
    $package = Package::factory()->create(['slug' => $slug]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id]);
    $service = app(MessageEntitlements::class);
    expect($service->canSchedule($tenant))->toBe($schedule)->and($service->canMessageGroups($tenant))->toBe($groups);
})->with([['free', false, false], ['starter', true, false], ['growth', true, true], ['enterprise', true, true], ['unknown', false, false]]);

test('explicit entitlement withdrawals and grants win over plan defaults', function () {
    $package = Package::factory()->create(['slug' => 'growth']);
    $tenant = Tenant::factory()->create(['package_id' => $package->id]);
    $tenant->features()->create(['feature_key' => 'community_message_scheduling', 'enabled' => false]);
    expect(app(MessageEntitlements::class)->canSchedule($tenant))->toBeFalse();
    $tenant->features()->updateOrCreate(['feature_key' => 'community_group_messaging'], ['enabled' => false]);
    expect(app(MessageEntitlements::class)->canMessageGroups($tenant))->toBeFalse();
    $tenant->features()->updateOrCreate(['feature_key' => 'community_message_scheduling'], ['enabled' => true]);
    expect(app(MessageEntitlements::class)->canSchedule($tenant))->toBeTrue();
});
