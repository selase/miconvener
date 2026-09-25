<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventServiceRequest;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventServiceRequestController extends Controller
{
    /**
     * The seat is what sends a steward to the right person: a name alone means
     * walking the room asking who asked.
     *
     * @var list<string>
     */
    private const array CONSOLE_RELATIONS = [
        'registration:id,full_name',
        'registration.seatAssignment:id,registration_id,room_id,seat_label',
        'registration.seatAssignment.room:id,name',
        'assignedTo:id,first_name,last_name',
    ];

    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $requests = $eventModel->serviceRequests()
            ->with(self::CONSOLE_RELATIONS)
            ->get();

        return response()->json($requests->map(fn (EventServiceRequest $r): array => $r->consolePayload()));
    }

    public function claim(Request $request, string $subdomain, string $event, string $serviceRequest): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $requestModel = $eventModel->serviceRequests()->where('id', $serviceRequest)->firstOrFail();

        $requestModel->update([
            'assigned_to' => $request->user()->id,
            'status' => $requestModel->status === EventServiceRequest::STATUS_OPEN
                ? EventServiceRequest::STATUS_ACKNOWLEDGED
                : $requestModel->status,
            'acknowledged_at' => $requestModel->acknowledged_at ?? now(),
        ]);

        return response()->json($requestModel->fresh(self::CONSOLE_RELATIONS)->consolePayload());
    }

    public function updateStatus(Request $request, string $subdomain, string $event, string $serviceRequest): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $requestModel = $eventModel->serviceRequests()->where('id', $serviceRequest)->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in(EventServiceRequest::STATUSES)],
        ]);

        $requestModel->update([
            'status' => $validated['status'],
            'resolved_at' => $validated['status'] === EventServiceRequest::STATUS_RESOLVED ? now() : $requestModel->resolved_at,
        ]);

        return response()->json($requestModel->fresh(self::CONSOLE_RELATIONS)->consolePayload());
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    private function getTenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
