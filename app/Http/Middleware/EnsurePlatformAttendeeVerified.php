<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Events\PlatformAttendeeVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a request only when the visitor has proven an address platform-wide,
 * and sets the verified address as a request attribute.
 */
final readonly class EnsurePlatformAttendeeVerified
{
    public function __construct(private PlatformAttendeeVerification $verification) {}

    public function handle(Request $request, Closure $next): Response
    {
        $email = $this->verification->verifiedEmail($request->session());

        if ($email === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Confirm your email address to see this.'], 401);
            }

            return redirect()->route('attendee.my');
        }

        $request->attributes->set('attendee_email', $email);

        return $next($request);
    }
}
