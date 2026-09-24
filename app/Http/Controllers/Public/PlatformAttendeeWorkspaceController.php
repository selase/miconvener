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
use App\Models\EventCertificate;
use App\Models\EventDynamicForm;
use App\Models\EventForumBan;
use App\Models\EventForumReply;
use App\Models\EventForumThread;
use App\Models\EventMaterial;
use App\Models\EventMaterialDownload;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Models\EventServiceRequest;
use App\Models\EventSessionAttendance;
use App\Services\Events\PlatformAttendeeWorkspaceAuthorizer;
use App\Services\Events\QrCodeGenerator;
use App\Services\Events\TicketPdfService;
use App\Services\Stratification\ParticipantStratificationService;
use App\Services\Tenancy\FeatureMeteringService;
use App\Support\ContactMask;
use Illuminate\Database\Eloquent\Builder;
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
                    'tenant' => fn ($t) => $t->withoutGlobalScopes(),
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
                ->filter(fn (EventMaterial $m): bool => $m->isReleased())
                ->map(fn (EventMaterial $m): array => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'provenance' => $m->provenance,
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

        $certificate = EventCertificate::withoutGlobalScopes()
            ->where('event_id', $event->id)
            ->where(function (Builder $q) use ($registrationModel): void {
                $q->where('registration_id', $registrationModel->id)
                    ->orWhereRaw('lower(recipient_email) = ?', [mb_strtolower($registrationModel->email)]);
            })
            ->first();

        $certificatePayload = $certificate !== null ? [
            'id' => $certificate->id,
            'uuid' => $certificate->uuid,
            'recipient_name' => $certificate->recipient_name,
            'role' => $certificate->role,
            'cpd_hours' => $certificate->cpd_hours,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'verification_url' => $certificate->verificationUrl(),
            'download_url' => url("/verify/cert/{$certificate->uuid}/download"),
        ] : null;

        $attendanceRecords = EventSessionAttendance::withoutGlobalScopes()
            ->where('registration_id', $registrationModel->id)
            ->with(['session' => fn ($s) => $s->withoutGlobalScopes()])
            ->latest('checked_in_at')
            ->get()
            ->map(fn (EventSessionAttendance $att): array => [
                'id' => $att->id,
                'session_title' => $att->session?->title,
                'checked_in_at' => $att->checked_in_at->toIso8601String(),
                'checked_out_at' => $att->checked_out_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Public/Events/AttendeePortal/Portal', [
            'event' => $this->buildEventPayload($event, revealDetails: $registrationModel->isConfirmed(), registration: $registrationModel),
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
            'certificate' => $certificatePayload,
            'attendance' => $attendanceRecords,
            'canRequestHelp' => $canRequestHelp,
            'poll_payment' => $pollPayment,
            'is_checkout_grant' => $this->authorizer->hasCheckoutGrant($request->session(), $registrationModel->id),
            'has_live_poll' => $event->polls()->live()->exists(),
            'has_forum' => $registrationModel->isConfirmed(),
            'has_forms' => $event->dynamicForms()->where('is_active', true)->exists(),
            'active_service_requests_count' => $event->serviceRequests()
                ->where('registration_id', $registrationModel->id)
                ->whereIn('status', [
                    EventServiceRequest::STATUS_OPEN,
                    EventServiceRequest::STATUS_ACKNOWLEDGED,
                    EventServiceRequest::STATUS_IN_PROGRESS,
                ])
                ->count(),
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

    public function serviceRequests(Request $request, string $registration): JsonResponse
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

        $requests = $event->serviceRequests()
            ->where('registration_id', $registrationModel->id)
            ->latest('created_at')
            ->get()
            ->map(fn (EventServiceRequest $sr): array => [
                'id' => $sr->id,
                'type' => $sr->type,
                'type_label' => $this->serviceRequestTypeLabel($sr->type),
                'location' => $sr->location,
                'note' => $sr->note,
                'priority' => $sr->priority,
                'status' => $sr->status,
                'created_at' => $sr->created_at->toIso8601String(),
                'resolved_at' => $sr->resolved_at?->toIso8601String(),
                'is_active' => in_array($sr->status, [
                    EventServiceRequest::STATUS_OPEN,
                    EventServiceRequest::STATUS_ACKNOWLEDGED,
                    EventServiceRequest::STATUS_IN_PROGRESS,
                ], true),
            ])
            ->values()
            ->all();

        return response()->json(['service_requests' => $requests]);
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

        $hasActive = $event->serviceRequests()
            ->where('registration_id', $registrationModel->id)
            ->where('type', $validated['type'])
            ->whereIn('status', [
                EventServiceRequest::STATUS_OPEN,
                EventServiceRequest::STATUS_ACKNOWLEDGED,
                EventServiceRequest::STATUS_IN_PROGRESS,
            ])
            ->exists();

        if ($hasActive) {
            return response()->json([
                'message' => "You already have an active {$validated['type']} request in progress.",
            ], 422);
        }

        $serviceRequest = $event->serviceRequests()->create([
            'tenant_id' => $registrationModel->tenant_id,
            'registration_id' => $registrationModel->id,
            'type' => $validated['type'],
            'status' => EventServiceRequest::STATUS_OPEN,
            'location' => $validated['location'] ?? null,
            'note' => $validated['note'] ?? null,
            'priority' => $validated['type'] === EventServiceRequest::TYPE_MEDICAL
                ? EventServiceRequest::PRIORITY_URGENT
                : EventServiceRequest::PRIORITY_NORMAL,
        ]);

        return response()->json([
            'message' => 'Someone is on the way.',
            'created_at' => $serviceRequest->created_at->toIso8601String(),
            'service_request' => [
                'id' => $serviceRequest->id,
                'type' => $serviceRequest->type,
                'type_label' => $this->serviceRequestTypeLabel($serviceRequest->type),
                'location' => $serviceRequest->location,
                'note' => $serviceRequest->note,
                'priority' => $serviceRequest->priority,
                'status' => $serviceRequest->status,
                'created_at' => $serviceRequest->created_at->toIso8601String(),
                'resolved_at' => null,
                'is_active' => true,
            ],
        ], 201);
    }

    public function poll(Request $request, string $registration): JsonResponse
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

        $poll = $event->polls()->live()->with('options')->first();
        $poll = $event->polls()->live()->with(['options' => fn ($q) => $q->withCount('responses')])->withCount('responses')->first();
        if (! $poll) {
            return response()->json(['poll' => null]);
        }

        $token = $this->pollRespondentToken($registrationModel, $poll);
        $existing = $poll->responses()->where('respondent_token', $token)->first();

        $totalVotes = (int) $poll->responses_count;

        $userResponse = $existing ? [
            'option_id' => $existing->option_id,
            'option_ids' => $existing->option_id ? [$existing->option_id] : [],
            'response_text' => $existing->response_text,
            'text_response' => $existing->response_text,
            'is_correct' => $existing->is_correct,
            'points_awarded' => $existing->points_awarded,
            'score' => $existing->points_awarded,
        ] : null;

        return response()->json([
            'poll' => [
                'id' => $poll->id,
                'question' => $poll->question,
                'type' => $poll->type,
                'timer_seconds' => $poll->timer_seconds,
                'points' => $poll->points,
                'went_live_at' => $poll->went_live_at?->toIso8601String(),
                'is_time_up' => $poll->type === EventPoll::TYPE_QUIZ && $poll->timeUp(),
                'total_votes' => $totalVotes,
                'options' => $poll->options->map(fn (EventPollOption $o): array => [
                    'id' => $o->id,
                    'label' => $o->label,
                    'votes_count' => (int) ($o->responses_count ?? 0),
                    'percentage' => $totalVotes > 0 ? (int) round((((int) ($o->responses_count ?? 0)) / $totalVotes) * 100) : 0,
                ])->values()->all(),
                'user_response' => $userResponse,
            ],
            'has_responded' => $existing !== null,
            'my_response' => $userResponse,
        ]);
    }

    public function respondPoll(Request $request, string $registration, string $poll): JsonResponse
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

        $pollModel = $event->polls()->live()->where('id', $poll)->with('options')->firstOrFail();

        if ($pollModel->type === EventPoll::TYPE_QUIZ && $pollModel->timeUp()) {
            return response()->json(['message' => "Time's up for this question."], 422);
        }

        $token = $this->pollRespondentToken($registrationModel, $pollModel);
        if ($pollModel->responses()->where('respondent_token', $token)->exists()) {
            return response()->json(['message' => 'You already responded to this poll.'], 422);
        }

        if ($request->has('text_response') && ! $request->has('response_text')) {
            $request->merge(['response_text' => $request->input('text_response')]);
        }

        $validated = $request->validate([
            'option_id' => [
                in_array($pollModel->type, [EventPoll::TYPE_MULTIPLE_CHOICE, EventPoll::TYPE_QUIZ], true) ? 'required' : 'prohibited',
                'nullable',
                Rule::exists('event_poll_options', 'id')->where('poll_id', $pollModel->id),
            ],
            'response_text' => [
                $pollModel->type === EventPoll::TYPE_OPEN ? 'required' : 'prohibited',
                'nullable',
                'string',
                'max:1000',
            ],
            'respondent_name' => ['nullable', 'string', 'max:100'],
        ]);

        $selectedOption = $pollModel->type === EventPoll::TYPE_QUIZ
            ? $pollModel->options->firstWhere('id', $validated['option_id'] ?? null)
            : null;

        $response = $pollModel->responses()->create([
            'tenant_id' => $registrationModel->tenant_id,
            'option_id' => $validated['option_id'] ?? null,
            'response_text' => $validated['response_text'] ?? null,
            'respondent_name' => $validated['respondent_name'] ?? null,
            'respondent_token' => $token,
            'is_correct' => $selectedOption ? $selectedOption->is_correct : null,
            'points_awarded' => $selectedOption?->is_correct ? $pollModel->points : 0,
            'is_approved' => $pollModel->type === EventPoll::TYPE_OPEN && $pollModel->requires_moderation ? null : true,
        ]);

        return response()->json([
            'message' => 'Thanks for responding!',
            'is_correct' => $pollModel->type === EventPoll::TYPE_QUIZ ? $response->is_correct : null,
            'points_awarded' => $response->points_awarded,
            'score' => $response->points_awarded,
        ]);
    }

    public function forum(Request $request, string $registration): JsonResponse
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

        $voterToken = $this->forumVoterToken($registrationModel, $event);
        $threads = $event->forumThreads()->visible()->with(['replies', 'votes'])->get();
        $isBanned = EventForumBan::where('event_id', $event->id)->where('author_email', $registrationModel->email)->exists();

        return response()->json([
            'is_banned' => $isBanned,
            'threads' => $threads->map(fn (EventForumThread $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'body' => $t->body,
                'author_name' => $t->is_anonymous ? 'Anonymous' : $t->author_name,
                'is_answered' => $t->is_answered,
                'attachment_url' => $t->attachmentUrl(),
                'attachment_name' => $t->attachment_name,
                'votes_count' => $t->votes->count(),
                'voted_by_me' => $t->votes->contains('respondent_token', $voterToken),
                'created_at' => $t->created_at->toIso8601String(),
                'replies' => $t->replies->map(fn (EventForumReply $r): array => [
                    'id' => $r->id,
                    'author_name' => $r->author_name,
                    'body' => $r->body,
                    'tag' => $r->tag,
                    'is_from_host' => $r->isFromHost(),
                    'attachment_url' => $r->attachmentUrl(),
                    'attachment_name' => $r->attachment_name,
                    'created_at' => $r->created_at->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function storeForumThread(Request $request, string $registration): JsonResponse
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

        if (EventForumBan::where('event_id', $event->id)->where('author_email', $registrationModel->email)->exists()) {
            return response()->json(['message' => "You're no longer able to post in this event's forum."], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,ppt,pptx,txt,zip'],
        ]);

        $attachment = [];
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachment = [
                'attachment_path' => Helper::processUploadedFile($request, 'attachment', 'forum', 'event-forum', Event::uploadDisk()),
                'attachment_name' => $file->getClientOriginalName(),
                'attachment_size' => $file->getSize(),
            ];
        }

        $thread = $event->forumThreads()->create([
            'tenant_id' => $registrationModel->tenant_id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'author_name' => $registrationModel->full_name,
            'author_email' => $registrationModel->email,
            'is_anonymous' => (bool) ($validated['is_anonymous'] ?? false),
            ...$attachment,
        ]);

        return response()->json([
            'message' => 'Your question has been posted.',
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'body' => $thread->body,
                'author_name' => $thread->is_anonymous ? 'Anonymous' : $thread->author_name,
                'votes_count' => 0,
                'voted_by_me' => false,
                'created_at' => $thread->created_at->toIso8601String(),
                'replies' => [],
            ],
        ], 201);
    }

    public function voteForumThread(Request $request, string $registration, string $thread): JsonResponse
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

        $threadModel = $event->forumThreads()->visible()->where('id', $thread)->firstOrFail();
        $token = $this->forumVoterToken($registrationModel, $event);

        $exists = $threadModel->votes()->where('respondent_token', $token)->exists();
        if ($exists) {
            return response()->json(['message' => 'Already upvoted.'], 422);
        }

        $threadModel->votes()->create([
            'tenant_id' => $registrationModel->tenant_id,
            'respondent_token' => $token,
        ]);

        return response()->json([
            'votes_count' => $threadModel->votes()->count(),
            'voted_by_me' => true,
        ]);
    }

    public function unvoteForumThread(Request $request, string $registration, string $thread): JsonResponse
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

        $threadModel = $event->forumThreads()->visible()->where('id', $thread)->firstOrFail();
        $token = $this->forumVoterToken($registrationModel, $event);

        $threadModel->votes()->where('respondent_token', $token)->delete();

        return response()->json([
            'votes_count' => $threadModel->votes()->count(),
            'voted_by_me' => false,
        ]);
    }

    public function forms(Request $request, string $registration): JsonResponse
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

        $isCheckedIn = $registrationModel->checked_in_at !== null
            || $registrationModel->status === EventRegistration::STATUS_CHECKED_IN;

        $forms = $event->dynamicForms()
            ->where('is_active', true)
            ->latest('created_at')
            ->get()
            ->filter(fn (EventDynamicForm $f): bool => $f->isOpen())
            ->map(fn (EventDynamicForm $f): array => [
                'id' => $f->id,
                'title' => $f->title,
                'slug' => $f->slug,
                'description' => $f->description,
                'type' => $f->type,
                'requires_check_in' => $f->requires_check_in,
                'is_eligible' => ! $f->requires_check_in || $isCheckedIn,
                'has_submitted' => $f->submissions()->where('registration_id', $registrationModel->id)->exists(),
            ])
            ->values()
            ->all();

        return response()->json(['forms' => $forms]);
    }

    public function showForm(Request $request, string $registration, string $form): JsonResponse
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

        $formModel = $event->dynamicForms()->where('id', $form)->firstOrFail();

        if (! $formModel->isOpen()) {
            return response()->json(['message' => 'This form is currently closed for submissions.'], 422);
        }

        $isCheckedIn = $registrationModel->checked_in_at !== null
            || $registrationModel->status === EventRegistration::STATUS_CHECKED_IN;

        if ($formModel->requires_check_in && ! $isCheckedIn) {
            return response()->json([
                'message' => 'This evaluation is restricted to checked-in attendees of this conference.',
            ], 403);
        }

        $submission = $formModel->submissions()->where('registration_id', $registrationModel->id)->first();

        return response()->json([
            'form' => [
                'id' => $formModel->id,
                'title' => $formModel->title,
                'slug' => $formModel->slug,
                'description' => $formModel->description,
                'type' => $formModel->type,
                'schema' => $formModel->schema ?? [],
                'requires_check_in' => $formModel->requires_check_in,
                'is_eligible' => true,
            ],
            'schema' => $formModel->schema ?? [],
            'has_submitted' => $submission !== null,
            'submission' => $submission ? [
                'answers' => $submission->answers,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
            ] : null,
            'my_submission' => $submission ? [
                'answers' => $submission->answers,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function submitForm(Request $request, string $registration, string $form): JsonResponse
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

        $formModel = $event->dynamicForms()->where('id', $form)->firstOrFail();

        if (! $formModel->isOpen()) {
            return response()->json(['message' => 'This form is currently closed for submissions.'], 422);
        }

        $isCheckedIn = $registrationModel->checked_in_at !== null
            || $registrationModel->status === EventRegistration::STATUS_CHECKED_IN;

        if ($formModel->requires_check_in && ! $isCheckedIn) {
            return response()->json([
                'message' => 'This evaluation is restricted to checked-in attendees of this conference.',
            ], 403);
        }

        if ($formModel->submissions()->where('registration_id', $registrationModel->id)->exists()) {
            return response()->json(['message' => 'You have already submitted this form.'], 422);
        }

        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        $schema = $formModel->schema ?? [];
        $answers = $validated['answers'];
        $errors = [];
        foreach ($schema as $field) {
            $key = $field['key'] ?? '';
            $label = $field['label'] ?? $key;
            $isRequired = ! empty($field['required']);
            if ($isRequired && (! isset($answers[$key]) || $answers[$key] === '' || $answers[$key] === [])) {
                $errors["answers.{$key}"] = ["{$label} is required."];
            }
        }

        if (! empty($errors)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $submission = $formModel->submissions()->create([
            'tenant_id' => $registrationModel->tenant_id,
            'event_id' => $event->id,
            'registration_id' => $registrationModel->id,
            'user_id' => null,
            'respondent_name' => $registrationModel->full_name,
            'respondent_email' => $registrationModel->email,
            'answers' => $answers,
            'submitted_at' => now(),
        ]);

        // Sync dynamic participant groups
        $stratificationService = app(ParticipantStratificationService::class);
        $groups = $event->participantGroups()->where('type', 'dynamic')->get();
        foreach ($groups as $group) {
            $stratificationService->syncGroupMembers($group);
        }

        return response()->json([
            'message' => 'Form response submitted successfully. Thank you for your feedback!',
            'submission_id' => $submission->id,
        ], 201);
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

    private function pollRespondentToken(EventRegistration $registration, EventPoll $poll): string
    {
        return hash_hmac('sha256', "poll:{$registration->id}:{$poll->id}", (string) config('app.key'));
    }

    private function forumVoterToken(EventRegistration $registration, Event $event): string
    {
        return hash_hmac('sha256', "forum:{$registration->id}:event:{$event->id}", (string) config('app.key'));
    }

    private function serviceRequestTypeLabel(string $type): string
    {
        return match ($type) {
            EventServiceRequest::TYPE_REFRESHMENT => 'Water / Refreshment',
            EventServiceRequest::TYPE_ASSISTANCE => 'Help finding my seat',
            EventServiceRequest::TYPE_TECHNICAL => 'Technical assistance',
            EventServiceRequest::TYPE_ACCESSIBILITY => 'Step-free access',
            EventServiceRequest::TYPE_MEDICAL => 'First aid',
            default => 'Assistance',
        };
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
    private function buildEventPayload(Event $event, bool $revealDetails, ?EventRegistration $registration = null): array
    {
        $withhold = $event->isPrivate() && ! $revealDetails;

        return [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'organiser_slug' => $event->tenant->slug,
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
            'sessions' => ! $withhold && $event->relationLoaded('sessions') ? $event->sessions->map(function ($s) use ($registration): array {
                $sessionMaterials = [];
                if ($registration !== null && $registration->isConfirmed()) {
                    $sessionMaterials = app(\App\Services\Events\MaterialReleasePolicy::class)
                        ->releasedMaterialsForSession($s, $registration)
                        ->values()
                        ->all();
                }

                return [
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
                    'materials' => $sessionMaterials,
                ];
            })->values()->all() : [],
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
