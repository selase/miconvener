<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PrepaidCreditReceipt;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantFeatureUsage;
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
        if ($quantity > 0) {
            $this->withLock($tenant, fn () => $this->debit($tenant, $quantity));
        }
    }

    /**
     * Reserve available credits, recording their exact sources for a later refund.
     */
    final public function reserve(Tenant $tenant, int $quantity): ?PrepaidCreditReceipt
    {
        return $this->withLock($tenant, function () use ($tenant, $quantity): ?PrepaidCreditReceipt {
            if ($quantity <= 0 || ! $this->canSend($tenant, $quantity)) {
                return null;
            }

            return PrepaidCreditReceipt::query()->create([
                'tenant_id' => $tenant->id,
                'feature' => $this->feature(),
                'sources' => $this->debit($tenant, $quantity),
            ]);
        });
    }

    /** Confirmed refusals only. An unknown provider outcome must remain reserved. */
    final public function refund(Tenant $tenant, string $receiptId): void
    {
        $this->withLock($tenant, function () use ($tenant, $receiptId): void {
            $receipt = PrepaidCreditReceipt::query()->where('tenant_id', $tenant->id)
                ->where('feature', $this->feature())->lockForUpdate()->findOrFail($receiptId);
            if ($receipt->refunded_at !== null) {
                return;
            }
            $sources = $receipt->sources;
            $usage = TenantFeatureUsage::query()->where('tenant_id', $tenant->id)
                ->where('feature_slug', $this->feature())->lockForUpdate()->find($sources['usage_id']);
            if ($usage !== null) {
                $usage->update(['used_count' => max(0, $usage->used_count - $sources['usage_quantity'])]);
            }
            foreach ($sources['packs'] as $id => $quantity) {
                $pack = TenantAddon::query()->where('tenant_id', $tenant->id)->where('addon_type', $this->packType())->lockForUpdate()->find($id);
                if ($pack !== null) {
                    $pack->update(['meta' => array_merge($pack->meta ?? [], ['remaining' => $this->packBalance($pack) + $quantity])]);
                }
            }
            $receipt->update(['refunded_at' => now()]);
        });
    }

    /**
     * Shared by reservations and existing consume callers, independent of member-cap locks.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    final public function withLock(Tenant $tenant, callable $callback): mixed
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $callback): mixed {
            DB::connection('landlord')->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['credits:'.$this->feature().':'.$tenant->id]);

            return $callback();
        });
    }

    /** @return array{usage_id: int|null, usage_quantity: int, packs: array<string, int>} */
    private function debit(Tenant $tenant, int $quantity): array
    {
        $planRemaining = $this->planRemaining($tenant);
        $fromPlan = $planRemaining === null ? $quantity : min($quantity, $planRemaining);
        $owed = $quantity - $fromPlan;
        $packs = [];
        if ($owed > 0) {
            foreach ($this->activePacks($tenant)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get() as $pack) {
                $take = min($owed, $this->packBalance($pack));
                if ($take <= 0) {
                    continue;
                }
                $pack->update(['meta' => array_merge($pack->meta ?? [], ['remaining' => $this->packBalance($pack) - $take])]);
                $packs[(string) $pack->id] = $take;
                $owed -= $take;
                if ($owed === 0) {
                    break;
                }
            }
        }
        $quantity = $fromPlan + $owed;
        $usage = null;
        if ($quantity > 0) {
            $usage = TenantFeatureUsage::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'feature_slug' => $this->feature(), 'period_start' => null, 'period_end' => null],
                ['used_count' => 0],
            );
            $usage->increment('used_count', $quantity);
        }

        return ['usage_id' => $usage?->id, 'usage_quantity' => $quantity, 'packs' => $packs];
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
