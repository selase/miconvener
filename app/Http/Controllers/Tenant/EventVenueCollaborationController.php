<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventOperationTask;
use App\Models\VenueFacilityMessage;
use App\Models\VenueInspectionLog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class EventVenueCollaborationController extends Controller
{
    public function show(Request $request, string $subdomain, string $eventId): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $event = Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $eventId)
            ->firstOrFail();

        Gate::authorize('read event', $event);

        $booking = $event->venueBooking;
        if (! $booking) {
            return response()->json([
                'has_venue' => false,
                'message' => 'No venue marketplace booking attached to this event.',
            ]);
        }

        // Auto-mark host messages as read by planner
        VenueFacilityMessage::query()
            ->where('venue_booking_id', $booking->id)
            ->where('sender_type', VenueFacilityMessage::SENDER_HOST)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Tasks in venue logistics pillar
        $tasks = EventOperationTask::withoutGlobalScopes()
            ->where('event_id', $event->id)
            ->where(function ($q) use ($booking): void {
                $q->where('is_venue_task', true)
                    ->orWhere('venue_shop_id', $booking->shop_id)
                    ->orWhereHas('pillar', fn ($p) => $p->withoutGlobalScopes()->where('slug', 'venue-logistics'));
            })
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->get()
            ->map(fn (EventOperationTask $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'priority' => $t->priority,
                'status' => $t->status,
                'due_date' => $t->due_date ? \Carbon\Carbon::parse($t->due_date)->format('Y-m-d') : null,
                'is_venue_task' => (bool) $t->is_venue_task,
                'completed_at' => $t->completed_at ? \Carbon\Carbon::parse($t->completed_at)->toIso8601String() : null,
            ]);

        $messages = VenueFacilityMessage::query()
            ->where('venue_booking_id', $booking->id)
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn (VenueFacilityMessage $m): array => $m->toPayload());

        $inspections = VenueInspectionLog::query()
            ->where('venue_booking_id', $booking->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VenueInspectionLog $i): array => $i->toPayload());

        return response()->json([
            'has_venue' => true,
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'venue_name' => $booking->shop->name ?? 'Venue Partner',
                'space_title' => $booking->listing->title ?? 'Main Hall',
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'contact' => [
                    'phone' => $booking->shop?->phone,
                    'email' => $booking->shop?->email,
                    'address' => $booking->shop?->address,
                ],
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

    public function storeMessage(Request $request, string $subdomain, string $eventId): RedirectResponse|JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $event = Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $eventId)
            ->firstOrFail();

        Gate::authorize('update event', $event);

        $booking = $event->venueBooking;
        if (! $booking) {
            abort(422, 'Cannot post message: no venue booking linked to this event.');
        }

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
            'shop_id' => $booking->shop_id,
            'venue_booking_id' => $booking->id,
            'event_id' => $event->id,
            'sender_user_id' => $user?->id ? (int) $user->id : null,
            'sender_type' => VenueFacilityMessage::SENDER_PLANNER,
            'sender_name' => $user ? $user->name : 'Event Organiser',
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

        return redirect()->back()->with('success', 'Message sent to venue host.');
    }

    public function storeInspection(Request $request, string $subdomain, string $eventId): RedirectResponse|JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $event = Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $eventId)
            ->firstOrFail();

        Gate::authorize('update event', $event);

        $booking = $event->venueBooking;
        if (! $booking) {
            abort(422, 'Cannot sign inspection: no venue booking linked to this event.');
        }

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

        $inspection = VenueInspectionLog::query()
            ->where('shop_id', $booking->shop_id)
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
                'shop_id' => $booking->shop_id,
                'venue_booking_id' => $booking->id,
                'event_id' => $event->id,
                'store_listing_id' => $booking->store_listing_id,
                'type' => $validated['type'],
                'inspector_role' => 'planner',
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
