<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides every Community console URL, and the start of a new dues checkout,
 * from a tenant without the Community switch. A 404 rather than a 403, so a
 * dark-launched feature does not announce itself.
 */
final readonly class EnsureCommunityEnabled
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenantContext->getTenant()?->communityEnabled()) {
            abort(404);
        }

        return $next($request);
    }
}
