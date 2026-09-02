<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    /**
     * Boot the trait.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model): void {
            if (! $model->tenant_id && $tenant = app(TenantContext::class)->getTenant()) {
                $model->tenant_id = $tenant->id;
            }
        });
    }

    /**
     * Initialize the trait.
     */
    public function initializeBelongsToTenant(): void
    {
        if ($this->connection === null) {
            $this->setConnection(config('database.default_tenant_connection', 'tenant'));
        }
    }

    /**
     * Relationship to the Tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
