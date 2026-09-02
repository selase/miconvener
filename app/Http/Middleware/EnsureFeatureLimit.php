<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFeatureLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $featureSlug, int $cost = 1): Response
    {
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();

        if (! $tenant) {
            abort(404, 'Tenant context not found.');
        }

        $service = app(\App\Services\Tenancy\FeatureMeteringService::class);

        if (! $service->canUse($tenant, $featureSlug, $cost)) {
            // 402 Payment Required or 403 Forbidden
            abort(403, "Usage limit exceeded for feature: {$featureSlug}. Please upgrade your plan.");
        }

        // Optional: record usage middleware?
        // Usually usage is recorded AFTER successful action in controller,
        // but simple "view" limits could be recorded here.
        // For now, we only enforce limit here.

        return $next($request);
    }
}
