<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ServiceRequestController extends Controller
{
    public function store(Request $request, string $subdomain, string $event, string $registration): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('id', $registration)
            ->firstOrFail();

        // Enforced here, not just hidden in the UI: a request raised weeks
        // early reaches an empty room, and "Someone is on the way" would be a
        // promise nobody is staffed to keep.
        $eventIsRunning = $eventModel->starts_at?->isPast() && $eventModel->ends_at?->isFuture();

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

        $serviceRequest = $eventModel->serviceRequests()->create([
            'tenant_id' => $tenant->id,
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

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
