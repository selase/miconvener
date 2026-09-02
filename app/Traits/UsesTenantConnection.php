<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Sets the model's database connection to the tenant connection.
 *
 * Use this on child models that live in the tenant database
 * but don't have a tenant_id column (they scope through a parent).
 */
trait UsesTenantConnection
{
    public function initializeUsesTenantConnection(): void
    {
        if ($this->connection === null) {
            $this->setConnection(config('database.default_tenant_connection', 'tenant'));
        }
    }
}
