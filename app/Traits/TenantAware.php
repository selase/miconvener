<?php

declare(strict_types=1);

namespace App\Traits;

use App\Jobs\Middleware\TenantAwareJob;
use App\Services\Tenancy\TenantContext;
use ReflectionClass;
use ReflectionProperty;

trait TenantAware
{
    public ?string $tenantId = null;

    /**
     * Prepare the instance for cloning and serialization.
     */
    public function __sleep()
    {
        if (! $this->tenantId && app()->bound(TenantContext::class)) {
            $this->tenantId = app(TenantContext::class)->activeTenantId();
        }

        $properties = new ReflectionClass($this)->getProperties();

        return array_map(fn (ReflectionProperty $property): string => $property->getName(), $properties);
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }
}
