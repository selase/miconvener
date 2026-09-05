<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventServiceRequest;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventServiceRequestController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $requests = $eventModel->serviceRequests()
            ->with(['registration:id,full_name', 'assignedTo:id,first_name,last_name'])
            ->get();

        return response()->json($requests->map(fn (EventServiceRequest $r): array => $this->payload($r)));
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

        return response()->json($this->payload($requestModel->fresh(['registration:id,full_name', 'assignedTo:id,first_name,last_name'])));
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

        return response()->json($this->payload($requestModel->fresh(['registration:id,full_name', 'assignedTo:id,first_name,last_name'])));
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EventServiceRequest $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'priority' => $r->priority,
            'status' => $r->status,
            'location' => $r->location,
            'note' => $r->note,
            'is_medical' => $r->isMedical(),
            'age_minutes' => $r->ageInMinutes(),
            'registrant_name' => $r->registration?->full_name,
            'assignee_name' => $r->assignedTo ? mb_trim($r->assignedTo->first_name.' '.$r->assignedTo->last_name) : null,
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
