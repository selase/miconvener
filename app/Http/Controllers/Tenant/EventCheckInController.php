<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventCheckInController extends Controller
{
    /**
     * Manual check-in: search by name, email, or ticket code, then check
     * in the exact registration by id.
     */
    public function search(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $query = (string) $request->query('q', '');

        $registrations = $eventModel->registrations()
            ->confirmed()
            ->where(function ($q) use ($query): void {
                $q->where('full_name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('ticket_code', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'full_name', 'email', 'ticket_code', 'status', 'checked_in_at']);

        return response()->json($registrations);
    }

    public function checkIn(Request $request, string $subdomain, string $event, string $registration): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $registrationModel = $eventModel->registrations()
            ->where('id', $registration)
            ->firstOrFail();

        return $this->markCheckedIn($registrationModel, $request);
    }

    /**
     * Camera QR scan: resolves the signed qr_token to a registration and
     * checks it in directly.
     */
    public function scan(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate(['token' => ['required', 'string']]);

        $registrationModel = $eventModel->registrations()
            ->where('qr_token', $validated['token'])
            ->first();

        if (! $registrationModel) {
            return response()->json(['message' => 'Ticket not recognized.'], 404);
        }

        return $this->markCheckedIn($registrationModel, $request);
    }

    private function markCheckedIn(EventRegistration $registration, Request $request): JsonResponse
    {
        if (! $registration->isConfirmed()) {
            return response()->json(['message' => 'This registration is not confirmed.'], 422);
        }

        $registration->loadMissing('seatAssignment.room:id,name');

        if ($registration->status === EventRegistration::STATUS_CHECKED_IN) {
            return response()->json([
                'message' => "{$registration->full_name} was already checked in.",
                'registration' => $this->registrationPayload($registration),
                'already_checked_in' => true,
            ]);
        }

        $registration->update([
            'status' => EventRegistration::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
            'checked_in_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => "{$registration->full_name} checked in.",
            'registration' => $this->registrationPayload($registration),
            'already_checked_in' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(EventRegistration $registration): array
    {
        return [
            ...$registration->only(['id', 'full_name', 'ticket_code', 'checked_in_at']),
            'seat_label' => $registration->seatAssignment?->seat_label,
            'room_name' => $registration->seatAssignment?->room?->name,
        ];
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
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
