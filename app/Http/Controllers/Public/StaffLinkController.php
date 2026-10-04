<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventServiceRequest;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use App\Services\Events\DoorCheckIn;
use App\Services\Events\ServiceRequestDesk;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What an usher sees when they open their staff link: the door scanner, the
 * attendee requests, or both, for one event and nothing else. The link is the
 * credential, with an optional PIN on top for links shared in group chats.
 */
final class StaffLinkController extends Controller
{
    public function __construct(
        private readonly DoorCheckIn $door,
        private readonly ServiceRequestDesk $desk,
    ) {}

    public function show(Request $request, string $subdomain, string $token): Response
    {
        $link = $this->resolve($token);

        if (! $link->isUsable()) {
            return Inertia::render('Public/Staff/Show', [
                'state' => 'closed',
                'link' => ['name' => $link->name],
                'event' => ['name' => $link->event?->name],
            ]);
        }

        if (! $this->unlocked($request, $link)) {
            return Inertia::render('Public/Staff/Show', [
                'state' => 'pin',
                'token' => $link->token,
                'link' => ['name' => $link->name],
                'event' => ['name' => $link->event->name],
            ]);
        }

        $link->forceFill(['last_used_at' => now()])->saveQuietly();

        return Inertia::render('Public/Staff/Show', [
            'state' => 'ready',
            'token' => $link->token,
            'link' => [
                'name' => $link->name,
                'can_check_in' => $link->can_check_in,
                'can_handle_requests' => $link->can_handle_requests,
            ],
            'event' => [
                'id' => $link->event->id,
                'name' => $link->event->name,
                'is_recurring' => (bool) $link->event->is_recurring,
            ],
            'counts' => $link->can_check_in ? $this->door->counts($link->event) : null,
        ]);
    }

    public function unlock(Request $request, string $subdomain, string $token): RedirectResponse
    {
        $link = $this->resolve($token);
        $validated = $request->validate(['pin' => ['required', 'string', 'max:12']]);

        if (! $link->pinMatches($validated['pin'])) {
            return back()->withErrors(['pin' => 'That PIN is not right. Ask the organizer for it.']);
        }

        $request->session()->put($this->sessionKey($link), true);

        return redirect()->route('public.staff.show', ['token' => $link->token]);
    }

    public function counts(Request $request, string $subdomain, string $token): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'check_in');

        return response()->json($this->door->counts($link->event));
    }

    public function search(Request $request, string $subdomain, string $token): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'check_in');

        return response()->json($this->door->search($link->event, (string) $request->query('q', '')));
    }

    public function scan(Request $request, string $subdomain, string $token): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'check_in');
        $validated = $request->validate(['token' => ['required', 'string']]);

        $registration = $this->door->findByQrToken($link->event, $validated['token']);

        if (! $registration) {
            return response()->json(['message' => 'Ticket not recognized.'], 404);
        }

        return $this->respond($this->door->checkIn($registration, staffLink: $link));
    }

    public function checkIn(Request $request, string $subdomain, string $token, string $registration): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'check_in');

        $registrationModel = $link->event->registrations()->where('id', $registration)->firstOrFail();

        return $this->respond($this->door->checkIn($registrationModel, staffLink: $link));
    }

    public function requests(Request $request, string $subdomain, string $token): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'requests');

        return response()->json($this->desk->forEvent($link->event));
    }

    public function claim(Request $request, string $subdomain, string $token, string $serviceRequest): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'requests');

        return response()->json($this->desk->claim($this->findRequest($link, $serviceRequest), staffLink: $link));
    }

    public function updateStatus(Request $request, string $subdomain, string $token, string $serviceRequest): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'requests');

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                EventServiceRequest::STATUS_IN_PROGRESS,
                EventServiceRequest::STATUS_RESOLVED,
            ])],
        ]);

        return response()->json($this->desk->setStatus($this->findRequest($link, $serviceRequest), $validated['status']));
    }

    private function findRequest(EventStaffLink $link, string $requestId): EventServiceRequest
    {
        return $link->event->serviceRequests()->where('id', $requestId)->firstOrFail();
    }

    /**
     * The link, if it belongs to this subdomain's organizer, is still usable,
     * has been unlocked on this phone, and allows the work being asked for.
     */
    private function authorizeFor(Request $request, string $token, string $capability): EventStaffLink
    {
        $link = $this->resolve($token);

        abort_unless($link->isUsable(), 410, 'This staff link has been switched off or its event is over.');
        abort_unless($this->unlocked($request, $link), 403, 'Enter the PIN first.');
        abort_unless($capability === 'check_in' ? $link->can_check_in : $link->can_handle_requests, 403, 'This staff link does not allow that.');

        $link->forceFill(['last_used_at' => now()])->saveQuietly();

        return $link;
    }

    private function resolve(string $token): EventStaffLink
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless($tenant instanceof Tenant, 404);

        return EventStaffLink::query()
            ->with('event')
            ->where('tenant_id', $tenant->id)
            ->where('token', $token)
            ->firstOrFail();
    }

    private function unlocked(Request $request, EventStaffLink $link): bool
    {
        return ! $link->requiresPin() || $request->session()->get($this->sessionKey($link)) === true;
    }

    private function sessionKey(EventStaffLink $link): string
    {
        // Tied to the PIN itself, so changing the PIN locks out every phone
        // that unlocked with the old one.
        return 'staff_link_unlocked.'.$link->id.'.'.mb_substr((string) $link->pin_hash, -12);
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    private function respond(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['status']);
    }
}
