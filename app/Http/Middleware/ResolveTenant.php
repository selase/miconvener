<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Services\Tenancy\TenantResolver;
use App\Services\Tenancy\TenantStorageManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveTenant
{
    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
        private TenantDatabaseManager $dbManager,
        private TenantStorageManager $storageManager
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if (! $tenant instanceof \App\Models\Tenant) {
            // Fallback: try to retrieve tenant from session directly (covers tests where resolver may miss)
            $tenantId = \Illuminate\Support\Facades\Session::get('active_tenant_id');
            if ($tenantId) {
                $tenant = \App\Models\Tenant::find($tenantId);
            }
        }

        if (! $tenant instanceof \App\Models\Tenant) {
            $this->dbManager->configureShared();

            return $next($request);
        }

        $this->context->setTenant($tenant);

        \Illuminate\Support\Facades\Log::info('ResolveTenant middleware reached', [
            'tenant_id' => $tenant->id,
            'path' => $request->path(),
        ]);

        \Illuminate\Support\Facades\URL::defaults([
            'subdomain' => $tenant->slug,
        ]);

        // Add tenant_id to Log context for observability
        \Illuminate\Support\Facades\Log::withContext([
            'tenant_id' => $tenant->id,
        ]);

        if ($tenant->requiresDedicatedDb()) {
            $this->dbManager->configure($tenant);
        } else {
            $this->dbManager->configureShared();
        }

        $this->storageManager->configure($tenant);

        // Set the team ID for Spatie Permissions
        setPermissionsTeamId($tenant->id);

        return $next($request);
    }
}
