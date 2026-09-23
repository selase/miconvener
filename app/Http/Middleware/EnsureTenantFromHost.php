<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantHostMatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a request only when the address bar names the organisation through
 * its existing MiConvener tenant subdomain.
 *
 * A tenant can also be decided by the browser's session, an X-Tenant header,
 * or a route parameter. Those are fine for the console, where the visitor has
 * already signed in to one organisation. They are not fine for a page that
 * hands out an attendee's history to whoever proves an email address: the
 * header in particular is chosen by the caller, and the subdomain routes match
 * reserved labels like www, where nothing in the host names an organisation.
 *
 * External custom-domain portal routing is deliberately not claimed here. It
 * needs its own route registration and domain-ownership boundary first.
 *
 * The answer is 404 rather than 403, so a refusal says nothing about whether
 * the organisation exists.
 */
final readonly class EnsureTenantFromHost
{
    public function __construct(
        private TenantContext $context,
        private TenantHostMatcher $hostMatcher,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->getTenant();

        if ($tenant === null || ! $this->hostMatcher->matches($tenant, $request->getHost())) {
            abort(404);
        }

        return $next($request);
    }
}
