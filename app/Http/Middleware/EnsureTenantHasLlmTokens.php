<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Llm\LlmUsageService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantHasLlmTokens
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

        $llmUsageService = app(LlmUsageService::class);

        // We check with 1 token as a base check.
        // Real-time checks with estimated tokens for a specific request would be done in the controller/service.
        if (! $llmUsageService->canConsume($tenant->id, 1)) {
            return response()->json([
                'message' => 'Llm token quota exceeded. Please upgrade your plan or top up your tokens.',
                'error_code' => 'QUOTA_EXCEEDED',
            ], 403);
        }

        return $next($request);
    }
}
