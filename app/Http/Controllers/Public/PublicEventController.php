<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationPaymentInvite;
use App\Mail\Events\EventRegistrationPendingApproval;
use App\Mail\Events\EventRegistrationVerifyEmail;
use App\Mail\Events\EventRegistrationWaitlisted;
use App\Models\Event;
use App\Models\EventFormField;
use App\Models\EventMaterial;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use App\Models\Tenant;
use App\Services\Events\QrCodeGenerator;
use App\Services\Events\RegistrationPricingService;
use App\Services\Tenancy\FeatureMeteringService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            // A private event shows enough to register and nothing more.
            'event' => $this->toPublicPayload($eventModel, revealDetails: ! $eventModel->isPrivate()),
            'org' => ['name' => $tenant->name],
            'isPrivate' => $eventModel->isPrivate(),
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
        $settings = $eventModel->effectiveRegistrationSettings();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'title' => ['required_without:full_name', 'nullable', 'string', 'max:32'],
            'first_name' => ['required_without:full_name', 'nullable', 'string', 'max:255'],
            'last_name' => ['required_without:full_name', 'nullable', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'phone' => [
                $settings['require_phone'] ? 'required' : 'nullable',
                'string',
                'max:32',
            ],
            'dietary_requirements' => [
                $settings['require_dietary'] ? 'required' : 'nullable',
                'string',
                'max:255',
            ],
            'accessibility_needs' => [
                $settings['require_accessibility'] ? 'required' : 'nullable',
                'string',
                'max:255',
            ],
            'ticket_type_id' => $usesTicketTypes
                ? ['required', Rule::in($activeTicketTypes->pluck('id')->all())]
                : ['nullable'],
            'form_answers' => ['nullable', 'array'],
            'promo_code' => ['nullable', 'string', 'max:64'],
            'access_code' => ['nullable', 'string', 'max:64'],
        ]);

        $cleanedFormAnswers = app(RegistrationPricingService::class)->validateAndCleanAnswers(
            $eventModel,
            (array) $request->input('form_answers', [])
        );

        $ticketType = $usesTicketTypes ? $activeTicketTypes->firstWhere('id', $validated['ticket_type_id']) : null;

        if ($ticketType && $ticketType->isInviteOnly()) {
            $providedAccessCode = mb_strtoupper(mb_trim((string) ($validated['access_code'] ?? '')));
            if ($providedAccessCode !== mb_strtoupper(mb_trim((string) $ticketType->access_code))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'access_code' => ['Invalid access code for this ticket type.'],
                ]);
            }
        }

        // The plan's registration ceiling. Checked before the registration row is
        // written: usage only increments on confirmation, so an organizer at the
        // ceiling is turned away rather than accumulating rows they cannot honour.
        $registrationLimit = $tenant->featureLimitValue('event_registrations');
        if ($registrationLimit !== null && ! app(FeatureMeteringService::class)->canUse($tenant, 'event_registrations')) {
            Log::warning('Registration refused: tenant is at its plan registration limit', [
                'tenant_id' => $tenant->id,
                'event_id' => $eventModel->id,
                'limit' => $registrationLimit,
            ]);

            return back()->with(
                'error',
                'Registration for this event is closed. Please contact the organizer.'
            );
        }

        $existingRegistration = EventRegistration::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('email', $validated['email'])
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
            ->first();

        if ($existingRegistration) {
            $this->resendRegistrationLink($tenant, $existingRegistration);

            return back()->with(
                'success',
                'A registration already exists for this email address. We have re-sent the link to that inbox — please check your email.'
            );
        }

        $isFull = $usesTicketTypes
            ? $ticketType->isSoldOut()
            : ($eventModel->capacity !== null && $eventModel->registrations()->confirmed()->count() >= $eventModel->capacity);

        $pricing = app(RegistrationPricingService::class)->calculatePrice(
            $eventModel,
            $ticketType,
            $cleanedFormAnswers
        );
        $amount = $pricing['amount'];

        $promoCodeModel = null;
        $discountAmount = 0;

        if (! empty($validated['promo_code'])) {
            $promoResult = app(\App\Services\Events\PromoCodeService::class)->validateCode(
                $eventModel,
                (string) $validated['promo_code'],
                $ticketType,
                (string) $validated['email'],
                $amount
            );

            if (! $promoResult['valid']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'promo_code' => [$promoResult['error']],
                ]);
            }

            $promoCodeModel = $promoResult['promo_code'];
            $discountAmount = $promoResult['discount_amount'];
            $amount = $promoResult['final_amount'];
        }

        $isFree = $amount === 0;
        $fees = app(\App\Services\Finance\FeeCalculator::class)->for($eventModel, $isFree ? 0 : $amount);
        $platformFeeAmount = $fees->platformFee;

        $status = match (true) {
            $isFull => EventRegistration::STATUS_WAITLISTED,
            $eventModel->requires_approval => EventRegistration::STATUS_PENDING_APPROVAL,
            $isFree => EventRegistration::STATUS_CONFIRMED,
            default => EventRegistration::STATUS_PENDING_PAYMENT,
        };

        $title = $validated['title'] ?? null;
        $firstName = $validated['first_name'] ?? null;
        $lastName = $validated['last_name'] ?? null;

        if ((! $firstName || ! $lastName) && ! empty($validated['full_name'])) {
            $parts = explode(' ', mb_trim((string) $validated['full_name']), 2);
            $firstName = $firstName ?: ($parts[0] ?? '');
            $lastName = $lastName ?: ($parts[1] ?? '');
        }

        $fullName = ! empty($validated['full_name'])
            ? $validated['full_name']
            : mb_trim(($title ? $title.' ' : '')."{$firstName} {$lastName}");

        $registration = EventRegistration::create([
            'title' => $title,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'dietary_requirements' => $validated['dietary_requirements'] ?? null,
            'accessibility_needs' => $validated['accessibility_needs'] ?? null,
            'form_answers' => ! empty($cleanedFormAnswers) ? $cleanedFormAnswers : null,
            'tenant_id' => $tenant->id,
            'event_id' => $eventModel->id,
            'ticket_type_id' => $ticketType?->id,
            'promo_code_id' => $promoCodeModel?->id,
            'amount' => $amount,
            'discount_amount' => $discountAmount,
            'platform_fee_amount' => $platformFeeAmount,
            'currency' => $eventModel->currency,
            'status' => $status,
            'waitlist_position' => $status === EventRegistration::STATUS_WAITLISTED
                ? $this->nextWaitlistPosition($eventModel, $ticketType)
                : null,
        ]);

        if ($promoCodeModel) {
            app(\App\Services\Events\PromoCodeService::class)->recordRedemption($promoCodeModel);
        }

        if ($status === EventRegistration::STATUS_CONFIRMED) {
            app(FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
            app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

            // A free registration costs nothing and proves nothing: anyone can
            // type any address and be handed a valid ticket at it. The ticket is
            // withheld until the address is confirmed. A paid registration needs
            // no such step -- the payment itself was made against this address.
            Mail::to($registration->email)->queue(new EventRegistrationVerifyEmail($registration));
        } elseif ($status === EventRegistration::STATUS_WAITLISTED) {
            app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
            Mail::to($registration->email)->queue(new EventRegistrationWaitlisted($registration));
        } elseif ($status === EventRegistration::STATUS_PENDING_APPROVAL) {
            app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
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

    /**
     * Confirms the address a free registration was made with, and only then
     * issues the ticket. Reached from a signed link, so the URL cannot be forged
     * and expires without anything needing to be stored or cleaned up.
     */
    public function verify(string $subdomain, string $event, string $registration): RedirectResponse
    {
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('id', $registration)
            ->firstOrFail();

        $portal = [
            'subdomain' => $tenant->slug,
            'event' => $eventModel->slug,
            'registration' => $registrationModel->id,
        ];

        // Clicking twice -- a mail client prefetching, a forwarded link -- must
        // not issue a second ticket or send a second email.
        if ($registrationModel->hasVerifiedEmail()) {
            return redirect()->route('public.events.confirmation', $portal);
        }

        $registrationModel->email_verified_at = now();

        if ($registrationModel->isConfirmed() && ! $registrationModel->ticket_code) {
            $registrationModel->issueTicket();
        }

        $registrationModel->save();

        if ($registrationModel->isConfirmed()) {
            app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
            Mail::to($registrationModel->email)->queue(new EventRegistrationConfirmed($registrationModel));
        }

        return redirect()->route('public.events.confirmation', $portal)
            ->with('success', 'Email confirmed. Your ticket is on its way.');
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
            'event' => $this->toPublicPayload($eventModel, revealDetails: $registrationModel->isConfirmed()),
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
                'checked_in' => $registrationModel->checked_in_at !== null,
                'email_verified' => $registrationModel->hasVerifiedEmail(),
            ],
            'materials' => $materials,
            // Asking for water three weeks early reaches nobody: a service
            // request is only answerable while there are staff in the room. The
            // window opens when the event does, or as soon as the attendee is
            // scanned in -- whichever happens first, since people arrive before
            // the published start.
            'canRequestHelp' => $registrationModel->checked_in_at !== null
                || ($eventModel->starts_at?->isPast() && $eventModel->ends_at?->isFuture()),
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

    public function validatePromo(Request $request, string $subdomain, string $event): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        $validated = $request->validate([
            'code' => ['required', 'string'],
            'ticket_type_id' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $ticketType = ! empty($validated['ticket_type_id'])
            ? $eventModel->ticketTypes()->where('id', $validated['ticket_type_id'])->first()
            : null;

        $amount = (int) ($validated['amount'] ?? ($ticketType ? $ticketType->price : $eventModel->ticket_price));

        $result = app(\App\Services\Events\PromoCodeService::class)->validateCode(
            $eventModel,
            $validated['code'],
            $ticketType,
            (string) ($validated['email'] ?? ''),
            $amount
        );

        if (! $result['valid']) {
            return response()->json([
                'valid' => false,
                'message' => $result['error'],
            ], 422);
        }

        $promo = $result['promo_code'];

        return response()->json([
            'valid' => true,
            'code' => $promo->code,
            'discount_type' => $promo->discount_type,
            'discount_value' => $promo->discount_value,
            'discount_amount' => $result['discount_amount'],
            'final_amount' => $result['final_amount'],
            'message' => $promo->discount_type === \App\Models\EventPromoCode::TYPE_COMPLIMENTARY
                ? 'Complimentary pass applied!'
                : 'Promo code applied successfully!',
        ]);
    }

    public function unlockTicketTypes(Request $request, string $subdomain, string $event): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        $validated = $request->validate(['access_code' => ['required', 'string']]);
        $code = mb_strtoupper(mb_trim($validated['access_code']));

        $unlocked = $eventModel->ticketTypes()->active()
            ->whereNotNull('access_code')
            ->whereRaw('UPPER(access_code) = ?', [$code])
            ->get()
            ->map(fn (EventTicketType $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'price' => $t->price,
                'is_free' => $t->isFree(),
                'is_sold_out' => $t->isSoldOut(),
                'is_invite_only' => true,
                'access_code' => $code,
            ]);

        if ($unlocked->isEmpty()) {
            return response()->json(['message' => 'No ticket types match this access code.'], 404);
        }

        return response()->json([
            'message' => 'Access code accepted.',
            'ticket_types' => $unlocked,
        ]);
    }

    /**
     * Re-sends the status-appropriate registration email to an address that
     * already holds a live registration for this event. The link is delivered
     * to the inbox that owns it, never through the HTTP response, so merely
     * knowing someone's email address cannot expose their ticket QR or PII.
     */
    private function resendRegistrationLink(Tenant $tenant, EventRegistration $registration): void
    {
        // Someone registering a second time has usually lost the first email.
        // If they never confirmed their address there is no ticket to resend --
        // sending "here is your ticket" with nothing in it would be worse than
        // useless, so they get the verification link again.
        if ($registration->isConfirmed() && ! $registration->hasVerifiedEmail() && ! $registration->ticket_code) {
            app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
            Mail::to($registration->email)->queue(new EventRegistrationVerifyEmail($registration));

            return;
        }

        $mailable = match ($registration->status) {
            EventRegistration::STATUS_PENDING_PAYMENT => new EventRegistrationPaymentInvite($registration, 'approved'),
            EventRegistration::STATUS_PENDING_APPROVAL => new EventRegistrationPendingApproval($registration),
            EventRegistration::STATUS_WAITLISTED => new EventRegistrationWaitlisted($registration),
            EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN => new EventRegistrationConfirmed($registration),
            default => null,
        };

        if (! $mailable) {
            return;
        }

        app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
        Mail::to($registration->email)->queue($mailable);
    }

    private function nextWaitlistPosition(Event $event, ?EventTicketType $ticketType): int
    {
        $query = $ticketType
            ? EventRegistration::where('ticket_type_id', $ticketType->id)
            : EventRegistration::where('event_id', $event->id)->whereNull('ticket_type_id');

        return 1 + $query->waitlisted()->count();
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
    /**
     * @param  bool  $revealDetails  Whether the viewer has earned the full page.
     *                               A private event's lineup, agenda and sponsors
     *                               are withheld until they hold a confirmed
     *                               registration.
     */
    private function toPublicPayload(Event $event, bool $revealDetails = true): array
    {
        $withhold = $event->isPrivate() && ! $revealDetails;

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
            'hero_image_url' => Helper::storageUrl($event->hero_image_path),
            'plan_your_visit_content' => $event->plan_your_visit_content,
            'registration_settings' => $event->effectiveRegistrationSettings(),
            'form_fields' => $event->formFields()->ordered()->get()->map(fn (EventFormField $f): array => [
                'id' => $f->id,
                'label' => $f->label,
                'field_key' => $f->field_key,
                'field_type' => $f->field_type,
                'help_text' => $f->help_text,
                'is_required' => (bool) $f->is_required,
                'options' => $f->options,
                'conditional_logic' => $f->conditional_logic,
            ])->values(),
            'ticket_types' => $event->ticketTypes()->active()->get()->map(fn (EventTicketType $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'price' => $t->price,
                'is_free' => $t->isFree(),
                'is_sold_out' => $t->isSoldOut(),
                'is_invite_only' => $t->isInviteOnly(),
            ])->values(),
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
                'signup_count' => $s->registrations_count ?? $s->signupCount(),
                'speaker_names' => $s->speakers->pluck('name')->values(),
            ])->values() : [],
            'speakers' => ! $withhold && $event->relationLoaded('speakers') ? $event->speakers->map(fn ($s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'title' => $s->title,
                'organization' => $s->organization,
                'bio' => $s->bio,
                'photo_url' => Helper::storageUrl($s->photo_path),
            ])->values() : [],
            'sponsors' => ! $withhold && $event->relationLoaded('sponsors') ? $event->sponsors->map(fn ($s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'tier' => $s->tier,
                'logo_url' => Helper::storageUrl($s->logo_path),
            ])->values() : [],
        ];
    }
}
