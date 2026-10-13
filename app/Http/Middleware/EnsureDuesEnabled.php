<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides every dues console URL from a tenant that does not have member dues
 * switched on. A 404 rather than a 403, so a dark-launched feature does not
 * announce itself.
 */
final readonly class EnsureDuesEnabled
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenantContext->getTenant()?->duesEnabled()) {
            abort(404);
        }

        return $next($request);
    }
}
