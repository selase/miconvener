<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enum\UsageMetric;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\UsageLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceUsageLimits
{
    public function __construct(
        private TenantContext $context,
        private UsageLimitService $limitService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->getTenant();

        if (!$tenant instanceof \App\Models\Tenant) {
            return $next($request);
        }
        if (! $this->limitService->isWithinLimits($tenant, UsageMetric::REQUEST_COUNT)) {
            return response()->json([
                'error' => 'Usage limit exceeded',
                'message' => 'You have reached your request limit for the current period.',
            ], 429);
        }

        return $next($request);
    }
}
