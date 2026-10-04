<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventServiceRequest;
use App\Models\Tenant;
use App\Services\Events\ServiceRequestDesk;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class EventServiceRequestController extends Controller
{
    /**
     * Answering attendee requests no longer needs the right to edit the event:
     * event staff hold "handle service-requests" alone. Roles that could answer
     * before, through "update event", still can.
     */
    public const array PERMISSIONS = ['handle service-requests', 'update event'];

    public function __construct(private readonly ServiceRequestDesk $desk) {}

    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');

        return response()->json($this->desk->forEvent($this->findEvent($event)));
    }

    public function claim(Request $request, string $subdomain, string $event, string $serviceRequest): JsonResponse
    {
        abort_unless(Gate::any(self::PERMISSIONS), 403);

        return response()->json($this->desk->claim($this->findRequest($event, $serviceRequest), $request->user()));
    }

    public function updateStatus(Request $request, string $subdomain, string $event, string $serviceRequest): JsonResponse
    {
        abort_unless(Gate::any(self::PERMISSIONS), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(EventServiceRequest::STATUSES)],
        ]);

        return response()->json($this->desk->setStatus($this->findRequest($event, $serviceRequest), $validated['status']));
    }

    private function findRequest(string $eventId, string $requestId): EventServiceRequest
    {
        return $this->findEvent($eventId)->serviceRequests()->where('id', $requestId)->firstOrFail();
    }

    private function findEvent(string $eventId): Event
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant instanceof Tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return Event::where('tenant_id', $tenant->id)->where('id', $eventId)->firstOrFail();
    }
}
