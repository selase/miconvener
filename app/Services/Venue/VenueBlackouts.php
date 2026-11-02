<?php

declare(strict_types=1);

namespace App\Services\Venue;

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\VenueBooking;
use App\Services\Marketplace\VenueAvailabilityService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A host's own blackout of a space. It occupies the calendar like any booking,
 * so it takes and releases time under the same listing lock.
 */
final class VenueBlackouts
{
    public function __construct(
        private readonly VenueCalendarLocks $locks,
        private readonly VenueAvailabilityService $availability,
    ) {}

    /**
     * @throws ValidationException when an active reservation, hold or blackout already occupies the time
     */
    public function create(Shop $shop, string $listingId, CarbonInterface $startsAt, CarbonInterface $endsAt, string $reason): VenueBooking
    {
        return $this->locks->forListing($listingId, function (StoreListing $listing) use ($shop, $startsAt, $endsAt, $reason): VenueBooking {
            if ($listing->shop_id !== $shop->id || $listing->listing_kind === StoreListing::KIND_MERCHANDISE) {
                throw (new ModelNotFoundException)->setModel(StoreListing::class, [$listing->id]);
            }

            if (! $this->availability->isSlotAvailable($listing, $startsAt, $endsAt)) {
                throw ValidationException::withMessages([
                    'starts_at' => ['Cannot block dates: An active reservation, hold, or blackout already occupies this space during this time.'],
                ]);
            }

            do {
                $reference = 'BLK-'.mb_strtoupper(Str::random(8));
            } while (VenueBooking::query()->where('booking_reference', $reference)->exists());

            return VenueBooking::query()->create([
                'booking_reference' => $reference,
                'tenant_id' => $shop->tenant_id,
                'shop_id' => $shop->id,
                'store_listing_id' => $listing->id,
                'planner_name' => 'Venue Maintenance / Blackout',
                'planner_email' => $shop->email ?? 'venue@miconvener.test',
                'planner_phone' => $shop->phone ?? null,
                'event_type' => 'Maintenance / Blackout',
                'guest_count' => 0,
                'layout_style' => 'banquet',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'time_slot_type' => 'full_day',
                'pricing_model' => 'per_day',
                'rate_pesewas' => 0,
                'duration_units' => 1.0,
                'rental_amount_pesewas' => 0,
                'security_deposit_pesewas' => 0,
                'total_amount_pesewas' => 0,
                'deposit_required_pesewas' => 0,
                'amount_paid_pesewas' => 0,
                'gateway_fee_pesewas' => 0,
                'status' => VenueBooking::STATUS_BLOCKED,
                'payment_status' => VenueBooking::PAYMENT_UNPAID,
                'host_notes' => $reason,
            ]);
        });
    }

    /**
     * @throws ModelNotFoundException when the block does not exist for this shop
     */
    public function remove(Shop $shop, string $bookingId): VenueBooking
    {
        return $this->locks->forBooking($bookingId, function (StoreListing $listing, VenueBooking $booking): VenueBooking {
            if ($booking->status !== VenueBooking::STATUS_BLOCKED) {
                throw (new ModelNotFoundException)->setModel(VenueBooking::class, [$booking->id]);
            }

            $booking->delete();

            return $booking;
        }, $shop->id);
    }
}
