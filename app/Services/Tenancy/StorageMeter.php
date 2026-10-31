<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Exception;
use Illuminate\Support\Facades\Storage;

final class StorageMeter
{
    /**
     * Calculate the total storage usage for a tenant in bytes.
     */
    public function calculateUsage(Tenant $tenant): int
    {
        // We use the 'tenant' disk which is already scoped to the tenant's root/prefix
        // by the TenantStorageManager
        app(TenantStorageManager::class)->configure($tenant);

        $disk = Storage::disk('tenant');
        $totalSize = 0;

        try {
            $files = $disk->allFiles();
            foreach ($files as $file) {
                $totalSize += $disk->size($file);
            }
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to calculate storage for tenant {$tenant->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        $merchandiseBytes = (int) \App\Models\StoreListingMedia::withoutGlobalScope('merchandise')
            ->whereNotNull('disk')->whereExists(fn ($query) => $query->selectRaw('1')->from('store_listings')->join('shops', 'shops.id', '=', 'store_listings.shop_id')
            ->whereColumn('store_listings.id', 'store_listing_media.listing_id')->where('shops.tenant_id', $tenant->id)
            ->where('store_listings.listing_kind', \App\Models\StoreListing::KIND_MERCHANDISE))->sum('byte_size');

        return $merchandiseBytes + $totalSize + (int) \App\Models\CommunityLibraryVersion::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sum('size');
    }
}
