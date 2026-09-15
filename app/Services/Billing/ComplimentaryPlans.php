<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;

/**
 * Tenants on a paid plan they are not billed for. Renewals skip them.
 */
final class ComplimentaryPlans
{
    /**
     * Mark every tenant on a paid package that never paid for a plan through
     * Paystack. Those were set up by hand before renewals existed; billing them
     * now would end plans nobody agreed to pay for.
     *
     * @return int the number of tenants marked
     */
    public function markHandProvisionedTenants(): int
    {
        $paidPackageIds = Package::query()->where('is_free', false)->pluck('id');

        $payingTenantIds = Transaction::query()
            ->where('provider', 'paystack')
            ->whereNotNull('meta->package_id')
            ->distinct()
            ->pluck('tenant_id');

        return Tenant::query()
            ->whereIn('package_id', $paidPackageIds)
            ->whereNotIn('id', $payingTenantIds)
            ->where('billing_complimentary', false)
            ->update(['billing_complimentary' => true]);
    }
}
