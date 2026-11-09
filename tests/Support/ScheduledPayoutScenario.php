<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;

function scheduledPolicyActors(): array
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active', 'two_factor_confirmed_at' => now()]);
    $tenant->users()->attach($owner);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $platform = User::factory()->create(['tenant_id' => null, 'status' => 'active', 'two_factor_confirmed_at' => now()]);
    setPermissionsTeamId(null);
    $platform->assignRole('Superadmin');
    setPermissionsTeamId($tenant->id);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);

    return [$tenant, $owner, $platform, $account];
}

function scheduledPolicyInput(TenantPayoutAccount $account): array
{
    return ['category' => 'dues', 'currency' => 'GHS', 'payout_account_id' => $account->id, 'timezone' => 'Africa/Accra',
        'cadence' => 'weekly', 'weekday' => 1, 'local_time' => '09:00', 'minimum_amount' => 1000,
        'maximum_amount' => 10000, 'daily_limit' => 20000, 'rolling_limit' => 20000, 'reserve_amount' => 500];
}

function fundedScheduledPolicy(): array
{
    [$tenant, $owner, $platform, $account] = scheduledPolicyActors();
    $policy = app(App\Services\Finance\PayoutPolicies::class)->approve(app(App\Services\Finance\PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account)), $platform);
    test()->travel(7)->days();
    app(App\Services\Finance\LedgerService::class)->postTransaction($tenant, null, 'test_funding', 'Verified dues funding', 'schedule-funding', [
        ['code' => App\Models\LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => 'debit', 'amount' => 15000],
        ['code' => App\Models\LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => 'credit', 'amount' => 15000],
    ], currency: 'GHS', category: App\Enum\LedgerCategoryEnum::Dues);
    App\Models\PayoutReconciliationCheckpoint::query()->create(['account_fingerprint' => $policy->account_fingerprint,
        'scope_key' => $tenant->id.':dues', 'observed_at' => now(), 'status' => 'matched', 'reviewed_by' => $platform->id,
        'evidence' => ['transfer_count' => 0, 'unresolved_count' => 0, 'ledger_checked' => true, 'provider_checked' => true], 'created_at' => now()]);

    return [$policy, $tenant, $owner, $platform, $account];
}
