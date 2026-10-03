<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enum\TenantStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\PlatformConfirmAccessCodeRequest;
use App\Http\Requests\Attendee\PlatformSendAccessCodeRequest;
use App\Http\Requests\Attendee\UpdateContributionTributeRequest;
use App\Models\EventContribution;
use App\Models\Tenant;
use App\Services\Events\AttendeePortalRateLimiter;
use App\Services\Events\DonationReceiptPdfService;
use App\Services\Events\PlatformAttendeeHistory;
use App\Services\Events\PlatformAttendeeVerification;
use App\Support\ContactMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class PlatformAttendeeAccessController extends Controller
{
    public function __construct(
        private readonly PlatformAttendeeVerification $verification,
        private readonly AttendeePortalRateLimiter $rateLimiter,
        private readonly PlatformAttendeeHistory $history,
        private readonly DonationReceiptPdfService $pdfService,
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
        $contributions = null;
        if ($verifiedEmail !== null) {
            $history = $this->history->getHistory($verifiedEmail, $organiserSlug);
            $certificates = $this->history->getCertificates($verifiedEmail, $organiserSlug);
            $abstracts = $this->history->getAbstracts($verifiedEmail, $organiserSlug);
            $attendance = $this->history->getAttendance($verifiedEmail, $organiserSlug);
            $contributions = $this->history->getContributions($verifiedEmail, $organiserSlug);
        }

        return Inertia::render('Public/Events/AttendeePortal/MyPortal', [
            'organiser' => $organiser,
            'verifiedEmail' => $verifiedEmail !== null ? ContactMask::email($verifiedEmail) : null,
            'initialHistory' => $history,
            'initialCertificates' => $certificates,
            'initialAbstracts' => $abstracts,
            'initialAttendance' => $attendance,
            'initialContributions' => $contributions,
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

    public function contributions(Request $request): JsonResponse
    {
        $email = (string) $request->attributes->get('attendee_email');
        $organiserSlug = $request->filled('organiser') ? (string) $request->input('organiser') : null;

        return response()->json($this->history->getContributions($email, $organiserSlug));
    }

    public function downloadReceipt(Request $request, string $contribution): SymfonyResponse
    {
        $email = (string) $request->attributes->get('attendee_email');
        $emailNormalized = PlatformAttendeeVerification::normalise($email);

        /** @var EventContribution|null $record */
        $record = EventContribution::withoutGlobalScopes()
            ->with([
                'event' => fn ($query) => $query->withoutGlobalScopes(),
                'tenant' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('id', $contribution)
            ->first();

        if ($record === null) {
            abort(404, 'Contribution record not found.');
        }

        if ($record->tenant?->status === TenantStatusEnum::BANNED) {
            abort(404, 'Contribution record not found.');
        }

        if (PlatformAttendeeVerification::normalise((string) $record->contributor_email) !== $emailNormalized) {
            abort(403, 'You are not authorized to access this receipt.');
        }

        if (! $record->isCompleted()) {
            abort(422, 'Receipt is only available for completed contributions.');
        }

        $pdfContent = $this->pdfService->generate($record);
        $filename = $this->pdfService->filename($record);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function updateTribute(UpdateContributionTributeRequest $request, string $contribution): JsonResponse
    {
        $email = (string) $request->attributes->get('attendee_email');
        $emailNormalized = PlatformAttendeeVerification::normalise($email);

        /** @var EventContribution|null $record */
        $record = EventContribution::withoutGlobalScopes()
            ->with(['tenant' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('id', $contribution)
            ->first();

        if ($record === null) {
            abort(404, 'Contribution record not found.');
        }

        if ($record->tenant?->status === TenantStatusEnum::BANNED) {
            abort(404, 'Contribution record not found.');
        }

        if (PlatformAttendeeVerification::normalise((string) $record->contributor_email) !== $emailNormalized) {
            abort(403, 'You are not authorized to edit this tribute.');
        }

        if (! $record->isCompleted()) {
            abort(422, 'Tributes can only be updated for completed contributions.');
        }

        $validated = $request->validated();

        $message = array_key_exists('tribute_message', $validated)
            ? ($validated['tribute_message'] !== null ? mb_trim(strip_tags((string) $validated['tribute_message'])) : null)
            : $record->tribute_message;

        if ($message === '') {
            $message = null;
        }

        $isAnonymous = array_key_exists('is_anonymous', $validated)
            ? (bool) $validated['is_anonymous']
            : $record->is_anonymous;

        // If tribute note changed, reset approval so it goes through moderation
        if ($message !== $record->tribute_message) {
            $record->tribute_message = $message;
            $record->is_approved = false;
        }

        $record->is_anonymous = $isAnonymous;
        $record->save();

        return response()->json([
            'message' => 'Tribute message updated successfully.',
            'contribution' => [
                'id' => $record->id,
                'tribute_message' => $record->tribute_message,
                'is_anonymous' => $record->is_anonymous,
                'is_approved' => $record->is_approved,
            ],
        ]);
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
