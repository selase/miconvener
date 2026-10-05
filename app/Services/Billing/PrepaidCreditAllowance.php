<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantAddon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A metered credit made of a monthly plan allowance plus one-off packs.
 *
 * The plan's allowance is counted in tenant usage and cleared by the monthly
 * usage reset. Packs are bought once: each keeps its own balance in
 * meta.remaining and is drawn down only after the plan's allowance is used,
 * so a new month never refills a pack. Usage only ever counts the plan's
 * share (plus anything beyond both), so limit checks against "plan + packs
 * left" stay honest.
 */
abstract class PrepaidCreditAllowance
{
    /** The tenant feature slug, e.g. sms_credits. */
    abstract protected function feature(): string;

    /** The TenantAddon type of the packs that top it up. */
    abstract protected function packType(): string;

    /**
     * Credits the tenant can still use; null when the plan is unlimited.
     */
    final public function remaining(Tenant $tenant): ?int
    {
        $planRemaining = $this->planRemaining($tenant);

        return $planRemaining === null ? null : $planRemaining + $this->packRemaining($tenant);
    }

    final public function canSend(Tenant $tenant, int $quantity = 1): bool
    {
        $remaining = $this->remaining($tenant);

        return $remaining === null || $remaining >= $quantity;
    }

    final public function packRemaining(Tenant $tenant): int
    {
        return (int) $this->activePacks($tenant)->get()
            ->sum(fn (TenantAddon $pack): int => $this->packBalance($pack));
    }

    /**
     * Spend from the plan first, then from the oldest pack. Anything beyond
     * both is still counted against the plan, so a send that already happened
     * is never lost from the books.
     */
    final public function consume(Tenant $tenant, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::connection('landlord')->transaction(function () use ($tenant, $quantity): void {
            $planRemaining = $this->planRemaining($tenant);
            $fromPlan = $planRemaining === null ? $quantity : min($quantity, $planRemaining);
            $owed = $quantity - $fromPlan;

            if ($owed > 0) {
                foreach ($this->activePacks($tenant)->orderBy('created_at')->lockForUpdate()->get() as $pack) {
                    $take = min($owed, $this->packBalance($pack));

                    if ($take <= 0) {
                        continue;
                    }

                    $pack->meta = array_merge($pack->meta ?? [], ['remaining' => $this->packBalance($pack) - $take]);
                    $pack->save();
                    $owed -= $take;

                    if ($owed === 0) {
                        break;
                    }
                }
            }

            $tenant->incrementUsage($this->feature(), $fromPlan + $owed);
        });
    }

    /**
     * What is left of this month's plan allowance; null when unlimited.
     */
    private function planRemaining(Tenant $tenant): ?int
    {
        $limit = $tenant->planLimitValue($this->feature());

        if ($limit === null) {
            return $tenant->hasUnlimited($this->feature()) ? null : 0;
        }

        $used = (int) ($tenant->usage()
            ->where('feature_slug', $this->feature())
            ->whereNull('period_start')
            ->value('used_count') ?? 0);

        return max(0, $limit - $used);
    }

    /**
     * @return Builder<TenantAddon>
     */
    private function activePacks(Tenant $tenant): Builder
    {
        return TenantAddon::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->ofType($this->packType());
    }

    private function packBalance(TenantAddon $pack): int
    {
        return max(0, (int) ($pack->meta['remaining'] ?? $pack->quantity));
    }
}
