<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\FeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureFeatureEnabled
{
    public function __construct(private FeatureService $featureService) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $this->featureService->enabled($feature)) {
            abort(403, "Feature [{$feature}] is disabled for this tenant.");
        }

        return $next($request);
    }
}
