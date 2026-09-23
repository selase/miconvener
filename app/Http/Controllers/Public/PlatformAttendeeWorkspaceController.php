<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enum\TenantStatusEnum;
use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventTicketTransferCode;
use App\Mail\Events\EventTicketTransferred;
use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventMaterialDownload;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Models\EventServiceRequest;
use App\Services\Events\PlatformAttendeeWorkspaceAuthorizer;
use App\Services\Events\QrCodeGenerator;
use App\Services\Events\TicketPdfService;
use App\Services\Tenancy\FeatureMeteringService;
use App\Support\ContactMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PlatformAttendeeWorkspaceController extends Controller
{
    public function __construct(
        private readonly PlatformAttendeeWorkspaceAuthorizer $authorizer,
        private readonly TicketPdfService $ticketPdfService,
        private readonly FeatureMeteringService $meteringService,
    ) {}

    public function show(Request $request, string $registration): InertiaResponse|RedirectResponse
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->with([
                'event' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'sessions' => fn ($s) => $s->withoutGlobalScopes()->withCount('registrations')->with('speakers'),
                    'materials' => fn ($m) => $m->withoutGlobalScopes(),
                ]),
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
                'ticketType' => fn ($q) => $q->withoutGlobalScopes(),
                'sessions' => fn ($q) => $q->withoutGlobalScopes(),
                'seatAssignment' => fn ($q) => $q->withoutGlobalScopes()->with('room'),
            ])
            ->first();

        if ($registrationModel === null) {
            return $this->unauthorizedRedirect($request, $registration);
        }

        if (! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            return $this->unauthorizedRedirect($request, $registration, $registrationModel);
        }

        if ($registrationModel->tenant?->status === TenantStatusEnum::BANNED) {
            abort(404);
        }

        $event = $registrationModel->event;
        if ($event === null) {
            abort(404);
        }

        $materials = [];
        if ($registrationModel->isConfirmed()) {
            $materials = $event->materials
                ->filter(fn ($m): bool => $m instanceof EventMaterial && $m->isReleased())
                ->map(fn ($m): array => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'remaining_attempts' => $m->remainingAttemptsFor($registrationModel->id),
                    'download_url' => route('attendee.my.events.materials.download', [
                        'registration' => $registrationModel->id,
                        'material' => $m->id,
                    ]),
                ])
                ->values()
                ->all();
        }

        $canRequestHelp = $registrationModel->checked_in_at !== null
            || ($event->starts_at->isPast() && $event->ends_at->isFuture());

        $pollPayment = $registrationModel->status === EventRegistration::STATUS_PENDING_PAYMENT
            && filled($registrationModel->payment_reference);

        return Inertia::render('Public/Events/AttendeePortal/Portal', [
            'event' => $this->buildEventPayload($event, revealDetails: $registrationModel->isConfirmed()),
            'registration' => [
                'id' => $registrationModel->id,
                'full_name' => $registrationModel->full_name,
                'email' => ContactMask::email($registrationModel->email),
                'status' => $registrationModel->status,
                'ticket_code' => $registrationModel->ticket_code,
                'ticket_type_name' => $registrationModel->ticketType?->name,
                'qr_image' => $registrationModel->qr_token
                    ? QrCodeGenerator::svgDataUri($registrationModel->qr_token)
                    : null,
                'agenda_session_ids' => $registrationModel->sessions->pluck('id')->values(),
                'waitlist_position' => $registrationModel->waitlist_position,
                'approval_note' => $registrationModel->approval_note,
                'seat_label' => $registrationModel->seatAssignment?->seat_label,
                'room_name' => $registrationModel->seatAssignment?->room?->name,
                'checked_in' => $registrationModel->checked_in_at !== null,
                'email_verified' => $registrationModel->hasVerifiedEmail(),
                'awaiting_checkout' => $registrationModel->status === EventRegistration::STATUS_PENDING_PAYMENT
                    && blank($registrationModel->payment_reference),
                'checkout_url' => $registrationModel->status === EventRegistration::STATUS_PENDING_PAYMENT && $registrationModel->tenant !== null
                    ? route('public.events.checkout', [
                        'subdomain' => $registrationModel->tenant->slug,
                        'event' => $event->slug,
                        'registration' => $registrationModel->id,
                    ])
                    : null,
            ],
            'materials' => $materials,
            'canRequestHelp' => $canRequestHelp,
            'poll_payment' => $pollPayment,
            'is_checkout_grant' => $this->authorizer->hasCheckoutGrant($request->session(), $registrationModel->id),
        ]);
    }

    public function status(Request $request, string $registration): JsonResponse
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->first();

        if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json([
            'id' => $registrationModel->id,
            'status' => $registrationModel->status,
            'is_confirmed' => $registrationModel->isConfirmed(),
            'ticket_code' => $registrationModel->ticket_code,
            'qr_image' => $registrationModel->qr_token
                ? QrCodeGenerator::svgDataUri($registrationModel->qr_token)
                : null,
            'checked_in' => $registrationModel->checked_in_at !== null,
            'payment_confirmed' => $registrationModel->isConfirmed(),
        ]);
    }

    public function ticket(Request $request, string $registration): Response
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->with(['event', 'tenant', 'ticketType'])
            ->first();

        if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            abort(401);
        }

        if (! $registrationModel->isConfirmed()) {
            abort(403, 'Ticket has not been issued yet.');
        }

        $pdfContent = $this->ticketPdfService->generate($registrationModel);
        $filename = $this->ticketPdfService->filename($registrationModel);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function transfer(Request $request, string $registration): JsonResponse
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->with(['event', 'tenant'])
            ->first();

        if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if ($registrationModel->status === EventRegistration::STATUS_CHECKED_IN) {
            return response()->json(['message' => 'This ticket has already been checked in and can no longer be transferred.'], 422);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        EventRegistrationTransfer::withoutGlobalScopes()
            ->where('registration_id', $registrationModel->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = EventRegistrationTransfer::generateCode();

        $transfer = EventRegistrationTransfer::create([
            'tenant_id' => $registrationModel->tenant_id,
            'registration_id' => $registrationModel->id,
            'to_full_name' => $validated['full_name'],
            'to_email' => $validated['email'],
            'to_phone' => $validated['phone'] ?? null,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(EventRegistrationTransfer::TTL_MINUTES),
        ]);

        $transfer->setRelation('registration', $registrationModel);

        Mail::to($registrationModel->email)->queue(new EventTicketTransferCode($transfer, $code));
        if ($registrationModel->tenant !== null) {
            $this->meteringService->recordUsage($registrationModel->tenant, 'email_credits');
        }

        return response()->json([
            'message' => 'We emailed a confirmation code to '.ContactMask::email($registrationModel->email).'. Enter it to complete the transfer.',
            'transfer_id' => $transfer->id,
            'expires_in_minutes' => EventRegistrationTransfer::TTL_MINUTES,
        ]);
    }

    public function confirmTransfer(Request $request, string $registration): JsonResponse
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->with(['event', 'tenant'])
            ->first();

        if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $transfer = EventRegistrationTransfer::withoutGlobalScopes()
            ->where('registration_id', $registrationModel->id)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if (! $transfer || ! $transfer->isUsable()) {
            return response()->json(['message' => 'That code has expired. Start the transfer again.'], 422);
        }

        if (! $transfer->matches($validated['code'])) {
            $transfer->increment('attempts');
            $remaining = max(0, EventRegistrationTransfer::MAX_ATTEMPTS - $transfer->attempts);

            return response()->json([
                'message' => $remaining > 0
                    ? "That code is not right. {$remaining} attempt(s) left."
                    : 'Too many incorrect codes. Start the transfer again.',
            ], 422);
        }

        if ($registrationModel->status === EventRegistration::STATUS_CHECKED_IN) {
            return response()->json(['message' => 'This ticket has been checked in and can no longer be transferred.'], 422);
        }

        $previousName = $registrationModel->full_name;
        $previousEmail = $registrationModel->email;

        // Atomically rotate ticket code and QR token upon transfer
        $registrationModel->rotateTicketCredentials();
        $registrationModel->update([
            'full_name' => $transfer->to_full_name,
            'email' => $transfer->to_email,
            'phone' => $transfer->to_phone,
            'ticket_code' => $registrationModel->ticket_code,
            'qr_token' => $registrationModel->qr_token,
        ]);

        $transfer->update(['consumed_at' => now()]);

        // Revoke any checkout grant the previous browser held
        $this->authorizer->revokeCheckoutAccess($request->session(), $registrationModel->id);

        Mail::to($previousEmail)->queue(new EventTicketTransferred(
            $registrationModel,
            $previousName,
            $registrationModel->full_name,
            $registrationModel->email,
        ));

        if ($registrationModel->tenant !== null) {
            $this->meteringService->recordUsage($registrationModel->tenant, 'email_credits');
        }

        if ($registrationModel->isConfirmed()) {
            Mail::to($registrationModel->email)->queue(new EventRegistrationConfirmed($registrationModel));
            if ($registrationModel->tenant !== null) {
                $this->meteringService->recordUsage($registrationModel->tenant, 'email_credits');
            }
        }

        return response()->json(['message' => 'Ticket transferred. The new holder has been emailed.']);
    }

    public function addToAgenda(Request $request, string $registration, string $session): JsonResponse
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->with(['event', 'tenant'])
            ->first();

        if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $sessionModel = $registrationModel->event?->sessions()->where('id', $session)->first();
        if ($sessionModel === null) {
            abort(404);
        }

        $alreadySignedUp = $registrationModel->sessions()->where('session_id', $sessionModel->id)->exists();
        if (! $alreadySignedUp && $sessionModel->isFull()) {
            return response()->json(['message' => 'This session is full.'], 422);
        }

        $registrationModel->sessions()->syncWithoutDetaching([
            $sessionModel->id => ['id' => (string) Str::uuid(), 'tenant_id' => $registrationModel->tenant_id],
        ]);

        return response()->json(['message' => 'Added to your day.']);
    }

    public function removeFromAgenda(Request $request, string $registration, string $session): JsonResponse
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->first();

        if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $registrationModel->sessions()->detach($session);

        return response()->json(['message' => 'Removed from your day.']);
    }

    public function storeServiceRequest(Request $request, string $registration): JsonResponse
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->with(['event'])
            ->first();

        if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $event = $registrationModel->event;
        if ($event === null) {
            abort(404);
        }

        $eventIsRunning = $event->starts_at->isPast() && $event->ends_at->isFuture();
        if (! $eventIsRunning && $registrationModel->checked_in_at === null) {
            return response()->json([
                'message' => 'Requests open when the event starts. Contact the organizer if you need something before then.',
            ], 422);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(EventServiceRequest::TYPES)],
            'location' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $serviceRequest = $event->serviceRequests()->create([
            'tenant_id' => $registrationModel->tenant_id,
            'registration_id' => $registrationModel->id,
            'type' => $validated['type'],
            'location' => $validated['location'] ?? null,
            'note' => $validated['note'] ?? null,
            'priority' => $validated['type'] === EventServiceRequest::TYPE_MEDICAL
                ? EventServiceRequest::PRIORITY_URGENT
                : EventServiceRequest::PRIORITY_NORMAL,
        ]);

        return response()->json([
            'message' => 'Someone is on the way.',
            'created_at' => $serviceRequest->created_at->toIso8601String(),
        ]);
    }

    public function downloadMaterial(Request $request, string $registration, string $material): StreamedResponse
    {
        $registrationModel = EventRegistration::withoutGlobalScopes()
            ->where('id', $registration)
            ->with(['event'])
            ->first();

        if ($registrationModel === null || ! $this->authorizer->canAccess($request->session(), $registrationModel)) {
            abort(401);
        }

        if (! $registrationModel->isConfirmed()) {
            abort(403, 'This registration is not confirmed.');
        }

        $materialModel = EventMaterial::withoutGlobalScopes()
            ->where('id', $material)
            ->where('event_id', $registrationModel->event_id)
            ->firstOrFail();

        if (! $materialModel->isReleased()) {
            abort(403, 'This material has not been released yet.');
        }

        if ($materialModel->remainingAttemptsFor($registrationModel->id) <= 0) {
            abort(429, 'You have used all your download attempts for this file.');
        }

        $disk = Event::uploadDisk();
        if (! Storage::disk($disk)->exists($materialModel->file_path)) {
            abort(404);
        }

        EventMaterialDownload::create([
            'tenant_id' => $registrationModel->tenant_id,
            'material_id' => $materialModel->id,
            'registration_id' => $registrationModel->id,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return Storage::disk($disk)->response(
            $materialModel->file_path,
            $this->filenameForMaterial($materialModel),
            array_filter([
                'Content-Type' => $materialModel->mime_type,
                'Cache-Control' => 'private, no-store',
            ]),
        );
    }

    private function unauthorizedRedirect(Request $request, string $registrationId, ?EventRegistration $registration = null): RedirectResponse
    {
        if ($registration !== null && \App\Services\Events\PlatformAttendeeVerification::getVerifiedEmail() !== null) {
            abort(403, 'Unauthorized.');
        }

        return redirect('/my?return_to='.urlencode("/my/events/{$registrationId}"));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEventPayload(Event $event, bool $revealDetails): array
    {
        $withhold = $event->isPrivate() && ! $revealDetails;

        return [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'description' => $event->description,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'timezone' => $event->timezone,
            'location_type' => $event->location_type,
            'address' => $event->address,
            'virtual_link' => $event->virtual_link,
            'ticket_price' => $event->ticket_price,
            'currency' => $event->currency,
            'hero_image_url' => Helper::storageUrl($event->hero_image_path),
            'sessions' => ! $withhold && $event->relationLoaded('sessions') ? $event->sessions->map(fn ($s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'description' => $s->description,
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'location' => $s->location,
                'track' => $s->track,
                'type' => $s->type,
                'capacity' => $s->capacity,
                'signup_count' => $s->registrations_count ?? 0,
                'speaker_names' => $s->relationLoaded('speakers') ? $s->speakers->pluck('name')->values() : [],
            ])->values()->all() : [],
        ];
    }

    private function filenameForMaterial(EventMaterial $material): string
    {
        $title = mb_trim(str_replace(['/', '\\'], '-', $material->title));
        $extension = pathinfo($material->file_path, PATHINFO_EXTENSION);

        if ($extension === '' || str_ends_with(mb_strtolower($title), '.'.mb_strtolower($extension))) {
            return $title;
        }

        return "{$title}.{$extension}";
    }
}
