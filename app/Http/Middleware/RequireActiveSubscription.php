<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Services\Tenancy\TenantContext|null $context */
        $context = app(\App\Services\Tenancy\TenantContext::class);
        $tenant = $context ? $context->getTenant() : null;

        // Check if subscription is either strictly active OR on grace period
        if ($tenant && $tenant->latestSubscription && (!$tenant->latestSubscription->isActive() && ! $tenant->latestSubscription->onGracePeriod())) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Subscription requires payment.'], 402);
            }
            // Redirect to billing dashboard
            return redirect()->route('tenant.billing.dashboard')
                ->with('error', 'Your subscription is past due. Please update your payment method to restore access.');
        }

        return $next($request);
    }
}
