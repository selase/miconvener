<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommercePaymentReference;
use App\Models\StoreOrder;
use App\Models\VenueBooking;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommercePaymentReference> */
final class CommercePaymentReferenceFactory extends Factory
{
    protected $model = CommercePaymentReference::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'paystack', 'account_fingerprint' => hash('sha256', 'test-account'),
            'reference' => fn () => 'pstk_'.Str::lower(Str::random(12)),
            'source_type' => CommercePaymentReference::SOURCE_ORDER,
            'order_id' => StoreOrder::factory(),
            'tenant_id' => fn (array $a) => $a['order_id'] === null ? VenueBooking::factory()->create()->tenant_id : StoreOrder::query()->findOrFail($a['order_id'])->tenant_id,
            'shop_id' => null, 'booking_id' => null, 'ledger_reference' => null, 'origin' => 'checkout',
        ];
    }

    /** A payment taken through the older public booking path, claimed against its booking. */
    public function legacyBooking(): static
    {
        return $this->state(function (): array {
            $booking = VenueBooking::factory()->create();

            return [
                'source_type' => CommercePaymentReference::SOURCE_LEGACY_BOOKING, 'order_id' => null,
                'booking_id' => $booking->id, 'tenant_id' => $booking->tenant_id, 'shop_id' => $booking->shop_id,
            ];
        });
    }
}
