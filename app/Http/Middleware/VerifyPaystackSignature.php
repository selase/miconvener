<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyPaystackSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Bypass signature check if we only allow from localhost (useful for local dev/testing)
        // Note: For production, we should ensure the IP is coming from Paystack.

        $signature = $request->header('x-paystack-signature');
        $secret = config('services.paystack.secret_key');

        if (! $signature || ! $secret) {
            return response()->json(['message' => 'Missing signature or configuration'], 400);
        }

        $payload = $request->getContent();

        if (! hash_equals(hash_hmac('sha512', $payload, (string) $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        return $next($request);
    }
}
