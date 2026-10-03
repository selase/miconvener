<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\EventSections;
use App\Services\Events\EventWorkspaceSnapshot;
use App\Services\Events\RecurrenceService;
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
        'contributions' => ['contributions'],
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

        $recentlyDeletedEvents = Event::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('purge_at', '>', $now)
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'name' => $event->name,
                'slug' => $event->slug,
                'status' => $event->status,
                'deleted_at' => $event->deleted_at?->toIso8601String(),
                'purge_at' => $event->purge_at?->toIso8601String(),
                'remaining_seconds' => $event->recoveryWindowRemainingSeconds(),
                'remaining_human' => $event->recoveryWindowRemainingHuman(),
            ]);

        return Inertia::render('Tenant/Events/Index', [
            'events' => $events,
            'stats' => $stats,
            'recentlyDeletedEvents' => $recentlyDeletedEvents,
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

        if (! empty($validated['store_listing_id'])) {
            $listing = \App\Models\StoreListing::with('shop')->find($validated['store_listing_id']);
            if ($listing && empty($validated['address']) && $listing->shop) {
                $validated['address'] = "{$listing->shop->address}, {$listing->shop->city}";
            }
            if ($listing && empty($validated['capacity'])) {
                $capacities = $listing->capacity_breakdown ?? [];
                $validated['capacity'] = $capacities['banquet'] ?? $capacities['theater'] ?? 100;
            }
        }

        $category = $validated['event_category'] ?? Event::CATEGORY_GENERAL;
        $lexicon = \App\Services\Events\EventLexicon::forCategory($category);

        $event = Event::create([
            ...$validated,
            'event_category' => $category,
            'contribution_title' => $validated['contribution_title'] ?? $lexicon['contributions_title'],
            'tenant_id' => $tenant->id,
            'created_by' => $request->user()->id,
            'slug' => $this->uniqueSlug($validated['name'], $tenant->id),
        ]);

        if ($event->isRecurring()) {
            app(RecurrenceService::class)->generateOccurrences($event);
        }

        if ($request->wantsJson()) {
            return response()->json($this->toPayload($event));
        }

        return redirect()->route('tenant.events.index', ['subdomain' => $tenant->slug])->with('success', 'Event created successfully.');
    }

    public function update(Request $request, string $subdomain, string $event): RedirectResponse|JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();

        $eventModel = $this->resolveEvent($tenant, $event, ['ticketTypes']);

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

        if (! empty($validated['store_listing_id'])) {
            $listing = \App\Models\StoreListing::with('shop')->find($validated['store_listing_id']);
            if ($listing && empty($validated['address']) && $listing->shop) {
                $validated['address'] = "{$listing->shop->address}, {$listing->shop->city}";
            }
            if ($listing && empty($validated['capacity'])) {
                $capacities = $listing->capacity_breakdown ?? [];
                $validated['capacity'] = $capacities['banquet'] ?? $capacities['theater'] ?? 100;
            }
        }

        if (isset($validated['event_category']) && $validated['event_category'] !== $eventModel->event_category) {
            $oldLexicon = \App\Services\Events\EventLexicon::forCategory($eventModel->event_category);
            $newLexicon = \App\Services\Events\EventLexicon::forCategory($validated['event_category']);
            if (empty($validated['contribution_title']) || $eventModel->contribution_title === $oldLexicon['contributions_title']) {
                $validated['contribution_title'] = $newLexicon['contributions_title'];
            }
        }

        $eventModel->update($validated);

        if ($eventModel->isRecurring()) {
            app(RecurrenceService::class)->generateOccurrences($eventModel);
        }

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

        $eventModel = $this->resolveEvent($tenant, $event);

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

    public function destroy(Request $request, string $subdomain, string $event): JsonResponse|RedirectResponse
    {
        $this->authorize('delete event');
        $tenant = $this->getTenant();

        $eventModel = $this->resolveEvent($tenant, $event);

        $validated = $request->validate([
            'confirm_name' => ['required', 'string'],
        ], [
            'confirm_name.required' => 'Please type the event title to confirm deletion.',
        ]);

        if (mb_trim(mb_strtolower($validated['confirm_name'])) !== mb_trim(mb_strtolower($eventModel->name))) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'The entered title does not match the event title.',
                    'errors' => ['confirm_name' => ['The entered title does not match the event title.']],
                ], 422);
            }

            return redirect()->back()->withErrors(['confirm_name' => 'The entered title does not match the event title.']);
        }

        $blockReason = null;
        if (! $eventModel->canBeDeleted($blockReason)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $blockReason,
                    'errors' => ['event' => [$blockReason]],
                ], 422);
            }

            return redirect()->back()->withErrors(['event' => $blockReason]);
        }

        $eventModel->update(['purge_at' => now()->addHours(6)]);
        $eventModel->delete();

        $message = "Event \"{$eventModel->name}\" was moved to recovery. It can be restored within 6 hours before permanent deletion.";

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'recovery_window_human' => '6h 0m left to restore',
            ]);
        }

        return redirect()->route('tenant.events.index', ['subdomain' => $tenant->slug])->with('success', $message);
    }

    public function restore(Request $request, string $subdomain, string $event): JsonResponse|RedirectResponse
    {
        $this->authorize('delete event');
        $tenant = $this->getTenant();

        $query = Event::onlyTrashed()->where('tenant_id', $tenant->id);
        if (Str::isUuid($event)) {
            $query->where('id', $event);
        } else {
            $query->where('slug', $event);
        }
        $eventModel = $query->firstOrFail();

        $limit = $tenant->featureLimitValue('events_in_flight');
        if ($limit !== null && $eventModel->ends_at->gte(now()) && $eventModel->status !== Event::STATUS_CANCELLED) {
            $currentCount = Event::where('tenant_id', $tenant->id)
                ->where('status', '!=', Event::STATUS_CANCELLED)
                ->where('ends_at', '>=', now())
                ->count();

            if ($currentCount >= $limit) {
                $message = "Your plan allows {$limit} concurrent event(s). Upgrade your plan or cancel an existing upcoming event to restore this one.";

                if ($request->wantsJson()) {
                    return response()->json(['message' => $message], 422);
                }

                return redirect()->back()->withErrors(['event' => $message]);
            }
        }

        $eventModel->restore();
        $eventModel->update(['purge_at' => null]);

        $message = "Event \"{$eventModel->name}\" was successfully restored.";

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'event' => $this->toPayload($eventModel),
            ]);
        }

        return redirect()->route('tenant.events.index', ['subdomain' => $tenant->slug])->with('success', $message);
    }

    public function forcePurge(Request $request, string $subdomain, string $event): JsonResponse|RedirectResponse
    {
        $this->authorize('delete event');
        $tenant = $this->getTenant();

        $query = Event::onlyTrashed()->where('tenant_id', $tenant->id);
        if (Str::isUuid($event)) {
            $query->where('id', $event);
        } else {
            $query->where('slug', $event);
        }
        $eventModel = $query->firstOrFail();

        $validated = $request->validate([
            'confirm_name' => ['required', 'string'],
        ], [
            'confirm_name.required' => 'Please type the event title to confirm permanent deletion.',
        ]);

        if (mb_trim(mb_strtolower($validated['confirm_name'])) !== mb_trim(mb_strtolower($eventModel->name))) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'The entered title does not match the event title.',
                    'errors' => ['confirm_name' => ['The entered title does not match the event title.']],
                ], 422);
            }

            return redirect()->back()->withErrors(['confirm_name' => 'The entered title does not match the event title.']);
        }

        $blockReason = null;
        if (! $eventModel->canBeDeleted($blockReason)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $blockReason,
                    'errors' => ['event' => [$blockReason]],
                ], 422);
            }

            return redirect()->back()->withErrors(['event' => $blockReason]);
        }

        $eventName = $eventModel->name;
        $eventModel->forceDelete();

        $message = "Event \"{$eventName}\" was permanently deleted.";

        if ($request->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('tenant.events.index', ['subdomain' => $tenant->slug])->with('success', $message);
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

        $eventModel = $this->resolveEvent($tenant, $event);

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

    public function exportContributions(string $subdomain, string $event): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->resolveEvent($tenant, $event);

        $filename = "{$eventModel->slug}-contributions.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Reference', 'Contributor Name', 'Email', 'Phone', 'Amount (Pesewas)', 'Amount (GHS)', 'Net (GHS)', 'Currency', 'Status', 'Is Anonymous', 'Tribute Message', 'Paid At', 'Created At']);

            $sanitizeCsv = fn (?string $v): ?string => ($v !== null && in_array($v[0] ?? '', ['=', '+', '-', '@'], true)) ? "'".$v : $v;

            $eventModel->contributions()->orderByDesc('created_at')->chunk(200, function ($contributions) use ($handle, $sanitizeCsv): void {
                foreach ($contributions as $c) {
                    fputcsv($handle, [
                        $c->payment_reference,
                        $sanitizeCsv($c->contributor_name),
                        $c->contributor_email,
                        $c->contributor_phone,
                        $c->amount,
                        number_format($c->amount / 100, 2),
                        number_format($c->net_amount / 100, 2),
                        $c->currency,
                        $c->status,
                        $c->is_anonymous ? 'Yes' : 'No',
                        $sanitizeCsv($c->tribute_message),
                        $c->paid_at?->toIso8601String(),
                        $c->created_at->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function updateContributionSettings(Request $request, string $subdomain, string $event): RedirectResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->resolveEvent($tenant, $event);

        $validated = $request->validate([
            'allow_contributions' => ['required', 'boolean'],
            'contribution_title' => ['nullable', 'string', 'max:255'],
            'contribution_description' => ['nullable', 'string', 'max:1000'],
            'contribution_presets' => ['nullable', 'array'],
            'contribution_presets.*' => ['integer', 'min:100'],
            'contribution_min_amount_pesewas' => ['nullable', 'integer', 'min:100'],
            'contribution_goal_amount_pesewas' => ['nullable', 'integer', 'min:100'],
            'show_tribute_wall' => ['required', 'boolean'],
            'show_contributor_amounts' => ['required', 'boolean'],
        ]);

        $eventModel->update($validated);

        return redirect()->back()->with('success', 'Contribution settings updated successfully.');
    }

    public function toggleContributionApproval(string $subdomain, string $event, string $contribution): RedirectResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->resolveEvent($tenant, $event);

        /** @var \App\Models\EventContribution $c */
        $c = $eventModel->contributions()->where('id', $contribution)->firstOrFail();
        $c->update(['is_approved' => ! $c->is_approved]);

        return redirect()->back()->with('success', 'Tribute visibility toggled.');
    }

    /**
     * Check-in with nothing else on screen, for whoever is on the door.
     */
    public function door(string $subdomain, string $event): Response
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();

        $eventModel = $this->resolveEvent($tenant, $event, [
            'sessions' => fn ($query) => $query->withCount(['registrations', 'attendances']),
            'sessions.speakers',
        ]);

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

    protected function getTenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }

    /**
     * Resolve an event by UUID or slug within the tenant scope.
     *
     * @param  array<int|string, mixed>  $relations
     */
    private function resolveEvent(Tenant $tenant, string $event, array $relations = []): Event
    {
        $query = Event::where('tenant_id', $tenant->id)
            ->where(function ($query) use ($event): void {
                if (Str::isUuid($event)) {
                    $query->where('id', $event);
                } else {
                    $query->where('slug', $event);
                }
            });

        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->firstOrFail();
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
                // Sessions carry their sign-up and attendance counts, which the payload reads.
                $relations['sessions'] = fn ($query) => $query->withCount(['registrations', 'attendances']);
            } else {
                $relations[] = $relation;
            }
        }

        $eventModel = $this->resolveEvent($tenant, $event, [
            'tenant.package',
            ...$relations,
        ]);

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
            'contributions' => $section === 'contributions' ? $eventModel->contributions()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (\App\Models\EventContribution $c): array => [
                    'id' => $c->id,
                    'contributor_name' => $c->contributor_name,
                    'contributor_email' => $c->contributor_email,
                    'contributor_phone' => $c->contributor_phone,
                    'amount' => $c->amount,
                    'gateway_fee_amount' => $c->gateway_fee_amount,
                    'platform_fee_amount' => $c->platform_fee_amount,
                    'net_amount' => $c->net_amount,
                    'currency' => $c->currency,
                    'status' => $c->status,
                    'payment_reference' => $c->payment_reference,
                    'paystack_reference' => $c->paystack_reference,
                    'tribute_message' => $c->tribute_message,
                    'is_anonymous' => $c->is_anonymous,
                    'is_approved' => $c->is_approved,
                    'paid_at' => $c->paid_at?->toIso8601String(),
                    'created_at' => $c->created_at->toIso8601String(),
                ])->values() : [],
            'contributionsStats' => $section === 'contributions' ? [
                'total_raised' => (int) $eventModel->contributions()->where('status', \App\Models\EventContribution::STATUS_COMPLETED)->sum('amount'),
                'net_payout' => (int) $eventModel->contributions()->where('status', \App\Models\EventContribution::STATUS_COMPLETED)->sum('net_amount'),
                'contributors_count' => (int) $eventModel->contributions()->where('status', \App\Models\EventContribution::STATUS_COMPLETED)->count(),
                'pending_count' => (int) $eventModel->contributions()->where('status', \App\Models\EventContribution::STATUS_PENDING_PAYMENT)->count(),
            ] : null,
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
                'payment_method' => $registration->payment_method,
                'offline_payment_status' => $registration->offline_payment_status,
                'offline_payment_reference' => $registration->offline_payment_reference,
                'offline_payment_notes' => $registration->offline_payment_notes,
                'offline_payment_submitted_at' => $registration->offline_payment_submitted_at?->toIso8601String(),
                'has_offline_proof' => filled($registration->offline_payment_proof_path),
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
            'event_category' => ['sometimes', 'string', Rule::in(Event::CATEGORIES)],
            'contribution_title' => ['nullable', 'string', 'max:255'],
            'contribution_description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in([Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED])],
            'visibility' => ['sometimes', Rule::in(Event::VISIBILITIES)],
            'fee_bearer' => ['nullable', Rule::in([
                \App\Services\Finance\PlatformFeeResolver::BEARER_ORGANIZER,
                \App\Services\Finance\PlatformFeeResolver::BEARER_ATTENDEE,
            ])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['required', 'string', 'timezone:all', 'max:64'],
            'location_type' => ['required', Rule::in([Event::LOCATION_IN_PERSON, Event::LOCATION_VIRTUAL])],
            'address' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('location_type') === Event::LOCATION_IN_PERSON && ! $request->filled('store_listing_id')),
                'string',
                'max:255',
            ],
            'virtual_link' => ['nullable', 'required_if:location_type,virtual', 'url', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'requires_approval' => ['sometimes', 'boolean'],
            'ticket_price' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'plan_your_visit_content' => ['nullable', 'string'],
            'hero_image' => ['nullable', 'image', 'max:20480'],
            'store_listing_id' => ['nullable', 'uuid', 'exists:landlord.store_listings,id'],
            'venue_booking_id' => ['nullable', 'uuid', 'exists:landlord.venue_bookings,id'],
            'is_recurring' => ['sometimes', 'boolean'],
            'recurrence_pattern' => ['nullable', 'required_if:is_recurring,true', Rule::in(RecurrenceService::PATTERNS)],
            'recurrence_days' => ['nullable', 'array'],
            'recurrence_days.*' => ['string', Rule::in(RecurrenceService::DAYS_OF_WEEK)],
            'recurrence_time_start' => ['nullable', 'date_format:H:i'],
            'recurrence_time_end' => ['nullable', 'date_format:H:i'],
            'recurrence_interval' => ['nullable', 'integer', 'min:1'],
            'recurrence_until' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'recurrence_auto_generate_weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
            'allow_offline_payments' => ['sometimes', 'boolean'],
            'offline_payment_instructions' => ['nullable', 'string', 'max:2000'],
            'offline_payment_bank_name' => ['nullable', 'string', 'max:255'],
            'offline_payment_account_name' => ['nullable', 'string', 'max:255'],
            'offline_payment_account_number' => ['nullable', 'string', 'max:255'],
            'offline_payment_momo_number' => ['nullable', 'string', 'max:64'],
            'offline_payment_momo_network' => ['nullable', 'string', 'max:64'],
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

        while (Event::withTrashed()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
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
            'speaker_slide_policy' => $event->speaker_slide_policy ?? Event::SPEAKER_POLICY_AFTER,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'timezone' => $event->timezone,
            'location_type' => $event->location_type,
            'address' => $event->address,
            'virtual_link' => $event->virtual_link,
            'contact_email' => $event->contact_email,
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
                'is_occurrence' => (bool) $s->is_occurrence,
                'occurrence_date' => $s->occurrence_date?->toDateString(),
                'occurrence_status' => $s->occurrence_status,
                'notes' => $s->notes,
                'presentation_url' => $s->presentation_url,
                'attendances_count' => $s->attendances_count ?? ($s->relationLoaded('attendances') ? $s->attendances->count() : $s->attendances()->count()),
                'speaker_ids' => $s->speakers->pluck('id')->values(),
                'speaker_names' => $s->speakers->pluck('name')->values(),
            ])->values() : [],
            'speakers' => $event->relationLoaded('speakers') ? $event->speakers->map(fn ($s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'title' => $s->title,
                'organization' => $s->organization,
                'bio' => $s->bio,
                'photo_url' => Helper::storageUrl($s->photo_path),
                'website_url' => $s->website_url,
                'linkedin_url' => $s->linkedin_url,
                'twitter_url' => $s->twitter_url,
                'role' => data_get($s, 'pivot.role'),
                'is_confirmed' => data_get($s, 'pivot.is_confirmed') !== null ? (bool) data_get($s, 'pivot.is_confirmed') : null,
                'confirmed_at' => data_get($s, 'pivot.confirmed_at') ? \Illuminate\Support\Carbon::parse(data_get($s, 'pivot.confirmed_at'))->toIso8601String() : null,
                'last_invited_at' => data_get($s, 'pivot.last_invited_at') ? \Illuminate\Support\Carbon::parse(data_get($s, 'pivot.last_invited_at'))->toIso8601String() : null,
                'portal_token' => data_get($s, 'pivot.portal_token'),
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
                'provenance' => $m->provenance,
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
            'store_listing_id' => $event->store_listing_id,
            'venue_booking_id' => $event->venue_booking_id,
            'is_marketplace_venue' => $event->isMarketplaceVenue(),
            'event_category' => $event->event_category ?? Event::CATEGORY_GENERAL,
            'lexicon' => $event->lexicon(),
            'allow_contributions' => (bool) $event->allow_contributions,
            'contribution_title' => $event->contribution_title ?: $event->lexicon()['contributions_title'],
            'contribution_description' => $event->contribution_description,
            'contribution_presets' => $event->effectiveContributionPresets(),
            'contribution_min_amount_pesewas' => $event->contribution_min_amount_pesewas ?? 100,
            'contribution_goal_amount_pesewas' => $event->contribution_goal_amount_pesewas,
            'show_tribute_wall' => (bool) $event->show_tribute_wall,
            'show_contributor_amounts' => (bool) $event->show_contributor_amounts,
            'is_recurring' => (bool) $event->is_recurring,
            'recurrence_pattern' => $event->recurrence_pattern,
            'recurrence_days' => $event->recurrenceDays(),
            'recurrence_time_start' => $event->recurrence_time_start,
            'recurrence_time_end' => $event->recurrence_time_end,
            'recurrence_interval' => $event->recurrence_interval ?? 1,
            'recurrence_until' => $event->recurrence_until?->toDateString(),
            'recurrence_auto_generate_weeks' => $event->recurrence_auto_generate_weeks ?? 4,
            'recurrence_summary' => app(RecurrenceService::class)->describeSchedule($event),
            'allow_offline_payments' => (bool) $event->allow_offline_payments,
            'offline_payment_instructions' => $event->offline_payment_instructions,
            'offline_payment_bank_name' => $event->offline_payment_bank_name,
            'offline_payment_account_name' => $event->offline_payment_account_name,
            'offline_payment_account_number' => $event->offline_payment_account_number,
            'offline_payment_momo_number' => $event->offline_payment_momo_number,
            'offline_payment_momo_network' => $event->offline_payment_momo_network,
        ];
    }
}
