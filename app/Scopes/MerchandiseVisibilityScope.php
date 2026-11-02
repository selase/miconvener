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
                    ->catalogAvailable()->whereHas('merchandise', fn (Builder $details) => $details->where('visibility', 'public'));
            });
        });
    }
}
