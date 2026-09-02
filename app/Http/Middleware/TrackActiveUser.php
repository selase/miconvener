<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\UsageService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final readonly class TrackActiveUser
{
    public function __construct(
        private TenantContext $context,
        private UsageService $usageService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->getTenant();
        $user = $request->user();

        if ($tenant && $user) {
            $cacheKey = "active_user_{$tenant->id}_{$user->id}_".now()->format('Y-m-d');

            // Check cache first to avoid recording the same user multiple times a day
            if (! Cache::has($cacheKey)) {
                $this->usageService->recordActiveUser($tenant, (string) $user->id);
                Cache::put($cacheKey, true, now()->endOfDay());
            }
        }

        return $next($request);
    }
}
