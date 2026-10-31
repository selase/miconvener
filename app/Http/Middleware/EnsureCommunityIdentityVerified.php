<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCommunityIdentityVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        $phone = app(PhoneVerification::class)->verifiedPhone($request->session());
        if ($email === null && $phone === null) {
            return $request->expectsJson() ? response()->json(['message' => 'Verify your email or phone first.'], 401) : redirect()->route('attendee.my');
        }
        $request->attributes->set('community_email', $email);
        $request->attributes->set('community_phone', $phone);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
