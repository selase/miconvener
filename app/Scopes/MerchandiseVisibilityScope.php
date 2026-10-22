<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Models\StoreListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<StoreListing> */
final class MerchandiseVisibilityScope implements Scope
{
    /** @param Builder<covariant StoreListing> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(function (Builder $query) use ($model): void {
            $query->where('store_listings.listing_kind', '!=', StoreListing::KIND_MERCHANDISE);
            if (! $model->getConnection()->getSchemaBuilder()->hasTable('store_merchandise')) {
                return;
            }
            $query->orWhere(function (Builder $merchandise): void {
                $merchandise->where('store_listings.listing_kind', StoreListing::KIND_MERCHANDISE)
                    ->where('store_listings.status', StoreListing::STATUS_PUBLISHED)
                    ->whereNull('store_listings.taken_down_at')
                    ->whereHas('merchandise', fn (Builder $details) => $details->where('visibility', 'public')->whereNull('archived_at')->whereRaw("collection_instructions ~ '[^[:space:]]'"))
                    ->whereHas('variants', fn (Builder $variants) => $variants->where('is_active', true))
                    ->whereHas('shop', fn (Builder $shop) => $shop->where('is_active', true)
                        ->whereHas('tenant', fn (Builder $tenant) => $tenant->where('status', 'active')
                            ->whereHas('features', fn (Builder $feature) => $feature->where('feature_key', 'commerce_catalog')->where('enabled', true))));
            });
        });
    }
}
