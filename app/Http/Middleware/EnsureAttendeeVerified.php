<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Events\AttendeeVerification;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a request only when the visitor has proven an address for the tenant
 * this subdomain belongs to, and hands controllers that address.
 *
 * The tenant comes from TenantContext -- the Host header, via ResolveTenant --
 * never from input. Controllers read the address from the request attribute,
 * never from input, so a request cannot ask for someone else's data.
 */
final readonly class EnsureAttendeeVerified
{
    public function __construct(private AttendeeVerification $verification) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->getTenant();
        $email = $tenant !== null ? $this->verification->verifiedEmail($request->session(), $tenant) : null;

        if ($email === null) {
            return response()->json(['message' => 'Confirm your email address to see this.'], 401);
        }

        $request->attributes->set('attendee_email', $email);

        return $next($request);
    }
}
