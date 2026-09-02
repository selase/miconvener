<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureOnboardingComplete
{
    public function __construct(
        private TenantContext $context
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for non-authenticated or if user is Superadmin (internal tool access)
        if (! auth()->check() || \Illuminate\Support\Facades\Gate::allows('access-superadmin-dashboard')) {
            return $next($request);
        }

        $tenant = $this->context->getTenant();

        if ($tenant && ! $tenant->onboarding_completed_at) {
            // We no longer force a redirect. Onboarding is optional.
            // However, we can still set a flag for the dashboard to show a reminder.
            session()->flash('onboarding_incomplete', true);
        }

        return $next($request);
    }
}
