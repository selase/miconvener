<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;
use App\Services\Api\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final readonly class IdentifyMarketplaceMcpPartner
{
    public function __construct(
        private ApiKeyService $apiKeyService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key');

        if (! $key && $request->bearerToken()) {
            $key = $request->bearerToken();
        }

        // If no API key was provided, proceed under the public tier (anonymous rate limit).
        if (! $key) {
            return $next($request);
        }

        $ip = $request->ip() ?: 'unknown';
        $rateLimitKey = 'mcp_invalid_auth:'.$ip;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message' => 'Too many failed authentication attempts. Please try again later.',
                'error' => 'Too Many Requests',
            ], 429, ['Retry-After' => $retryAfter]);
        }

        $apiKey = $this->apiKeyService->authenticate((string) $key);

        if (! $apiKey || ! $apiKey->isValid()) {
            RateLimiter::hit($rateLimitKey, 60);

            return response()->json([
                'message' => 'Invalid or expired API Key.',
                'error' => 'Unauthorized',
            ], 401);
        }

        // IP Restriction Check on the API key
        if ($apiKey->ip_restrictions && ! empty($apiKey->ip_restrictions) && ! in_array($request->ip(), $apiKey->ip_restrictions, true)) {
            return response()->json([
                'message' => 'IP address not allowed for this API Key.',
                'error' => 'Forbidden',
            ], 403);
        }

        /** @var Tenant|null $tenant */
        $tenant = $apiKey->tenant;

        // Ensure tenant account is active
        if (! $tenant || $tenant->status !== TenantStatusEnum::ACTIVE) {
            return response()->json([
                'message' => 'Tenant account is not active.',
                'error' => 'Forbidden',
            ], 403);
        }

        // Tenant-level IP Restriction Check
        if ($tenant->allowed_ips && ! empty($tenant->allowed_ips) && ! in_array($request->ip(), $tenant->allowed_ips, true)) {
            return response()->json([
                'message' => 'Tenant IP restriction: IP address not allowed.',
                'error' => 'Forbidden',
            ], 403);
        }

        // Successful authentication clears failed attempts
        RateLimiter::clear($rateLimitKey);

        // Set partner attributes on request for rate limiters and MCP tools
        $request->attributes->set('mcp_partner_api_key', $apiKey);
        $request->attributes->set('mcp_partner_tenant', $tenant);

        return $next($request);
    }
}
