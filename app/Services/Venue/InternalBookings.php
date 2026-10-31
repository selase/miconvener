<?php

declare(strict_types=1);

namespace App\Services\Venue;

use App\Mail\Venue\RoomRequestDecided;
use App\Models\Member;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Services\Marketplace\VenueAvailabilityService;
use App\Services\Tenancy\FeatureService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Free bookings of an organisation's own rooms by its own staff: requested,
 * approved, declined and cancelled. There is no payment, commission or ledger
 * entry anywhere here, by design. The slot is checked and written under a lock
 * on the room, so two requests for the same time cannot both succeed.
 */
final class InternalBookings
{
    public function __construct(
        private readonly VenueAvailabilityService $availability,
        private readonly VenueCalendarLocks $locks,
    ) {}

    /**
     * A request is pending until a facility manager decides, unless the room
     * needs no approval or a team member entering it confirms it ($confirmNow).
     */
    public function request(
        Tenant $tenant,
        StoreListing $room,
        Member $for,
        CarbonInterface $from,
        CarbonInterface $to,
        string $purpose,
        ?string $department = null,
        ?int $guests = null,
        ?User $enteredBy = null,
        bool $confirmNow = false,
    ): VenueBooking {
        $this->assertAllowed($tenant, $room, $for, $from, $to);

        return $this->locks->forListing($room->id, function (StoreListing $locked) use ($tenant, $for, $from, $to, $purpose, $department, $guests, $enteredBy, $confirmNow): VenueBooking {
            $this->assertFree($locked, $from, $to);
            $this->assertOpenRequestsBelowLimit($tenant, $for);

            $confirmed = $confirmNow || ! $locked->requires_approval;

            return VenueBooking::query()->create([
                'booking_reference' => 'INT-'.mb_strtoupper(Str::random(8)),
                'kind' => VenueBooking::KIND_INTERNAL,
                'tenant_id' => $tenant->id,
                'shop_id' => $locked->shop_id,
                'store_listing_id' => $locked->id,
                'planner_tenant_id' => $tenant->id,
                'planner_name' => $for->name,
                'planner_email' => $for->email ?? '',
                'planner_phone' => $for->phone,
                'event_type' => 'Staff booking',
                'guest_count' => $guests ?? 1,
                'starts_at' => $from,
                'ends_at' => $to,
                'time_slot_type' => VenueBooking::SLOT_HOURLY,
                'requested_for_member_id' => $for->id,
                'entered_by_user_id' => $enteredBy?->id,
                'department' => $department,
                'purpose' => $purpose,
                'status' => $confirmed ? VenueBooking::STATUS_CONFIRMED : VenueBooking::STATUS_PENDING_APPROVAL,
                'payment_status' => VenueBooking::PAYMENT_UNPAID,
            ]);
        });
    }

    public function approve(VenueBooking $booking, User $by): VenueBooking
    {
        return $this->locks->forBooking($booking->id, function (StoreListing $listing, VenueBooking $locked) use ($by): VenueBooking {
            $this->assertPending($locked);
            $this->assertFree($listing, $locked->starts_at, $locked->ends_at, $locked->id);
            $locked->update(['status' => VenueBooking::STATUS_CONFIRMED, 'entered_by_user_id' => $locked->entered_by_user_id ?? $by->id]);
            $this->tell($locked, RoomRequestDecided::APPROVED);

            return $locked;
        });
    }

    public function decline(VenueBooking $booking, User $by, string $reason): VenueBooking
    {
        return $this->locks->forBooking($booking->id, function (StoreListing $listing, VenueBooking $locked) use ($reason): VenueBooking {
            $this->assertPending($locked);
            $locked->update(['status' => VenueBooking::STATUS_DECLINED, 'decline_reason' => $reason]);
            $this->tell($locked, RoomRequestDecided::DECLINED, $reason);

            return $locked;
        });
    }

    /**
     * A staff member cancels only their own; a team member cancels any, and
     * the staff member is told.
     */
    public function cancel(VenueBooking $booking, Member|User $by): VenueBooking
    {
        return $this->locks->forBooking($booking->id, function (StoreListing $listing, VenueBooking $locked) use ($by): VenueBooking {
            if ($locked->kind !== VenueBooking::KIND_INTERNAL || ! in_array($locked->status, [VenueBooking::STATUS_PENDING_APPROVAL, VenueBooking::STATUS_CONFIRMED], true)) {
                throw ValidationException::withMessages(['booking' => 'This booking cannot be cancelled.']);
            }

            if ($by instanceof Member && $locked->requested_for_member_id !== $by->id) {
                throw ValidationException::withMessages(['booking' => 'You can only cancel your own bookings.']);
            }

            $locked->update(['status' => VenueBooking::STATUS_CANCELLED]);

            if ($by instanceof User) {
                $this->tell($locked, RoomRequestDecided::CANCELLED);
            }

            return $locked;
        });
    }

    private function assertAllowed(Tenant $tenant, StoreListing $room, Member $for, CarbonInterface $from, CarbonInterface $to): void
    {
        if ($room->listing_kind === StoreListing::KIND_MERCHANDISE) {
            throw ValidationException::withMessages(['room' => 'Merchandise cannot be reserved as a room.']);
        }

        if (! $tenant->featureEnabled(FeatureService::FEATURE_STAFF_ROOM_BOOKING)) {
            throw ValidationException::withMessages(['room' => 'Staff room booking is not available on this plan.']);
        }

        if ($room->shop?->tenant_id !== $tenant->id) {
            throw ValidationException::withMessages(['room' => 'That room does not belong to this organisation.']);
        }

        if ($for->tenant_id !== $tenant->id || ! $for->is_staff) {
            throw ValidationException::withMessages(['member' => 'Only staff of this organisation can book its rooms.']);
        }

        $limits = [...config('bookings.staff.defaults'), ...($tenant->booking_limits ?? [])];

        if ($from->isPast() || $to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a time in the future, with an end after the start.']);
        }

        if ($from->diffInMinutes($to) > (int) $limits['max_hours'] * 60) {
            throw ValidationException::withMessages(['ends_at' => "A booking can be at most {$limits['max_hours']} hours."]);
        }

        if ($from->copy()->startOfDay()->greaterThan(now()->addDays((int) $limits['window_days'])->startOfDay())) {
            throw ValidationException::withMessages(['starts_at' => "Rooms can be booked up to {$limits['window_days']} days ahead."]);
        }
    }

    private function assertFree(StoreListing $room, CarbonInterface $from, CarbonInterface $to, ?string $ignoring = null): void
    {
        if (! $this->availability->isSlotAvailable($room, $from, $to, $ignoring)) {
            throw ValidationException::withMessages(['starts_at' => 'That time is not available. Please choose another.']);
        }
    }

    private function assertOpenRequestsBelowLimit(Tenant $tenant, Member $for): void
    {
        $limit = (int) ([...config('bookings.staff.defaults'), ...($tenant->booking_limits ?? [])]['open_requests']);

        $open = VenueBooking::query()
            ->where('kind', VenueBooking::KIND_INTERNAL)
            ->where('requested_for_member_id', $for->id)
            ->where('status', VenueBooking::STATUS_PENDING_APPROVAL)
            ->count();

        if ($open >= $limit) {
            throw ValidationException::withMessages(['member' => "There can be at most {$limit} open requests at a time."]);
        }
    }

    private function assertPending(VenueBooking $locked): void
    {
        if ($locked->kind !== VenueBooking::KIND_INTERNAL || $locked->status !== VenueBooking::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['booking' => 'This request has already been decided.']);
        }
    }

    private function tell(VenueBooking $booking, string $outcome, ?string $reason = null): void
    {
        if (filled($booking->planner_email)) {
            Mail::to($booking->planner_email)->queue(new RoomRequestDecided($booking->loadMissing('listing'), $outcome, $reason));
        }
    }
}
