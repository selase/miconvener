<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Events\EventSections;
use App\Services\Events\EventWorkspaceSnapshot;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class EventController extends Controller
{
    /**
     * The relations each workspace section reads from the event payload.
     * Sections not listed load their own data from their JSON endpoints.
     *
     * @var array<string, list<string>>
     */
    private const array SECTION_RELATIONS = [
        'tickets' => ['ticketTypes'],
        'schedule' => ['sessions', 'sessions.speakers', 'speakers'],
        'speakers' => ['speakers'],
        'check-in' => ['sessions', 'sessions.speakers'],
        'materials' => ['materials'],
        'venue' => ['venueRooms.seatAssignments.registration:id,full_name'],
    ];

    public function index(string $subdomain): Response
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();

        $allEvents = Event::where('tenant_id', $tenant->id)
            ->withCount(['registrations as registrations_count' => fn ($query) => $query->confirmed()])
            ->orderByDesc('starts_at')
            ->get();

        $events = $allEvents->map(fn (Event $event): array => $this->toPayload($event));

        $now = now();
        $stats = [
            'events_this_year' => $allEvents->filter(fn (Event $e): bool => $e->starts_at->year === $now->year)->count(),
            'running_now' => $allEvents->filter(fn (Event $e): bool => $e->status === Event::STATUS_PUBLISHED && $e->starts_at->lte($now) && $e->ends_at->gte($now))->count(),
            'total_registered' => (int) EventRegistration::where('tenant_id', $tenant->id)->confirmed()->count(),
            'collected_this_year' => (int) EventRegistration::where('tenant_id', $tenant->id)
                ->confirmed()
                ->whereYear('created_at', $now->year)
                ->sum('amount'),
        ];

        return Inertia::render('Tenant/Events/Index', [
            'events' => $events,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request, string $subdomain): RedirectResponse|JsonResponse
    {
        $this->authorize('create event');
        $tenant = $this->getTenant();

        $limit = $tenant->featureLimitValue('events_in_flight');
        if ($limit !== null) {
            $currentCount = Event::where('tenant_id', $tenant->id)
                ->where('status', '!=', Event::STATUS_CANCELLED)
                ->where('ends_at', '>=', now())
                ->count();

            if ($currentCount >= $limit) {
                $message = "Your plan allows {$limit} concurrent event(s). Cancel an existing upcoming event, or upgrade your plan, to create another.";

                if ($request->wantsJson()) {
                    return response()->json(['message' => $message], 422);
                }

                return redirect()->back()->withErrors(['name' => $message]);
            }
        }

        $validated = $this->validateEvent($request);

        if (($validated['ticket_price'] ?? 0) > 0 && ! $tenant->planAllows('paid_tickets')) {
            $message = 'Your plan runs free events only. Set the ticket price to 0, or upgrade to sell tickets.';

            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return redirect()->back()->withErrors(['ticket_price' => $message]);
        }

        if ($request->hasFile('hero_image')) {
            $validated['hero_image_path'] = Helper::processUploadedFile($request, 'hero_image', 'event_hero', 'events/hero', config('app.env') === 'production' ? 's3' : 'public');
        }

        $event = Event::create([
            ...$validated,
            'tenant_id' => $tenant->id,
            'created_by' => $request->user()->id,
            'slug' => $this->uniqueSlug($validated['name'], $tenant->id),
        ]);

        if ($request->wantsJson()) {
            return response()->json($this->toPayload($event));
        }

        return redirect()->route('tenant.events.index', ['subdomain' => $tenant->slug])->with('success', 'Event created successfully.');
    }

    public function update(Request $request, string $subdomain, string $event): RedirectResponse|JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->with('ticketTypes')->firstOrFail();

        $validated = $this->validateEvent($request);

        // A grandfathered event was already selling when the plan changed, so
        // the organizer can keep editing it; a smaller plan only stops new paid events.
        $mayCharge = $tenant->planAllows('paid_tickets') || ($eventModel->isGrandfathered() && $eventModel->isPaid());

        if (($validated['ticket_price'] ?? 0) > 0 && ! $mayCharge) {
            $message = 'Your plan runs free events only. Set the ticket price to 0, or upgrade to sell tickets.';

            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return redirect()->back()->withErrors(['ticket_price' => $message]);
        }

        $wouldBePaid = ($validated['ticket_price'] ?? 0) > 0
            || $eventModel->ticketTypes->where('is_active', true)->where('price', '>', 0)->isNotEmpty();

        if ($wouldBePaid && $validated['status'] === Event::STATUS_PUBLISHED && ! $mayCharge) {
            $message = 'Your plan runs free events only. Make its ticket types free, or upgrade, to publish it.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return redirect()->back()->with('error', $message);
        }

        if ($wouldBePaid && $validated['status'] === Event::STATUS_PUBLISHED && ! $tenant->canAcceptPayments()) {
            $message = 'Connect a payment gateway under Settings → Payments before publishing a paid event.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return redirect()->back()->with('error', $message);
        }

        if ($request->hasFile('hero_image')) {
            $previousHeroImage = $eventModel->hero_image_path;

            $validated['hero_image_path'] = Helper::processUploadedFile($request, 'hero_image', 'event_hero', 'events/hero', Event::uploadDisk());

            // Replacing the image used to leave the old one on the disk forever,
            // so an organizer iterating on artwork quietly accumulated files
            // nothing referenced.
            if ($previousHeroImage) {
                Helper::deleteFile($previousHeroImage, Event::uploadDisk());
            }
        }

        $eventModel->update($validated);

        if ($request->wantsJson()) {
            return response()->json($this->toPayload($eventModel));
        }

        return redirect()->back()->with('success', 'Event updated successfully.');
    }

    /**
     * Visibility on its own route rather than through the full event form: a
     * host flipping this is making a confidentiality decision, and it should
     * not require re-submitting every other field to take effect.
     */
    public function updateVisibility(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $validated = $request->validate([
            'visibility' => ['required', Rule::in(Event::VISIBILITIES)],
        ]);

        $eventModel->update(['visibility' => $validated['visibility']]);

        return response()->json([
            'visibility' => $eventModel->visibility,
            'message' => $eventModel->isPrivate()
                ? 'This event is private. Speakers, the agenda and sponsors are hidden until someone has a confirmed registration.'
                : 'This event is public. Anyone with the link sees the full page.',
        ]);
    }

    public function destroy(string $subdomain, string $event): JsonResponse|RedirectResponse
    {
        $this->authorize('delete event');
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();
        $eventModel->delete();

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Event deleted successfully.']);
        }

        return redirect()->route('tenant.events.index', ['subdomain' => $tenant->slug])->with('success', 'Event deleted successfully.');
    }

    /**
     * An event's workspace, opened on its overview.
     */
    public function show(string $subdomain, string $event): Response|RedirectResponse
    {
        // Links written before sections had their own addresses.
        $legacy = request()->query('section');
        if (is_string($legacy) && $legacy !== EventSections::OVERVIEW && EventSections::exists($legacy)) {
            return redirect()->route('tenant.events.section', ['event' => $event, 'section' => $legacy]);
        }

        return $this->workspace($event, EventSections::OVERVIEW);
    }

    /**
     * One section of an event's workspace, at its own address.
     */
    public function section(string $subdomain, string $event, string $section): Response
    {
        return $this->workspace($event, $section);
    }

    public function exportGuests(string $subdomain, string $event): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $filename = "{$eventModel->slug}-guests.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Name', 'Email', 'Phone', 'Status', 'Ticket Code', 'Amount', 'Currency', 'Checked In At', 'Registered At']);

            $eventModel->registrations()->orderByDesc('created_at')->chunk(200, function ($registrations) use ($handle): void {
                foreach ($registrations as $registration) {
                    fputcsv($handle, [
                        $registration->full_name,
                        $registration->email,
                        $registration->phone,
                        $registration->status,
                        $registration->ticket_code,
                        $registration->amount,
                        $registration->currency,
                        $registration->checked_in_at?->toIso8601String(),
                        $registration->created_at->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Check-in with nothing else on screen, for whoever is on the door.
     */
    public function door(string $subdomain, string $event): Response
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)
            ->with(['sessions' => fn ($query) => $query->withCount('registrations'), 'sessions.speakers'])
            ->firstOrFail();

        // Same gate as the check-in section: every scan endpoint needs it.
        abort_unless(app(EventSections::class)->allows(request()->user(), $tenant, $eventModel, 'check-in'), 403);

        return Inertia::render('Tenant/Events/CheckInDoor', [
            'event' => $this->toPayload($eventModel),
            'counts' => [
                'checked_in' => $eventModel->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN)->count(),
                'expected' => $eventModel->registrations()
                    ->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])
                    ->count(),
            ],
        ]);
    }

    protected function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }

    /**
     * Renders the workspace for one section, loading only what that section
     * shows. The page used to load every registration -- 587 of them on a real
     * event -- whichever tab was open, including to show the schedule.
     */
    private function workspace(string $event, string $section): Response
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();

        $relations = [];
        foreach (self::SECTION_RELATIONS[$section] ?? [] as $relation) {
            if ($relation === 'sessions') {
                // Sessions carry their sign-up count, which the payload reads.
                $relations['sessions'] = fn ($query) => $query->withCount('registrations');
            } else {
                $relations[] = $relation;
            }
        }

        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)
            // The fee cascade walks event -> tenant -> package, and the payload
            // reads it three times.
            ->with(['tenant.package', ...$relations])
            ->firstOrFail();

        $sections = app(EventSections::class);
        abort_unless($sections->allows(request()->user(), $tenant, $eventModel, $section), 403);

        $snapshot = app(EventWorkspaceSnapshot::class);
        $phase = $snapshot->phase($eventModel);
        $hasActiveGateway = $tenant->canAcceptPayments();

        return Inertia::render('Tenant/Events/Show', [
            'event' => [
                ...$this->toPayload($eventModel),
                'platform_fee_percentage' => $eventModel->platform_fee_percentage,
                'effective_platform_fee_percentage' => $eventModel->effectivePlatformFeePercentage(),
                'effective_platform_fee_cap_amount' => $eventModel->effectivePlatformFeeCapAmount(),
                'effective_fee_bearer' => $eventModel->effectiveFeeBearer(),
                /**
                 * What one ticket at the event's own price actually splits
                 * into, so the organizer sees their net rather than inferring
                 * it from a percentage.
                 */
                'fee_preview' => app(\App\Services\Finance\FeeCalculator::class)
                    ->for($eventModel, (int) $eventModel->ticket_price)
                    ->toArray(),
            ],
            'registrations' => $section === 'guests' ? $this->registrationRows($eventModel) : [],
            'stats' => $section === EventSections::OVERVIEW ? $this->overviewStats($eventModel) : null,
            'hasActiveGateway' => $hasActiveGateway,
            'settlementMode' => $tenant->settlement_mode,
            'publicUrl' => route('public.events.show', ['subdomain' => $tenant->slug, 'event' => $eventModel->slug]),
            'sections' => $sections->menuFor(request()->user(), $tenant, $eventModel),
            'section' => $section,
            'phase' => $phase,
            'badges' => $snapshot->badges(request()->user(), $tenant, $eventModel, $phase),
            'overview' => $section === EventSections::OVERVIEW
                ? $snapshot->overview(request()->user(), $tenant, $eventModel, $phase, $hasActiveGateway)
                : null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registrationRows(Event $event): array
    {
        return $event->registrations()
            ->with(['ticketType:id,name', 'seatAssignment.room:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EventRegistration $registration): array => [
                'id' => $registration->id,
                'full_name' => $registration->full_name,
                'email' => $registration->email,
                'phone' => $registration->phone,
                'status' => $registration->status,
                'waitlist_position' => $registration->waitlist_position,
                'approval_note' => $registration->approval_note,
                'ticket_code' => $registration->ticket_code,
                'ticket_type_name' => $registration->ticketType?->name,
                'amount' => $registration->amount,
                'platform_fee_amount' => $registration->platform_fee_amount,
                'currency' => $registration->currency,
                'seat_label' => $registration->seatAssignment?->seat_label,
                'room_name' => $registration->seatAssignment?->room?->name,
                'checked_in_at' => $registration->checked_in_at?->toIso8601String(),
                'created_at' => $registration->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * The overview's figures, counted in the database rather than by loading
     * every registration to add them up in the browser.
     *
     * @return array{confirmed: int, collected: int, platform_fees: int}
     */
    private function overviewStats(Event $event): array
    {
        $totals = $event->registrations()
            ->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])
            ->selectRaw('count(*) as confirmed, coalesce(sum(amount), 0) as collected, coalesce(sum(platform_fee_amount), 0) as platform_fees')
            ->toBase()
            ->first();

        return [
            'confirmed' => (int) ($totals->confirmed ?? 0),
            'collected' => (int) ($totals->collected ?? 0),
            'platform_fees' => (int) ($totals->platform_fees ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEvent(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in([Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED])],
            'visibility' => ['sometimes', Rule::in(Event::VISIBILITIES)],
            'fee_bearer' => ['nullable', Rule::in([
                \App\Services\Finance\PlatformFeeResolver::BEARER_ORGANIZER,
                \App\Services\Finance\PlatformFeeResolver::BEARER_ATTENDEE,
            ])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['required', 'string', 'max:64'],
            'location_type' => ['required', Rule::in([Event::LOCATION_IN_PERSON, Event::LOCATION_VIRTUAL])],
            'address' => ['nullable', 'required_if:location_type,in_person', 'string', 'max:255'],
            'virtual_link' => ['nullable', 'required_if:location_type,virtual', 'url', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'requires_approval' => ['sometimes', 'boolean'],
            'ticket_price' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'plan_your_visit_content' => ['nullable', 'string'],
            'hero_image' => ['nullable', 'image', 'max:20480'],
        ], [
            'hero_image.max' => 'The hero image must not be greater than 20MB.',
            'hero_image.image' => 'The hero image must be a valid image file (JPG, PNG, WebP, GIF, or SVG).',
            'ends_at.after' => 'The event end date and time must be after the start date and time.',
            'address.required_if' => 'The address is required for in-person events.',
            'virtual_link.required_if' => 'The virtual meeting link is required for virtual events.',
        ]);

        // platform_fee_percentage and platform_fee_cap_amount are deliberately
        // NOT settable here — they are the platform's own revenue levers, not
        // something a host can self-discount. Application-superadmin only, via
        // events:set-platform-fee, which has no web surface at all.
        //
        // fee_bearer is different: who absorbs the commission is the
        // organizer's own pricing decision, so it belongs to them.

        unset($validated['hero_image']);

        return $validated;
    }

    private function uniqueSlug(string $name, string $tenantId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Event::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'description' => $event->description,
            'status' => $event->status,
            'visibility' => $event->visibility,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'timezone' => $event->timezone,
            'location_type' => $event->location_type,
            'address' => $event->address,
            'virtual_link' => $event->virtual_link,
            'capacity' => $event->capacity,
            'requires_approval' => $event->requires_approval,
            'ticket_price' => $event->ticket_price,
            'currency' => $event->currency,
            'fee_bearer' => $event->fee_bearer,
            'hero_image_url' => Helper::storageUrl($event->hero_image_path),
            'plan_your_visit_content' => $event->plan_your_visit_content,
            'is_free' => $event->isFree(),
            'registrations_count' => $event->registrations_count ?? $event->registrations()->confirmed()->count(),
            'ticket_types' => $event->relationLoaded('ticketTypes') ? $event->ticketTypes->map(fn ($t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'badge_tier' => $t->badge_tier,
                'price' => $t->price,
                'capacity' => $t->capacity,
                'is_active' => $t->is_active,
                'confirmed_count' => $t->confirmedCount(),
                'is_sold_out' => $t->isSoldOut(),
            ])->values() : [],
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
                'signup_count' => $s->registrations_count,
                'speaker_ids' => $s->speakers->pluck('id')->values(),
                'speaker_names' => $s->speakers->pluck('name')->values(),
            ])->values() : [],
            'speakers' => $event->relationLoaded('speakers') ? $event->speakers->map(fn ($s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'title' => $s->title,
                'organization' => $s->organization,
                'bio' => $s->bio,
                'photo_url' => Helper::storageUrl($s->photo_path),
                'role' => $s->pivot->role,
            ])->values() : [],
            'materials' => $event->relationLoaded('materials') ? $event->materials->map(fn ($m): array => [
                'id' => $m->id,
                'title' => $m->title,
                'session_id' => $m->session_id,
                'file_size' => $m->file_size,
                'download_limit' => $m->download_limit,
                'release_at' => $m->release_at?->toIso8601String(),
                'is_released' => $m->isReleased(),
                'downloads_count' => $m->downloads()->count(),
            ])->values() : [],
            'venue_rooms' => $event->relationLoaded('venueRooms') ? $event->venueRooms->map(fn ($room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'rows' => $room->rows,
                'seats_per_row' => $room->seats_per_row,
                'seat_labels' => $room->seatLabels(),
                'assignments' => $room->seatAssignments->map(fn ($a): array => [
                    'id' => $a->id,
                    'seat_label' => $a->seat_label,
                    'registration_id' => $a->registration_id,
                    'registration_name' => $a->registration?->full_name,
                ])->values(),
            ])->values() : [],
        ];
    }
}
