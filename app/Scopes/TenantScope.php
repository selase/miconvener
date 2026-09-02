<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($tenantId = app(TenantContext::class)->activeTenantId()) {
            $builder->where($model->getTable().'.tenant_id', $tenantId);
        }
    }
}
