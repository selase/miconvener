<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class MeterRequestUsage
{
    public function __construct(
        private TenantContext $context
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $tenant = $this->context->getTenant();

        if ($tenant instanceof \App\Models\Tenant) {
            $duration = (int) ((microtime(true) - $start) * 1000);
            $statusCode = $response->getStatusCode();
            $statusBucket = (floor($statusCode / 100) * 100).'xx';
            $routeName = ($request->route() && $request->route()->getName())
                ? $request->route()->getName()
                : ($request->method().' '.$request->path());

            // We'll dispatch a job or use a service to record this
            // For now, let's call a service directly (we can queue it later for performance)
            app(\App\Services\Tenancy\UsageService::class)->recordRequest(
                $tenant,
                $routeName,
                $statusCode,
                $statusBucket,
                $duration
            );
        }

        return $response;
    }
}
