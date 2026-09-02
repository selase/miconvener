<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Feature;
use App\Models\TenantFeature;
use Illuminate\Support\Facades\Cache;

final readonly class EntitlementService
{
    public function __construct(private TenantContext $context) {}

    /**
     * Check if the current tenant is entitled to a specific permission.
     * This checks if the permission is tied to any features, and if so,
     * whether at least one of those features is enabled for the tenant.
     */
    public function isEntitled(string $permissionName): bool
    {
        $tenantId = $this->context->activeTenantId();

        if (! $tenantId) {
            return true;
        }

        $featureSlugs = $this->getFeaturesForPermission($permissionName);

        if ($featureSlugs === []) {
            return true;
        }
        return array_any($featureSlugs, fn(string $slug): bool => $this->isFeatureEnabledForTenant($tenantId, $slug));
    }

    /**
     * Flush the cached allowed permissions for a tenant.
     * Call this when a tenant's package or features change.
     */
    public function flushCacheForTenant(string $tenantId): void
    {
        Cache::forget("tenant_{$tenantId}_allowed_permissions");
    }

    /**
     * Get all permission names that a tenant is entitled to from the TENANT_SAFE list.
     */
    public function getAllowedPermissionsForTenant(string $tenantId): array
    {
        return Cache::remember("tenant_{$tenantId}_allowed_permissions", now()->addMinutes(10), function () use ($tenantId) {
            $tenantSafePermissions = \App\Models\Permission::whereIn('name', \App\Models\Permission::TENANT_SAFE)->get();

            return $tenantSafePermissions->filter(function ($permission) use ($tenantId): bool {
                // If permission has no features, it's allowed
                if ($permission->features->isEmpty()) {
                    return true;
                }

                // If any associated feature is enabled, it's allowed
                foreach ($permission->features as $feature) {
                    if ($this->isFeatureEnabledForTenant($tenantId, $feature->slug)) {
                        return true;
                    }
                }

                return false;
            })->pluck('name')->toArray();
        });
    }

    /**
     * Get all feature slugs that "unlock" a specific permission.
     */
    private function getFeaturesForPermission(string $permissionName): array
    {
        return Feature::whereHas('permissions', function ($query) use ($permissionName): void {
            $query->where('name', $permissionName);
        })->pluck('slug')->toArray();
    }

    private function isFeatureEnabledForTenant(string $tenantId, string $featureSlug): bool
    {
        return TenantFeature::where('tenant_id', $tenantId)
            ->where('feature_key', $featureSlug)
            ->where('enabled', true)
            ->exists();
    }
}
