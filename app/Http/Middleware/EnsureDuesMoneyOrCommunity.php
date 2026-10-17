<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps dues payouts reachable while a tenant holds dues money, even after the
 * Community switch is turned off: the switch stops new activity, never the
 * settlement of money already collected.
 */
final readonly class EnsureDuesMoneyOrCommunity
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantContext->getTenant();

        if (! $tenant || (! $tenant->communityEnabled() && ! $tenant->hasDuesMoney() && ! $tenant->hasGivingMoney())) {
            abort(404);
        }

        return $next($request);
    }
}
