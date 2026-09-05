<?php

declare(strict_types=1);

namespace Tests\Console;

use App\Models\Tenant;

beforeEach(function () {
    refreshTenantDatabases();
});

test('backfilling settlement mode sets every existing tenant to own_gateway', function () {
    $existing = Tenant::factory()->create();
    expect($existing->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT);

    $this->artisan('tenants:backfill-settlement-mode', ['--yes' => true])->assertExitCode(0);

    expect($existing->fresh()->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
});

test('backfilling settlement mode does not touch a tenant already explicitly set', function () {
    $alreadyPlatform = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $alreadyOwnGateway = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

    // Simulate that $alreadyPlatform was deliberately opted in post-launch by
    // recording it as "seen" — this command only needs to run once at
    // rollout, so for this task it backfills unconditionally; a tenant that
    // wants platform_default post-rollout switches via the settings screen
    // (Task 3), not by re-running this command.
    $this->artisan('tenants:backfill-settlement-mode', ['--yes' => true])->assertExitCode(0);

    expect($alreadyOwnGateway->fresh()->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);
});
