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

        if ($this->hostMatcher->isPlatformHost($host)) {
            $request->headers->set('host', $host);
            $request->server->set('HTTP_HOST', $host);
            $request->server->set('SERVER_NAME', $host);
            $request->attributes->set('is_platform_attendee_portal', true);

            return $next($request);
        }

        $baseDomain = $this->hostMatcher->baseDomain();

        if ($baseDomain !== '' && $request->isMethod('GET') && $request->is('my')) {
            $scheme = $request->getScheme();

            if ($this->hostMatcher->isWwwHost($host)) {
                $query = $request->getQueryString();
                $target = "{$scheme}://{$baseDomain}/my".($query ? "?{$query}" : '');

                return redirect()->to($target);
            }

            $slug = $this->hostMatcher->tenantSlug($host);
            if ($slug !== null) {
                $query = $request->getQueryString();
                $params = [];
                if ($query) {
                    parse_str($query, $params);
                }
                $params['organiser'] = $slug;
                $targetQuery = http_build_query($params);
                $target = "{$scheme}://{$baseDomain}/my".($targetQuery !== '' ? "?{$targetQuery}" : '');

                return redirect()->to($target);
            }
        }

        abort(404);
    }
}
