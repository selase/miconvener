<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Support\TenantHandle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TenantResolver
{
    /**
     * Resolve the active tenant from the request.
     *
     * @throws HttpException
     */
    public function resolve(Request $request): ?Tenant
    {
        $tenant = $this->findTenant($request);
        if (! $tenant instanceof Tenant) {
            return null;
        }

        // Set the current team ID for Spatie Permissions BEFORE membership checks
        // This prevents role relationship caching issues with empty roles.
        setPermissionsTeamId($tenant->id);

        $this->validateStatus($tenant);
        $this->validateMembership($tenant, $request);

        return $tenant;
    }

    /**
     * Find the tenant based on precedence rules.
     */
    private function findTenant(Request $request): ?Tenant
    {
        $host = $request->getHost();

        // 1. Custom Domain (Highest Priority)
        /** @var Tenant|null $tenant */
        $tenant = Tenant::where('custom_domain', $host)
            ->where('custom_domain_status', 'active')
            ->first();

        if ($tenant) {
            return $tenant;
        }

        // 2. Subdomain
        $subdomain = $request->route('subdomain');

        if (! $subdomain) {
            $baseDomain = mb_ltrim((string) config('session.domain'), '.');
            if ($baseDomain && str_ends_with($host, '.'.$baseDomain)) {
                $subdomain = str_replace('.'.$baseDomain, '', $host);
            }
        }

        if ($subdomain && ! in_array($subdomain, TenantHandle::RESERVED, true)) {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::where('slug', $subdomain)->first();

            if ($tenant) {
                return $tenant;
            }

            // The host names an organization that does not exist. Falling through
            // would serve whatever the catch-all routes match -- in practice the
            // marketing landing page, on a 200 -- which tells the visitor the
            // organization is real. This has to hold for a subdomain derived from
            // the host as well as one captured by a route parameter: no route in
            // the subdomain group matches "/", so a bare unknown subdomain reaches
            // the central routes with no {subdomain} parameter set at all.
            throw new HttpException(404, 'Tenant not found');
        }

        // 2. Session
        if ($tenantId = Session::get('active_tenant_id')) {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::find($tenantId);

            return $tenant;
        }

        // 2. Header
        if ($tenantId = $request->header('X-Tenant')) {
            /** @var Tenant|null $tenant */
            $tenant = \Illuminate\Support\Str::isUuid($tenantId)
                ? Tenant::where('id', $tenantId)->orWhere('slug', $tenantId)->first()
                : Tenant::where('slug', $tenantId)->first();

            return $tenant;
        }

        // 3. Route Parameter
        if ($tenantSlug = $request->route('tenant')) {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::where('slug', $tenantSlug)->first();

            return $tenant;
        }

        return null;
    }

    private function validateMembership(Tenant $tenant, Request $request): void
    {
        $user = $request->user();

        // If there is no authenticated user, we treat it as a guest request (e.g. login/register pages or public tenant pages).
        // Actual authentication requirements are handled by the 'auth' middleware on specific routes.
        if (! $user) {
            return;
        }

        // Superadmins bypass membership checks to allow global management
        if (\Illuminate\Support\Facades\Gate::allows('access-superadmin-dashboard')) {
            return;
        }

        // Robust membership check using relationship query
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            throw new \App\Exceptions\TenantMembershipException();
        }
    }

    /**
     * Validate that the tenant is active.
     */
    private function validateStatus(Tenant $tenant): void
    {
        if ($tenant->status === \App\Enum\TenantStatusEnum::BANNED) {
            throw new HttpException(403, 'Tenant is banned');
        }
    }
}
