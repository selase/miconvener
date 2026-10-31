<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Models\StoreListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides staff-only rooms from every query on listings by default. The rule
 * lives here, not in each public page, so a new endpoint, search or tool can
 * never reveal a private room by forgetting a filter; only code that asks for
 * staff rooms by name (the owning console, a verified staff member in /my) sees
 * them. See StoreListing::withStaffOnly() and StoreListing::visibleToStaffOf().
 */
/**
 * @implements Scope<StoreListing>
 */
final class StaffOnlyScope implements Scope
{
    /**
     * @param  Builder<covariant StoreListing>  $builder
     * @param  StoreListing  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('audience'), '!=', StoreListing::AUDIENCE_STAFF);
    }
}
