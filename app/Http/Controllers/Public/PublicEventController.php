<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationPendingApproval;
use App\Mail\Events\EventRegistrationWaitlisted;
use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use App\Services\Events\QrCodeGenerator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PublicEventController extends Controller
{
    public function show(string $subdomain, string $event): Response
    {
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->published()
            ->with(['sessions' => fn ($query) => $query->withCount('registrations'), 'sessions.speakers', 'speakers', 'sponsors'])
            ->firstOrFail();

        return Inertia::render('Public/Events/Show', [
            'event' => $this->toPublicPayload($eventModel),
            'org' => ['name' => $tenant->name],
        ]);
    }

    public function register(Request $request, string $subdomain, string $event): RedirectResponse
    {
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->published()
            ->with('ticketTypes')
            ->firstOrFail();
        $eventModel->setRelation('tenant', $tenant);

        $activeTicketTypes = $eventModel->ticketTypes->where('is_active', true);
        $usesTicketTypes = $activeTicketTypes->isNotEmpty();

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'dietary_requirements' => ['nullable', 'string', 'max:255'],
            'accessibility_needs' => ['nullable', 'string', 'max:255'],
            'ticket_type_id' => $usesTicketTypes
                ? ['required', Rule::in($activeTicketTypes->pluck('id')->all())]
                : ['nullable'],
        ]);

        $ticketType = $usesTicketTypes ? $activeTicketTypes->firstWhere('id', $validated['ticket_type_id']) : null;

        $isFull = $usesTicketTypes
            ? $ticketType->isSoldOut()
            : ($eventModel->capacity !== null && $eventModel->registrations()->confirmed()->count() >= $eventModel->capacity);

        $isFree = $ticketType ? $ticketType->isFree() : $eventModel->isFree();
        $amount = $ticketType ? $ticketType->price : $eventModel->ticket_price;
        $platformFeeAmount = $isFree ? 0 : (int) round($amount * $eventModel->effectivePlatformFeePercentage() / 100);

        $status = match (true) {
            $isFull => EventRegistration::STATUS_WAITLISTED,
            $eventModel->requires_approval => EventRegistration::STATUS_PENDING_APPROVAL,
            $isFree => EventRegistration::STATUS_CONFIRMED,
            default => EventRegistration::STATUS_PENDING_PAYMENT,
        };

        $registration = EventRegistration::create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'dietary_requirements' => $validated['dietary_requirements'] ?? null,
            'accessibility_needs' => $validated['accessibility_needs'] ?? null,
            'tenant_id' => $tenant->id,
            'event_id' => $eventModel->id,
            'ticket_type_id' => $ticketType?->id,
            'status' => $status,
            'waitlist_position' => $status === EventRegistration::STATUS_WAITLISTED
                ? $this->nextWaitlistPosition($eventModel, $ticketType)
                : null,
            'amount' => $isFree ? 0 : $amount,
            'platform_fee_amount' => $platformFeeAmount,
            'currency' => $eventModel->currency,
        ]);

        if ($status === EventRegistration::STATUS_CONFIRMED) {
            $registration->issueTicket();
            $registration->save();
            Mail::to($registration->email)->queue(new EventRegistrationConfirmed($registration));
        } elseif ($status === EventRegistration::STATUS_WAITLISTED) {
            Mail::to($registration->email)->queue(new EventRegistrationWaitlisted($registration));
        } elseif ($status === EventRegistration::STATUS_PENDING_APPROVAL) {
            Mail::to($registration->email)->queue(new EventRegistrationPendingApproval($registration));
        }

        if ($status === EventRegistration::STATUS_PENDING_PAYMENT) {
            return redirect()->route('public.events.checkout', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
                'registration' => $registration->id,
            ]);
        }

        return redirect()->route('public.events.confirmation', [
            'subdomain' => $tenant->slug,
            'event' => $eventModel->slug,
            'registration' => $registration->id,
        ]);
    }

    private function nextWaitlistPosition(Event $event, ?EventTicketType $ticketType): int
    {
        $query = $ticketType
            ? EventRegistration::where('ticket_type_id', $ticketType->id)
            : EventRegistration::where('event_id', $event->id)->whereNull('ticket_type_id');

        return 1 + $query->waitlisted()->count();
    }

    public function confirmation(string $subdomain, string $event, string $registration): Response
    {
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)
            ->with(['sessions' => fn ($query) => $query->withCount('registrations'), 'sessions.speakers'])
            ->firstOrFail();
        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('id', $registration)
            ->with(['ticketType:id,name', 'sessions:id', 'seatAssignment.room:id,name'])
            ->firstOrFail();

        $materials = [];
        if ($registrationModel->isConfirmed()) {
            $materials = $eventModel->materials()
                ->get()
                ->filter(fn (EventMaterial $m): bool => $m->isReleased())
                ->map(fn (EventMaterial $m): array => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'remaining_attempts' => $m->remainingAttemptsFor($registrationModel->id),
                    'download_url' => route('public.events.materials.download', [
                        'subdomain' => $tenant->slug,
                        'event' => $eventModel->slug,
                        'registration' => $registrationModel->id,
                        'material' => $m->id,
                    ]),
                ])
                ->values();
        }

        return Inertia::render('Public/Events/Confirmation', [
            'event' => $this->toPublicPayload($eventModel),
            'registration' => [
                'id' => $registrationModel->id,
                'full_name' => $registrationModel->full_name,
                'email' => $registrationModel->email,
                'phone' => $registrationModel->phone,
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
            ],
            'materials' => $materials,
        ]);
    }

    /**
     * Static preview of the attendee-facing "my ticket" portal — not wired
     * to a real registration yet, just a design preview for the host.
     */
    public function attendeePortalPreview(string $subdomain, string $event): Response
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->firstOrFail();

        return Inertia::render('Public/Events/AttendeePortalPreview', [
            'event' => ['name' => $eventModel->name],
        ]);
    }

    /**
     * Static preview of the speaker-facing confirmation/materials portal —
     * not wired to a real speaker yet, just a design preview for the host.
     */
    public function speakerPortalPreview(string $subdomain, string $event): Response
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->firstOrFail();

        return Inertia::render('Public/Events/SpeakerPortalPreview', [
            'event' => ['name' => $eventModel->name],
        ]);
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPublicPayload(Event $event): array
    {
        return [
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
            'is_free' => $event->isFree(),
            'hero_image_url' => $event->hero_image_path ? asset('storage/'.$event->hero_image_path) : null,
            'plan_your_visit_content' => $event->plan_your_visit_content,
            'ticket_types' => $event->ticketTypes()->active()->get()->map(fn (EventTicketType $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'price' => $t->price,
                'is_free' => $t->isFree(),
                'is_sold_out' => $t->isSoldOut(),
            ])->values(),
            'sessions' => $event->relationLoaded('sessions') ? $event->sessions->map(fn ($s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'description' => $s->description,
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'location' => $s->location,
                'track' => $s->track,
                'type' => $s->type,
                'capacity' => $s->capacity,
                'signup_count' => $s->registrations_count ?? $s->signupCount(),
                'speaker_names' => $s->speakers->pluck('name')->values(),
            ])->values() : [],
            'speakers' => $event->relationLoaded('speakers') ? $event->speakers->map(fn ($s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'title' => $s->title,
                'organization' => $s->organization,
                'bio' => $s->bio,
                'photo_url' => $s->photo_path ? asset('storage/'.$s->photo_path) : null,
            ])->values() : [],
            'sponsors' => $event->relationLoaded('sponsors') ? $event->sponsors->map(fn ($s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'tier' => $s->tier,
                'logo_url' => $s->logo_path ? asset('storage/'.$s->logo_path) : null,
            ])->values() : [],
        ];
    }
}
