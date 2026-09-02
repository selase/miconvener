<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleLlmRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant) {
            return $next($request);
        }

        // Define rate limits based on tenant override or package
        // Default: 60 RPM
        $limit = $tenant->custom_llm_limit ?: 60;

        // E.g. Check package for limits if no custom limit is set
        if (! $tenant->custom_llm_limit && $tenant->package && isset($tenant->package->meta['llm_rate_limit'])) {
            $limit = $tenant->package->meta['llm_rate_limit'];
        }

        $key = "llm_rate_limit:{$tenant->id}";

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too Many Requests',
                'retry_after' => $seconds,
            ], 429)->withHeaders([
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key);

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, $limit),
        ]);
    }
}
