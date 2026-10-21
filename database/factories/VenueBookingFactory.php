<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\VenueBooking;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A free, confirmed marketplace booking for tomorrow; states change what matters.
 *
 * @extends Factory<VenueBooking>
 */
final class VenueBookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $listing = StoreListing::factory()->create();
        $shop = Shop::query()->findOrFail($listing->shop_id);
        $starts = now()->addDay()->setTime(9, 0);

        return [
            'booking_reference' => 'BKG-'.mb_strtoupper(Str::random(8)),
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'store_listing_id' => $listing->id,
            'planner_name' => fake()->name(),
            'planner_email' => fake()->unique()->safeEmail(),
            'event_type' => 'Meeting',
            'guest_count' => 10,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours(2),
            'time_slot_type' => VenueBooking::SLOT_HOURLY,
            'status' => VenueBooking::STATUS_CONFIRMED,
            'payment_status' => VenueBooking::PAYMENT_UNPAID,
        ];
    }

    /**
     * An internal booking: free, for the organisation's own staff.
     */
    public function internal(): self
    {
        return $this->state(['kind' => VenueBooking::KIND_INTERNAL, 'status' => VenueBooking::STATUS_PENDING_APPROVAL]);
    }
}
