<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\Tenant;
use App\Models\TenantAddon;
use Illuminate\Support\Facades\DB;

/**
 * How many SMS a tenant may still send, and the spending of them.
 *
 * The plan's sms_credits are a monthly allowance, counted in tenant usage and
 * cleared by the usage reset. SMS packs are bought once: each pack keeps its
 * own balance in meta.remaining and is drawn down only after the plan's
 * allowance is used, so a pack is never refilled by a new month.
 */
final class SmsAllowance
{
    public const string FEATURE = 'sms_credits';

    /**
     * Messages the tenant can still send; null when the plan is unlimited.
     */
    public function remaining(Tenant $tenant): ?int
    {
        $planRemaining = $this->planRemaining($tenant);

        return $planRemaining === null ? null : $planRemaining + $this->packRemaining($tenant);
    }

    public function canSend(Tenant $tenant, int $quantity = 1): bool
    {
        $remaining = $this->remaining($tenant);

        return $remaining === null || $remaining >= $quantity;
    }

    public function packRemaining(Tenant $tenant): int
    {
        return (int) $this->activePacks($tenant)->get()
            ->sum(fn (TenantAddon $pack): int => $this->packBalance($pack));
    }

    /**
     * Spend from the plan first, then from the oldest pack. Anything beyond
     * both is still counted against the plan, so a send already handed to the
     * provider is never lost from the books.
     */
    public function consume(Tenant $tenant, int $quantity): void
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

            $tenant->recordUsage(self::FEATURE, $fromPlan + $owed);
        });
    }

    /**
     * What is left of this month's plan allowance; null when unlimited.
     */
    private function planRemaining(Tenant $tenant): ?int
    {
        $limit = $tenant->featureLimitValue(self::FEATURE);

        if ($limit === null) {
            return $tenant->hasUnlimited(self::FEATURE) ? null : 0;
        }

        $used = (int) ($tenant->usage()
            ->where('feature_slug', self::FEATURE)
            ->whereNull('period_start')
            ->value('used_count') ?? 0);

        return max(0, $limit - $used);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<TenantAddon>
     */
    private function activePacks(Tenant $tenant): \Illuminate\Database\Eloquent\Builder
    {
        return TenantAddon::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->ofType(TenantAddon::TYPE_SMS_PACK);
    }

    private function packBalance(TenantAddon $pack): int
    {
        return max(0, (int) ($pack->meta['remaining'] ?? $pack->quantity));
    }
}
