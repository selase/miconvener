<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Tenant;
use App\Services\Events\DoorCheckIn;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class EventCheckInController extends Controller
{
    /**
     * Checking people in no longer needs the right to edit the event: event
     * staff hold "check in attendees" alone. Roles that could scan before,
     * through "update event", still can.
     */
    public const array PERMISSIONS = ['check in attendees', 'update event'];

    public function __construct(private readonly DoorCheckIn $door) {}

    /**
     * Manual check-in: search by name, email, or ticket code, then check
     * in the exact registration by id.
     */
    public function search(Request $request, string $subdomain, string $event): JsonResponse
    {
        abort_unless(Gate::any(self::PERMISSIONS), 403);
        $eventModel = $this->findEvent($event);

        return response()->json($this->door->search($eventModel, (string) $request->query('q', '')));
    }

    public function checkIn(Request $request, string $subdomain, string $event, string $registration): JsonResponse
    {
        abort_unless(Gate::any(self::PERMISSIONS), 403);
        $eventModel = $this->findEvent($event);

        $registrationModel = $eventModel->registrations()
            ->where('id', $registration)
            ->firstOrFail();

        return $this->respond($this->door->checkIn($registrationModel, $request->user()));
    }

    /**
     * Camera QR scan: resolves the signed qr_token to a registration and
     * checks it in directly.
     */
    public function scan(Request $request, string $subdomain, string $event): JsonResponse
    {
        abort_unless(Gate::any(self::PERMISSIONS), 403);
        $eventModel = $this->findEvent($event);

        $validated = $request->validate(['token' => ['required', 'string']]);

        $registrationModel = $this->door->findByQrToken($eventModel, $validated['token']);

        if (! $registrationModel) {
            return response()->json(['message' => 'Ticket not recognized.'], 404);
        }

        return $this->respond($this->door->checkIn($registrationModel, $request->user()));
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    private function respond(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['status']);
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
