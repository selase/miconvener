<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enum\UsageMetric;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\UsagePrice;
use Illuminate\Support\Facades\Cache;

final class PricingService
{
    /**
     * Get the effective unit price for a metric for a specific tenant.
     */
    public function getUnitPrice(Tenant $tenant, UsageMetric $metric): ?UsagePrice
    {
        return Cache::remember("unit_price_{$tenant->id}_{$metric->value}", 3600, function () use ($tenant, $metric) {
            // 1. Check for tenant-specific override
            $price = UsagePrice::where('target_type', Tenant::class)
                ->where('target_id', $tenant->id)
                ->where('metric', $metric)
                ->first();

            if ($price) {
                return $price;
            }

            // 2. Check for package-level pricing
            if ($tenant->package_id) {
                $price = UsagePrice::where('target_type', Package::class)
                    ->where('target_id', $tenant->package_id)
                    ->where('metric', $metric)
                    ->first();

                if ($price) {
                    return $price;
                }
            }

            // 3. Fallback to Global standard pricing (target_type is null)
            return UsagePrice::whereNull('target_type')
                ->where('metric', $metric)
                ->first();
        });
    }

    /**
     * Get the effective markup percentage for a tenant.
     */
    public function getEffectiveMarkup(Tenant $tenant): float
    {
        // Markups are additive: Global + Package + Tenant override
        $globalMarkup = (float) config('billing.global_markup', 0);
        $packageMarkup = $tenant->package ? (float) $tenant->package->markup_percentage : 0;
        $tenantMarkup = (float) $tenant->markup_percentage;

        return $globalMarkup + $packageMarkup + $tenantMarkup;
    }

    /**
     * Calculate the cost for a given usage quantity.
     */
    public function calculateCost(Tenant $tenant, UsageMetric $metric, float $quantity): float
    {
        $price = $this->getUnitPrice($tenant, $metric);

        if (! $price || $price->unit_price <= 0) {
            return 0.0;
        }

        // Base Cost = (Quantity / Unit Quantity) * Unit Price
        $baseCost = ($quantity / (float) $price->unit_quantity) * (float) $price->unit_price;

        // Apply Markup
        $markupPercent = $this->getEffectiveMarkup($tenant);
        $totalCost = $baseCost * (1 + ($markupPercent / 100));

        return round($totalCost, 6);
    }
}
