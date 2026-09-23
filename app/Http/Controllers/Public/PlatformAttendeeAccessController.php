<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\PlatformConfirmAccessCodeRequest;
use App\Http\Requests\Attendee\PlatformSendAccessCodeRequest;
use App\Models\Tenant;
use App\Services\Events\AttendeePortalRateLimiter;
use App\Services\Events\PlatformAttendeeVerification;
use App\Support\ContactMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformAttendeeAccessController extends Controller
{
    public function __construct(
        private readonly PlatformAttendeeVerification $verification,
        private readonly AttendeePortalRateLimiter $rateLimiter,
    ) {}

    public function page(Request $request): Response
    {
        $verifiedEmail = $this->verification->verifiedEmail($request->session());

        $organiser = null;
        if ($request->filled('organiser')) {
            $tenant = Tenant::query()->where('slug', (string) $request->input('organiser'))->first();
            if ($tenant !== null) {
                $organiser = [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ];
            }
        }

        return Inertia::render('Public/Events/AttendeePortal/MyPortal', [
            'organiser' => $organiser,
            'verifiedEmail' => $verifiedEmail !== null ? ContactMask::email($verifiedEmail) : null,
        ]);
    }

    public function send(PlatformSendAccessCodeRequest $request): JsonResponse
    {
        $email = (string) $request->input('email');
        $ip = (string) $request->ip();
        $token = $request->filled('turnstile_token') ? (string) $request->input('turnstile_token') : null;

        $result = $this->verification->requestSend($email, $ip, $token);

        if ($result['status'] === AttendeePortalRateLimiter::RESULT_CHALLENGE_REQUIRED) {
            return response()->json([
                'message' => 'Please complete the verification challenge.',
                'challenge_required' => true,
                'site_key' => $result['site_key'] ?? null,
            ], 428);
        }

        if ($result['status'] === AttendeePortalRateLimiter::RESULT_CHALLENGE_FAILED) {
            return response()->json([
                'message' => 'Verification challenge failed. Please try again.',
                'challenge_required' => true,
                'site_key' => $result['site_key'] ?? null,
            ], 422);
        }

        if ($result['status'] === AttendeePortalRateLimiter::RESULT_RATE_LIMITED) {
            return response()->json([
                'message' => 'Too many code requests. Please wait a moment and try again.',
            ], 429);
        }

        return response()->json([
            'message' => 'A sign-in code is on its way to your email.',
        ], 202);
    }

    public function confirm(PlatformConfirmAccessCodeRequest $request): JsonResponse
    {
        $email = (string) $request->input('email');
        $code = (string) $request->input('code');
        $ip = (string) $request->ip();

        if (! $this->rateLimiter->checkConfirm(PlatformAttendeeVerification::normalise($email), $ip)) {
            return response()->json([
                'message' => 'Too many verification attempts. Please try again later.',
            ], 429);
        }

        $confirmed = $this->verification->confirm($request->session(), $email, $code, $ip);

        if (! $confirmed) {
            return response()->json([
                'message' => "That code didn't match. Check it, or ask for a new one.",
            ], 422);
        }

        return response()->json([
            'email' => ContactMask::email(PlatformAttendeeVerification::normalise($email)),
        ]);
    }

    public function forget(Request $request): JsonResponse
    {
        $this->verification->forget($request->session());

        return response()->json([
            'message' => 'Signed out.',
        ]);
    }

    public function session(Request $request): JsonResponse
    {
        $email = (string) $request->attributes->get('attendee_email');

        return response()->json([
            'email' => ContactMask::email($email),
        ]);
    }
}
