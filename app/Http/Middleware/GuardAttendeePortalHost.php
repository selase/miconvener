<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantHostMatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a non-canonical host before route matching and the tenant-aware web
 * middleware can resolve caller-controlled session or header state.
 */
final readonly class GuardAttendeePortalHost
{
    public function __construct(private TenantHostMatcher $hostMatcher) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('my', 'my/*')) {
            return $next($request);
        }

        $host = $this->hostMatcher->normalizeHost($request->getHost());

        if ($this->hostMatcher->tenantSlug($host) === null) {
            abort(404);
        }

        $request->headers->set('host', $host);
        $request->server->set('HTTP_HOST', $host);
        $request->server->set('SERVER_NAME', $host);

        return $next($request);
    }
}
