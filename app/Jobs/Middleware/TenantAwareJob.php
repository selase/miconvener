<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use Closure;
use Illuminate\Support\Facades\URL;
use Throwable;

final class TenantAwareJob
{
    public function handle(object $job, Closure $next): void
    {
        if (isset($job->tenantId)) {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::find($job->tenantId);

            if ($tenant instanceof Tenant) {
                app(TenantContext::class)->setTenant($tenant);
                setPermissionsTeamId($tenant->id);

                if ($tenant->requiresDedicatedDb()) {
                    app(TenantDatabaseManager::class)->configure($tenant);
                } else {
                    app(TenantDatabaseManager::class)->configureShared();
                }

                app(\App\Services\Tenancy\TenantStorageManager::class)->configure($tenant);

                URL::defaults(['subdomain' => $tenant->slug]);
            }
        }

        $start = microtime(true);
        $exception = null;

        try {
            $next($job);
        } catch (Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            if (isset($tenant) && $tenant instanceof Tenant) {
                $duration = (int) ((microtime(true) - $start) * 1000);
                app(\App\Services\Tenancy\UsageService::class)->recordJob(
                    $tenant,
                    $job::class,
                    !$exception instanceof \Throwable,
                    $duration
                );
            }
        }
    }
}
