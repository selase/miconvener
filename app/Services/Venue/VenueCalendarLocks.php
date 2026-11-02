<?php

declare(strict_types=1);

namespace App\Services\Venue;

use App\Models\StoreListing;
use App\Models\VenueBooking;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one lock order for everything that creates, changes or removes calendar
 * occupancy: the listing row first, then at most one booking row. Taking any
 * other order, or checking availability before the listing lock, lets two
 * writers both see a free slot.
 *
 * The lock grants no permission. The caller resolves ownership first and the
 * helpers re-verify it under the lock.
 */
final class VenueCalendarLocks
{
    /**
     * Lock a listing for the rest of the current landlord transaction.
     */
    public function listing(string $listingId): StoreListing
    {
        $this->assertInTransaction();

        return StoreListing::query()->withStaffOnly()->lockForUpdate()->findOrFail($listingId);
    }

    /**
     * Lock one booking of an already locked listing, so its state is read after the listing lock.
     */
    public function booking(StoreListing $lockedListing, string $bookingId): VenueBooking
    {
        $this->assertInTransaction();

        return VenueBooking::query()
            ->whereKey($bookingId)
            ->where('store_listing_id', $lockedListing->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Run a calendar change under the listing lock.
     *
     * @template T
     *
     * @param  Closure(StoreListing): T  $callback
     * @return T
     */
    public function forListing(string $listingId, Closure $callback): mixed
    {
        return DB::connection('landlord')->transaction(fn (): mixed => $callback($this->listing($listingId)));
    }

    /**
     * Run a change to one booking under the listing lock and then the booking lock.
     * A booking never changes listing, so its listing is read first without a lock.
     *
     * @template T
     *
     * @param  Closure(StoreListing, VenueBooking): T  $callback
     * @param  string|null  $shopId  When given, the booking must belong to this shop.
     * @return T
     */
    public function forBooking(string $bookingId, Closure $callback, ?string $shopId = null): mixed
    {
        $listingId = VenueBooking::query()->whereKey($bookingId)->value('store_listing_id');
        if ($listingId === null) {
            throw (new ModelNotFoundException)->setModel(VenueBooking::class, [$bookingId]);
        }

        return DB::connection('landlord')->transaction(function () use ($listingId, $bookingId, $callback, $shopId): mixed {
            $listing = $this->listing((string) $listingId);
            $booking = $this->booking($listing, $bookingId);

            if ($shopId !== null && $booking->shop_id !== $shopId) {
                throw (new ModelNotFoundException)->setModel(VenueBooking::class, [$bookingId]);
            }

            return $callback($listing, $booking);
        });
    }

    private function assertInTransaction(): void
    {
        if (DB::connection('landlord')->transactionLevel() < 1) {
            throw new LogicException('Calendar locks must be taken inside a landlord transaction.');
        }
    }
}
