<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreCommercialAgreement;
use App\Models\StoreOrder;
use App\Models\StoreOrderLine;
use App\Models\StoreVariant;
use App\Models\VenueBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreOrderLine> */
final class StoreOrderLineFactory extends Factory
{
    protected $model = StoreOrderLine::class;

    public function definition(): array
    {
        return [
            'variant_id' => StoreVariant::factory(),
            'order_id' => function (array $a): string {
                $variant = StoreVariant::findOrFail($a['variant_id']);
                $agreement = StoreCommercialAgreement::factory()->create(['shop_id' => $variant->shop_id, 'tenant_id' => $variant->shop->tenant_id]);

                return StoreOrder::factory()->create(['agreement_id' => $agreement->id])->id;
            },
            'shop_id' => fn (array $a) => StoreVariant::findOrFail($a['variant_id'])->shop_id,
            'listing_id' => fn (array $a) => StoreVariant::findOrFail($a['variant_id'])->listing_id,
            'title_snapshot' => 'Association shirt', 'sku_snapshot' => 'SHIRT-M', 'options_snapshot' => ['Size' => 'M'],
            'quantity' => 1, 'unit_price_pesewas' => 3000, 'line_amount_pesewas' => 3000,
        ];
    }

    /** A reservation line: the booked venue itself, with no merchandise variant or stock. */
    public function reservation(?VenueBooking $booking = null): static
    {
        $booking ??= VenueBooking::factory()->create();

        return $this->state(function () use ($booking): array {
            $agreement = StoreCommercialAgreement::factory()->reservation()->create(['shop_id' => $booking->shop_id, 'tenant_id' => $booking->tenant_id]);

            return [
                'variant_id' => null, 'booking_id' => $booking->id, 'offering_type' => 'reservation',
                'order_id' => StoreOrder::factory()->reservation()->create(['agreement_id' => $agreement->id])->id,
                'shop_id' => $booking->shop_id, 'listing_id' => $booking->store_listing_id,
                'title_snapshot' => 'Venue booking', 'sku_snapshot' => 'RESERVATION', 'options_snapshot' => [],
            ];
        });
    }
}
