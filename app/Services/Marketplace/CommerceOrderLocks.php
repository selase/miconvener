<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\VenueBooking;
use App\Models\VenueBookingCharge;
use App\Services\Venue\VenueCalendarLocks;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one entry point for locking an order before money moves. The persisted
 * order type picks the order of locks, and the two orders never cross:
 *
 * - purchase: tenant, then the order (then variants, via CommerceInventoryLocks)
 * - reservation: tenant, then the venue listing, booking and charge, then the order
 *
 * Calendar-only writers take the listing and booking without a tenant lock, so a
 * tenant lock is never requested while a listing lock is held.
 */
final class CommerceOrderLocks
{
    public function __construct(private readonly CommerceInventoryLocks $inventory, private readonly VenueCalendarLocks $calendar) {}

    public function order(StoreOrder $order): StoreOrder
    {
        $type = StoreOrder::query()->whereKey($order->id)->value('offering_type');

        return match ($type) {
            'purchase' => $this->inventory->order($order),
            'reservation' => $this->reservation($order),
            default => throw new LogicException('The order type could not be resolved.'),
        };
    }

    /**
     * Run a money operation on a booking under the reservation lock order up to
     * the booking: tenant, then listing, then booking.
     *
     * @template T
     *
     * @param  Closure(StoreListing, VenueBooking): T  $callback
     * @return T
     */
    public function forBooking(string $bookingId, Closure $callback): mixed
    {
        $booking = VenueBooking::query()->whereKey($bookingId)->first(['id', 'tenant_id', 'store_listing_id']);
        if ($booking === null) {
            throw (new ModelNotFoundException)->setModel(VenueBooking::class, [$bookingId]);
        }

        return DB::connection('landlord')->transaction(function () use ($booking, $callback): mixed {
            $this->inventory->tenant($booking->tenant_id);
            $listing = $this->calendar->listing($booking->store_listing_id);

            return $callback($listing, $this->calendar->booking($listing, $booking->id));
        });
    }

    private function reservation(StoreOrder $order): StoreOrder
    {
        $charge = VenueBookingCharge::query()->where('order_id', $order->id)->first();
        $listingId = $charge === null ? null : VenueBooking::query()->whereKey($charge->booking_id)->value('store_listing_id');
        if ($charge === null || $listingId === null || $charge->tenant_id !== $order->tenant_id) {
            throw new LogicException('A reservation order needs its venue charge.');
        }

        $this->inventory->tenant($order->tenant_id);
        $listing = $this->calendar->listing((string) $listingId);
        $this->calendar->booking($listing, $charge->booking_id);
        VenueBookingCharge::query()->lockForUpdate()->findOrFail($charge->id);

        return StoreOrder::query()->where('tenant_id', $order->tenant_id)->lockForUpdate()->findOrFail($order->id);
    }
}
