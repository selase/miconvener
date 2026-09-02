<?php

declare(strict_types=1);

namespace App\Policies;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

abstract class BasePolicy
{
    /**
     * Determine if the given model belongs to the active tenant.
     */
    protected function belongsToActiveTenant(Model $model): bool
    {
        $tenantId = app(TenantContext::class)->activeTenantId();

        if (! $tenantId) {
            return false;
        }

        // Check if the model has a tenant_id and it matches
        return isset($model->tenant_id) && $model->tenant_id === $tenantId;
    }
}
