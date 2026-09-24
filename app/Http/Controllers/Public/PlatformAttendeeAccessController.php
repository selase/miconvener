<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\PlatformConfirmAccessCodeRequest;
use App\Http\Requests\Attendee\PlatformSendAccessCodeRequest;
use App\Models\Tenant;
use App\Services\Events\AttendeePortalRateLimiter;
use App\Services\Events\PlatformAttendeeHistory;
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
        private readonly PlatformAttendeeHistory $history,
    ) {}

    public function page(Request $request): Response
    {
        $verifiedEmail = $this->verification->verifiedEmail($request->session());

        $organiser = null;
        $organiserSlug = $request->filled('organiser') ? (string) $request->input('organiser') : null;
        if ($organiserSlug !== null) {
            $tenant = Tenant::query()->where('slug', $organiserSlug)->first();
            if ($tenant !== null) {
                $organiser = [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ];
            }
        }

        $history = null;
        $certificates = null;
        $abstracts = null;
        $attendance = null;
        if ($verifiedEmail !== null) {
            $history = $this->history->getHistory($verifiedEmail, $organiserSlug);
            $certificates = $this->history->getCertificates($verifiedEmail, $organiserSlug);
            $abstracts = $this->history->getAbstracts($verifiedEmail, $organiserSlug);
            $attendance = $this->history->getAttendance($verifiedEmail, $organiserSlug);
        }

        return Inertia::render('Public/Events/AttendeePortal/MyPortal', [
            'organiser' => $organiser,
            'verifiedEmail' => $verifiedEmail !== null ? ContactMask::email($verifiedEmail) : null,
            'initialHistory' => $history,
            'initialCertificates' => $certificates,
            'initialAbstracts' => $abstracts,
            'initialAttendance' => $attendance,
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
                'action' => $result['action'] ?? null,
            ], 428);
        }

        if ($result['status'] === AttendeePortalRateLimiter::RESULT_CHALLENGE_FAILED) {
            return response()->json([
                'message' => 'Verification challenge failed. Please try again.',
                'challenge_required' => true,
                'site_key' => $result['site_key'] ?? null,
                'action' => $result['action'] ?? null,
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

    public function events(Request $request): JsonResponse
    {
        $email = (string) $request->attributes->get('attendee_email');
        $organiserSlug = $request->filled('organiser') ? (string) $request->input('organiser') : null;

        return response()->json($this->history->getHistory($email, $organiserSlug));
    }

    public function certificates(Request $request): JsonResponse
    {
        $email = (string) $request->attributes->get('attendee_email');
        $organiserSlug = $request->filled('organiser') ? (string) $request->input('organiser') : null;

        return response()->json($this->history->getCertificates($email, $organiserSlug));
    }

    public function abstracts(Request $request): JsonResponse
    {
        $email = (string) $request->attributes->get('attendee_email');
        $organiserSlug = $request->filled('organiser') ? (string) $request->input('organiser') : null;

        return response()->json($this->history->getAbstracts($email, $organiserSlug));
    }

    public function attendance(Request $request): JsonResponse
    {
        $email = (string) $request->attributes->get('attendee_email');
        $organiserSlug = $request->filled('organiser') ? (string) $request->input('organiser') : null;

        return response()->json($this->history->getAttendance($email, $organiserSlug));
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => 'MiConvener Attendee Portal',
            'short_name' => 'My Events',
            'description' => 'Your tickets, materials, and agendas across all MiConvener events',
            'start_url' => '/my',
            'scope' => '/my/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#4f46e5',
            'icons' => [
                [
                    'src' => '/assets/img/brand/mark-180.png',
                    'sizes' => '180x180',
                    'type' => 'image/png',
                ],
                [
                    'src' => '/assets/img/brand/mark-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
