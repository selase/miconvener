<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncStaffScansRequest;
use App\Models\EventDoorScan;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use App\Services\Events\DoorCheckIn;
use App\Services\Events\ServiceRequestDesk;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

    public function show(Request $request, string $subdomain, string $token): SymfonyResponse
    {
        $link = $this->resolve($token);

        if (! $link->isUsable()) {
            return $this->page($request, [
                'state' => 'closed',
                'link' => ['name' => $link->name],
                'event' => ['name' => $link->event?->name],
            ]);
        }

        if (! $this->unlocked($request, $link)) {
            return $this->page($request, [
                'state' => 'pin',
                'token' => $link->token,
                'link' => ['name' => $link->name],
                'event' => ['name' => $link->event->name],
            ]);
        }

        $link->forceFill(['last_used_at' => now()])->saveQuietly();

        return $this->page($request, [
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

        // The unlock outlives the session -- an usher's shift is longer than two
        // hours -- but not a change of PIN, which the value is tied to.
        return redirect()->route('public.staff.show', ['token' => $link->token])
            ->withCookie(cookie($this->cookieName($link), $this->unlockValue($link), minutes: 60 * 24 * 14, path: '/staff/'));
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
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'client_scan_id' => ['nullable', 'string', 'max:64'],
        ]);

        $registration = $this->door->findByQrToken($link->event, $validated['token']);

        if (! $registration) {
            return response()->json(['message' => 'Ticket not recognized.', 'refused' => true], 404);
        }

        // The phone's id for this scan: if the answer is slow to arrive the
        // phone queues the same scan offline, and the id stops it counting twice.
        return $this->respond($this->door->checkIn($registration, staffLink: $link, clientScanId: $validated['client_scan_id'] ?? null));
    }

    public function checkIn(Request $request, string $subdomain, string $token, string $registration): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'check_in');
        $validated = $request->validate(['client_scan_id' => ['nullable', 'string', 'max:64']]);

        $registrationModel = $link->event->registrations()->where('id', $registration)->firstOrFail();

        return $this->respond($this->door->checkIn($registrationModel, staffLink: $link, clientScanId: $validated['client_scan_id'] ?? null));
    }

    /**
     * Everything the phone needs to keep admitting guests without a
     * connection. QR tokens leave as SHA-256 hashes only: the phone can
     * recognise a scanned code but cannot produce one. No emails or phone
     * numbers.
     */
    public function pack(Request $request, string $subdomain, string $token): JsonResponse
    {
        $link = $this->authorizeFor($request, $token, 'check_in');
        $event = $link->event;
        $today = $event->doorDayFor(now());
        $timezone = $event->timezone ?: 'Africa/Accra';

        $inToday = EventDoorScan::query()
            ->where('event_id', $event->id)
            ->admittedOn($today)
            ->selectRaw('registration_id, min(scanned_at) as first_at')
            ->groupBy('registration_id')
            ->pluck('first_at', 'registration_id');

        $guests = $event->registrations()
            ->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])
            ->with(['ticketType:id,name', 'seatAssignment.room:id,name'])
            ->get(['id', 'full_name', 'ticket_code', 'qr_token', 'ticket_type_id', 'status', 'checked_in_at'])
            ->map(function (EventRegistration $registration) use ($inToday, $event, $today, $timezone): array {
                $arrivedToday = $registration->checked_in_at !== null && $event->doorDayFor($registration->checked_in_at) === $today
                    ? $registration->checked_in_at
                    : null;
                $in = $inToday[$registration->id] ?? $arrivedToday;

                return [
                    'id' => $registration->id,
                    'name' => $registration->full_name,
                    'ticket_type' => $registration->ticketType?->name,
                    'ticket_code' => $registration->ticket_code,
                    'seat' => $registration->seatAssignment?->seat_label,
                    'room' => $registration->seatAssignment?->room?->name,
                    'qr_hash' => $registration->qr_token !== null ? hash('sha256', $registration->qr_token) : null,
                    'in_today_at' => $in !== null ? Carbon::parse($in)->setTimezone($timezone)->toIso8601String() : null,
                ];
            });

        return response()->json([
            'version' => now()->toIso8601String(),
            'day' => $today,
            // A single-day event is one door day even past midnight; the phone follows suit.
            'multi_day' => $event->isMultiDay(),
            'timezone' => $timezone,
            'ends_at' => $event->ends_at->copy()->addHours(EventStaffLink::HOURS_AFTER_EVENT)->toIso8601String(),
            'guests' => $guests,
        ]);
    }

    /**
     * Scans made while the phone was offline. Each is applied once (by its
     * client id), so resending after a dropped answer is harmless. The answer
     * also carries admissions made at other doors since the last sync.
     */
    public function sync(SyncStaffScansRequest $request, string $subdomain, string $token): JsonResponse
    {
        $link = $this->authorizeForSync($request, $token);
        $results = [];

        // How far the phone's clock is from ours, so its scan times can be corrected.
        $sentAt = $request->validated('sent_at');
        $offset = $sentAt !== null ? (int) Carbon::parse($sentAt)->diffInSeconds(now(), false) : 0;

        foreach ($request->validated('scans') as $scan) {
            $registration = $link->event->registrations()->where('id', $scan['registration_id'])->first();
            $scannedAt = Carbon::parse($scan['scanned_at'])->addSeconds($offset);
            $refusal = match (true) {
                $registration === null => 'This ticket is not for this event.',
                // Transferred while the phone was offline: the old code no longer admits anyone.
                isset($scan['qr_hash']) && $registration->qr_token !== null && ! hash_equals(hash('sha256', $registration->qr_token), $scan['qr_hash']) => "{$registration->full_name} ({$registration->ticket_code}): this ticket was transferred and the code scanned is no longer valid.",
                $link->revoked_at !== null && $scannedAt->greaterThan($link->revoked_at) => 'This staff link had been switched off when this scan was made.',
                default => null,
            };

            if ($refusal !== null) {
                $results[] = [
                    'client_scan_id' => $scan['client_scan_id'],
                    'outcome' => EventDoorScan::OUTCOME_REFUSED,
                    'message' => $refusal,
                ];

                continue;
            }

            $result = $this->door->checkIn(
                $registration,
                staffLink: $link,
                scannedAt: $scannedAt,
                clientScanId: $scan['client_scan_id'],
                wasOffline: true,
            );

            $results[] = [
                'client_scan_id' => $scan['client_scan_id'],
                'outcome' => $result['body']['outcome'],
                'message' => $result['body']['message'],
            ];
        }

        $since = $request->validated('since');

        return response()->json([
            'results' => $results,
            'admitted_since' => $since === null ? [] : EventDoorScan::query()
                ->where('event_id', $link->event_id)
                ->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE])
                ->where('created_at', '>', Carbon::parse($since))
                ->get(['registration_id', 'scanned_at'])
                ->map(fn (EventDoorScan $scan): array => ['registration_id' => $scan->registration_id, 'at' => $scan->scanned_at->toIso8601String()]),
            'server_time' => now()->toIso8601String(),
        ]);
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

    /**
     * Sync is the one thing a switched-off or expired link may still do, for
     * a week after its event: scans made before it was switched off are real
     * admissions, and a phone must be able to hand them over.
     */
    private function authorizeForSync(Request $request, string $token): EventStaffLink
    {
        $link = $this->resolve($token);

        abort_unless($link->event !== null && $link->event->ends_at->copy()->addDays(7)->isFuture(), 410, 'This staff link has closed.');
        abort_unless($this->unlocked($request, $link), 403, 'Enter the PIN first.');
        abort_unless($link->can_check_in, 403, 'This staff link does not allow that.');

        $link->forceFill(['last_used_at' => now()])->saveQuietly();

        return $link;
    }

    /**
     * Rendered with a header saying which state the page is in: the offline
     * worker keeps only a ready page, never a PIN screen it could not unlock.
     *
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, array $props): SymfonyResponse
    {
        $response = Inertia::render('Public/Staff/Show', $props)->toResponse($request);
        $response->headers->set('X-Staff-State', (string) $props['state']);
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }

    private function cookieName(EventStaffLink $link): string
    {
        return 'staff_unlock_'.str_replace('-', '', $link->id);
    }

    private function unlockValue(EventStaffLink $link): string
    {
        return hash('sha256', $link->id.'|'.$link->pin_hash);
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
        if (! $link->requiresPin()) {
            return true;
        }

        $cookie = $request->cookie($this->cookieName($link));

        return $request->session()->get($this->sessionKey($link)) === true
            || (is_string($cookie) && hash_equals($this->unlockValue($link), $cookie));
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
