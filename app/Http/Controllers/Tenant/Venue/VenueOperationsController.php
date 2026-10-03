<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Models\EventOperationPillar;
use App\Models\EventOperationTask;
use App\Models\Shop;
use App\Models\VenueBooking;
use App\Models\VenueFacilityMessage;
use App\Models\VenueInspectionLog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class VenueOperationsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        // Query all active and upcoming bookings hosted at this venue shop
        $bookings = VenueBooking::query()
            ->with(['listing', 'plannerTenant', 'event'])
            ->where('shop_id', $shop->id)
            ->whereIn('status', [
                VenueBooking::STATUS_CONFIRMED,
                VenueBooking::STATUS_COMPLETED,
                VenueBooking::STATUS_PENDING_PAYMENT,
            ])
            ->orderBy('starts_at')
            ->get();

        $now = now();
        $todayStart = (clone $now)->startOfDay();
        $todayEnd = (clone $now)->endOfDay();

        $hostedEvents = $bookings->map(function (VenueBooking $booking) use ($shop): array {
            $event = $booking->event;

            // Facility tasks for this event
            $tasksQuery = EventOperationTask::withoutGlobalScopes();
            if ($event) {
                $tasksQuery->where('event_id', $event->id)
                    ->where(function ($q) use ($shop): void {
                        $q->where('is_venue_task', true)
                            ->orWhere('venue_shop_id', $shop->id)
                            ->orWhereHas('pillar', fn ($p) => $p->withoutGlobalScopes()->where('slug', 'venue-logistics'));
                    });
            } else {
                $tasksQuery->where('venue_shop_id', $shop->id);
            }

            $totalTasks = (clone $tasksQuery)->count();
            $completedTasks = (clone $tasksQuery)->where('status', EventOperationTask::STATUS_DONE)->count();
            $readinessPercent = $totalTasks > 0 ? (int) round(($completedTasks / $totalTasks) * 100) : 100;

            // Inspection logs
            $checkIn = VenueInspectionLog::query()
                ->where('shop_id', $shop->id)
                ->where(fn ($q) => $q->where('venue_booking_id', $booking->id)->when($event, fn ($e) => $e->orWhere('event_id', $event->id)))
                ->where('type', VenueInspectionLog::TYPE_CHECK_IN)
                ->latest()
                ->first();

            $checkOut = VenueInspectionLog::query()
                ->where('shop_id', $shop->id)
                ->where(fn ($q) => $q->where('venue_booking_id', $booking->id)->when($event, fn ($e) => $e->orWhere('event_id', $event->id)))
                ->where('type', VenueInspectionLog::TYPE_CHECK_OUT)
                ->latest()
                ->first();

            // Unread messages from planner
            $unreadMessages = VenueFacilityMessage::query()
                ->where('shop_id', $shop->id)
                ->where(fn ($q) => $q->where('venue_booking_id', $booking->id)->when($event, fn ($e) => $e->orWhere('event_id', $event->id)))
                ->where('sender_type', VenueFacilityMessage::SENDER_PLANNER)
                ->whereNull('read_at')
                ->count();

            return [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'title' => $event ? $event->name : "{$booking->planner_name}'s {$booking->event_type}",
                'event_type' => $booking->event_type,
                'space_title' => $booking->listing ? $booking->listing->title : 'Main Hall',
                'space_slug' => $booking->listing?->slug,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'guest_count' => $booking->guest_count,
                'layout_style' => $booking->layout_style,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'planner' => [
                    'name' => $booking->planner_name,
                    'email' => $booking->planner_email,
                    'phone' => $booking->planner_phone,
                    'company' => $booking->planner_company,
                    'tenant_name' => $booking->plannerTenant?->name,
                ],
                'has_event' => $event !== null,
                'event_slug' => $event?->slug,
                'tasks_total' => $totalTasks,
                'tasks_completed' => $completedTasks,
                'readiness_percent' => $readinessPercent,
                'check_in_status' => $checkIn ? $checkIn->status : 'not_started',
                'check_out_status' => $checkOut ? $checkOut->status : 'not_started',
                'unread_messages' => $unreadMessages,
            ];
        });

        // Summary Stats
        $upcomingCount = $bookings->filter(fn (VenueBooking $b) => $b->ends_at->gte($now))->count();
        $activeTasksCount = EventOperationTask::withoutGlobalScopes()
            ->where('venue_shop_id', $shop->id)
            ->where('status', '!=', EventOperationTask::STATUS_DONE)
            ->count();
        $unreadMessagesTotal = VenueFacilityMessage::query()
            ->where('shop_id', $shop->id)
            ->where('sender_type', VenueFacilityMessage::SENDER_PLANNER)
            ->whereNull('read_at')
            ->count();
        $todayOccupancyCount = $bookings->filter(fn (VenueBooking $b) => $b->starts_at->lte($todayEnd) && $b->ends_at->gte($todayStart))->count();

        return Inertia::render('Tenant/Venue/Operations/Index', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'address' => $shop->address,
                'city' => $shop->city,
                'phone' => $shop->phone,
            ],
            'stats' => [
                'upcoming_events_count' => $upcomingCount,
                'active_facility_tasks_count' => $activeTasksCount,
                'unread_messages_count' => $unreadMessagesTotal,
                'today_occupancy_count' => $todayOccupancyCount,
            ],
            'hostedEvents' => $hostedEvents->values()->all(),
        ]);
    }

    public function show(Request $request, string $subdomain, string $bookingId): Response
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $booking = VenueBooking::query()
            ->with(['listing', 'plannerTenant', 'event'])
            ->where('shop_id', $shop->id)
            ->where('id', $bookingId)
            ->firstOrFail();

        $event = $booking->event;

        // Auto-seed default pillars if event exists
        if ($event) {
            EventOperationPillar::seedDefaultsForEvent($event);
        }

        // Query facility tasks
        $tasksQuery = EventOperationTask::withoutGlobalScopes()->with(['pillar' => fn ($p) => $p->withoutGlobalScopes()]);
        if ($event) {
            $tasksQuery->where('event_id', $event->id)
                ->where(function ($q) use ($shop): void {
                    $q->where('is_venue_task', true)
                        ->orWhere('venue_shop_id', $shop->id)
                        ->orWhereHas('pillar', fn ($p) => $p->withoutGlobalScopes()->where('slug', 'venue-logistics'));
                });
        } else {
            $tasksQuery->where('venue_shop_id', $shop->id);
        }

        $tasks = $tasksQuery->orderBy('due_date')->orderBy('created_at')->get()
            ->map(fn (EventOperationTask $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'pillar_name' => $t->pillar ? $t->pillar->name : 'Venue & Logistics',
                'priority' => $t->priority,
                'status' => $t->status,
                'due_date' => $t->due_date ? \Carbon\Carbon::parse($t->due_date)->format('Y-m-d') : null,
                'is_venue_task' => (bool) $t->is_venue_task,
                'completed_at' => $t->completed_at ? \Carbon\Carbon::parse($t->completed_at)->toIso8601String() : null,
            ]);

        // Auto-mark unread messages from planner as read
        VenueFacilityMessage::query()
            ->where('shop_id', $shop->id)
            ->where('venue_booking_id', $booking->id)
            ->where('sender_type', VenueFacilityMessage::SENDER_PLANNER)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Query messages
        $messages = VenueFacilityMessage::query()
            ->where('shop_id', $shop->id)
            ->where('venue_booking_id', $booking->id)
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn (VenueFacilityMessage $m): array => $m->toPayload());

        // Query inspections
        $inspections = VenueInspectionLog::query()
            ->where('shop_id', $shop->id)
            ->where('venue_booking_id', $booking->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VenueInspectionLog $i): array => $i->toPayload());

        return Inertia::render('Tenant/Venue/Operations/Show', [
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'event_type' => $booking->event_type,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'layout_style' => $booking->layout_style,
                'guest_count' => $booking->guest_count,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'special_requests' => $booking->special_requests,
                'listing' => $booking->listing ? [
                    'id' => $booking->listing->id,
                    'title' => $booking->listing->title,
                    'slug' => $booking->listing->slug,
                    'address' => $shop->address,
                ] : null,
                'planner' => [
                    'name' => $booking->planner_name,
                    'email' => $booking->planner_email,
                    'phone' => $booking->planner_phone,
                    'company' => $booking->planner_company,
                    'tenant_name' => $booking->plannerTenant?->name,
                ],
                'event' => $event ? [
                    'id' => $event->id,
                    'name' => $event->name,
                    'slug' => $event->slug,
                    'status' => $event->status,
                ] : null,
            ],
            'tasks' => $tasks->values()->all(),
            'messages' => $messages->values()->all(),
            'inspections' => $inspections->values()->all(),
            'defaultChecklists' => [
                'check_in' => VenueInspectionLog::defaultChecklist(VenueInspectionLog::TYPE_CHECK_IN),
                'check_out' => VenueInspectionLog::defaultChecklist(VenueInspectionLog::TYPE_CHECK_OUT),
            ],
        ]);
    }

    public function createTask(Request $request, string $subdomain, string $bookingId): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $booking = VenueBooking::query()
            ->where('shop_id', $shop->id)
            ->where('id', $bookingId)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'priority' => ['required', Rule::in(EventOperationTask::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $event = $booking->event;
        if (! $event) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Cannot create facility task: no event is linked to this booking yet.',
                ], 422);
            }
            abort(422, 'Cannot create facility task: no event is linked to this booking yet.');
        }

        EventOperationPillar::seedDefaultsForEvent($event);
        $pillar = EventOperationPillar::withoutGlobalScopes()
            ->where('event_id', $event->id)
            ->where('slug', 'venue-logistics')
            ->first() ?? EventOperationPillar::withoutGlobalScopes()->where('event_id', $event->id)->first();

        $task = EventOperationTask::withoutGlobalScopes()->create([
            'tenant_id' => $event->tenant_id,
            'event_id' => $event->id,
            'pillar_id' => $pillar->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'],
            'due_date' => $validated['due_date'] ?? null,
            'status' => EventOperationTask::STATUS_NOT_STARTED,
            'is_venue_task' => true,
            'venue_shop_id' => $shop->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'priority' => $task->priority,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Facility task created successfully.');
    }

    public function updateTaskStatus(Request $request, string $subdomain, string $bookingId, string $taskId): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $booking = VenueBooking::query()
            ->where('shop_id', $shop->id)
            ->where('id', $bookingId)
            ->firstOrFail();

        $event = $booking->event;

        $task = EventOperationTask::withoutGlobalScopes()
            ->where(function ($q) use ($event, $shop): void {
                if ($event) {
                    $q->where('event_id', $event->id);
                } else {
                    $q->where('venue_shop_id', $shop->id);
                }
            })
            ->where('id', $taskId)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in(EventOperationTask::STATUSES)],
        ]);

        $task->status = $validated['status'];
        $task->completed_at = $validated['status'] === EventOperationTask::STATUS_DONE ? now() : null;
        $task->save();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'task' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'completed_at' => $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->toIso8601String() : null,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Task status updated.');
    }

    public function storeMessage(Request $request, string $subdomain, string $bookingId): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $booking = VenueBooking::query()
            ->where('shop_id', $shop->id)
            ->where('id', $bookingId)
            ->firstOrFail();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf,docx,xlsx', 'max:10240'],
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('venue-collaborations', 'public');
        }

        $user = $request->user();

        $msg = VenueFacilityMessage::create([
            'shop_id' => $shop->id,
            'venue_booking_id' => $booking->id,
            'event_id' => $booking->event?->id,
            'sender_user_id' => $user?->id ? (int) $user->id : null,
            'sender_type' => VenueFacilityMessage::SENDER_HOST,
            'sender_name' => $user ? $user->name : $shop->name.' Facility Desk',
            'message' => $validated['message'],
            'attachment_path' => $attachmentPath,
            'read_at' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg->toPayload(),
            ]);
        }

        return redirect()->back()->with('success', 'Message sent to planner.');
    }

    public function storeInspection(Request $request, string $subdomain, string $bookingId): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $booking = VenueBooking::query()
            ->where('shop_id', $shop->id)
            ->where('id', $bookingId)
            ->firstOrFail();

        $validated = $request->validate([
            'type' => ['required', Rule::in(VenueInspectionLog::TYPES)],
            'inspector_name' => ['required', 'string', 'max:255'],
            'checklist' => ['nullable', 'array', 'max:100'],
            'checklist.*.item' => ['required', 'string', 'max:255'],
            'checklist.*.status' => ['required', 'string', Rule::in(['good', 'damaged', 'not_applicable'])],
            'checklist.*.notes' => ['nullable', 'string', 'max:1000'],
            'general_notes' => ['nullable', 'string', 'max:5000'],
            'sign_now' => ['nullable', 'boolean'],
        ]);

        $checklist = $validated['checklist'] ?? VenueInspectionLog::defaultChecklist($validated['type']);

        // Check if any item is flagged as damaged
        $hasIssues = false;
        foreach ($checklist as $item) {
            if (($item['status'] ?? '') === 'damaged') {
                $hasIssues = true;
                break;
            }
        }

        $status = $hasIssues ? VenueInspectionLog::STATUS_FLAGGED : VenueInspectionLog::STATUS_PASSED;
        $signNow = (bool) ($validated['sign_now'] ?? true);
        $user = $request->user();

        // Check for existing inspection to update if unsigned
        $inspection = VenueInspectionLog::query()
            ->where('shop_id', $shop->id)
            ->where('venue_booking_id', $booking->id)
            ->where('type', $validated['type'])
            ->latest()
            ->first();

        if ($inspection && $inspection->isSigned()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'This inspection report has already been signed and finalized.'], 422);
            }
            abort(422, 'This inspection report has already been signed and finalized.');
        }

        if (! $inspection) {
            $inspection = new VenueInspectionLog([
                'shop_id' => $shop->id,
                'venue_booking_id' => $booking->id,
                'event_id' => $booking->event?->id,
                'store_listing_id' => $booking->store_listing_id,
                'type' => $validated['type'],
                'inspector_role' => 'host',
            ]);
        }

        $inspection->inspector_user_id = $user ? (int) $user->id : null;
        $inspection->inspector_name = $validated['inspector_name'];
        $inspection->status = $status;
        $inspection->checklist = $checklist;
        $inspection->general_notes = $validated['general_notes'] ?? null;

        if ($signNow) {
            $inspection->signed_by_name = $validated['inspector_name'];
            $inspection->signed_at = now();
        }

        $inspection->save();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'inspection' => $inspection->toPayload(),
            ]);
        }

        return redirect()->back()->with('success', 'Inspection log recorded successfully.');
    }
}
