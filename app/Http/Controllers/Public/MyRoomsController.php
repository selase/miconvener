<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Services\Community\CommunityHistory;
use App\Services\Marketplace\VenueAvailabilityService;
use App\Services\Venue\InternalBookings;
use App\Services\Venue\StaffIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An organisation's staff booking its rooms from /my. A person who is not that
 * organisation's staff gets a 404 for everything here, so nothing reveals that
 * the organisation has private rooms.
 */
final class MyRoomsController extends Controller
{
    public function __construct(
        private readonly StaffIdentity $identity,
        private readonly InternalBookings $bookings,
        private readonly VenueAvailabilityService $availability,
        private readonly CommunityHistory $history,
    ) {}

    public function index(Request $request, string $tenantSlug): JsonResponse
    {
        $tenant = $this->staffTenant($request, $tenantSlug);
        $data = $request->validate(['from' => ['nullable', 'date'], 'days' => ['nullable', 'integer', 'min:1', 'max:31']]);

        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::now()->startOfDay();
        $to = $from->addDays((int) ($data['days'] ?? 7))->endOfDay();

        $rooms = $this->rooms($tenant)->orderBy('title')->get()->map(fn (StoreListing $room): array => [
            'id' => $room->id,
            'title' => $room->title,
            'audience' => $room->audience,
            'requires_approval' => $room->requires_approval,
            'busy' => $this->availability->getBookedSlots($room, $from, $to)->values(),
        ]);

        $mine = VenueBooking::query()->with('listing')
            ->where('tenant_id', $tenant->id)
            ->where('kind', VenueBooking::KIND_INTERNAL)
            ->whereIn('requested_for_member_id', $this->history->memberQuery($request->session())->where('tenant_id', $tenant->id)->select('id'))
            ->whereIn('status', [VenueBooking::STATUS_PENDING_APPROVAL, VenueBooking::STATUS_CONFIRMED])
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (VenueBooking $booking): array => $this->summary($booking));

        return response()->json(['organisation' => $tenant->name, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rooms' => $rooms, 'mine' => $mine]);
    }

    public function store(Request $request, string $tenantSlug, string $room): JsonResponse
    {
        $tenant = $this->staffTenant($request, $tenantSlug);
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'purpose' => ['required', 'string', 'max:500'],
            'department' => ['nullable', 'string', 'max:255'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $member = $this->identity->memberFor($tenant, $request->session());
        abort_unless($member instanceof Member, 404);

        $booking = $this->bookings->request(
            tenant: $tenant,
            room: $this->rooms($tenant)->findOrFail($room),
            for: $member,
            from: CarbonImmutable::parse($data['starts_at']),
            to: CarbonImmutable::parse($data['ends_at']),
            purpose: $data['purpose'],
            department: $data['department'] ?? null,
            guests: isset($data['guest_count']) ? (int) $data['guest_count'] : null,
        );

        return response()->json(['booking' => $this->summary($booking->load('listing'))], 201);
    }

    public function cancel(Request $request, string $tenantSlug, string $booking): JsonResponse
    {
        $tenant = $this->staffTenant($request, $tenantSlug);
        $member = $this->identity->memberFor($tenant, $request->session());
        abort_unless($member instanceof Member, 404);

        $found = VenueBooking::query()->where('tenant_id', $tenant->id)->where('kind', VenueBooking::KIND_INTERNAL)->findOrFail($booking);

        return response()->json(['booking' => $this->summary($this->bookings->cancel($found, $member)->load('listing'))]);
    }

    private function staffTenant(Request $request, string $slug): Tenant
    {
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();

        abort_unless($this->identity->isStaff($tenant, $request->session()), 404);

        return $tenant;
    }

    /**
     * The organisation's own published rooms, staff-only included.
     *
     * @return Builder<StoreListing>
     */
    private function rooms(Tenant $tenant): Builder
    {
        return StoreListing::query()->withStaffOnly()
            ->whereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $tenant->id))
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('status', StoreListing::STATUS_PUBLISHED);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(VenueBooking $booking): array
    {
        return [
            'id' => $booking->id,
            'room' => $booking->listing->title,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'status' => $booking->status,
            'purpose' => $booking->purpose,
            'department' => $booking->department,
        ];
    }
}
