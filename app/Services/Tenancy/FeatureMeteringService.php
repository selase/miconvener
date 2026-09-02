<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;

final class FeatureMeteringService
{
    /**
     * Check if the tenant can use the feature.
     */
    public function canUse(Tenant $tenant, string $featureSlug, int $quantity = 1): bool
    {
        return $tenant->canUse($featureSlug, $quantity);
    }

    /**
     * Record usage for the feature.
     */
    public function recordUsage(Tenant $tenant, string $featureSlug, int $quantity = 1): void
    {
        $tenant->recordUsage($featureSlug, $quantity);
    }

    /**
     * Get usage usage statistics for API responses.
     */
    public function getUsage(Tenant $tenant, string $featureSlug): array
    {
        $used = $tenant->usage()
            ->where('feature_slug', $featureSlug)
            ->whereNull('period_start')
            ->value('used_count') ?? 0;

        $feature = $tenant->features()->where('feature_key', $featureSlug)->first();
        $limit = $feature->meta['value'] ?? 0;
        $isEnabled = $feature->enabled ?? false;

        return [
            'slug' => $featureSlug,
            'enabled' => $isEnabled,
            'used' => $used,
            'limit' => (int) $limit,
            'remaining' => max(0, (int) $limit - $used),
        ];
    }

    /**
     * Reset usage for a tenant (e.g. at end of billing cycle).
     */
    public function resetUsage(Tenant $tenant, ?string $featureSlug = null): void
    {
        $query = $tenant->usage();

        if ($featureSlug) {
            $query->where('feature_slug', $featureSlug);
        }

        // For simple implementations, we assume deleting usage records resets the counter.
        // A more advanced system would archive them with 'period_end' set to now.
        $query->delete();
    }
}
