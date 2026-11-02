<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VenueBookingCharge;
use App\Models\VenueSettlementReserve;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VenueSettlementReserve> */
final class VenueSettlementReserveFactory extends Factory
{
    protected $model = VenueSettlementReserve::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'charge_id' => VenueBookingCharge::factory(),
            'booking_id' => fn (array $a) => VenueBookingCharge::query()->findOrFail($a['charge_id'])->booking_id,
            'shop_id' => fn (array $a) => VenueBookingCharge::query()->findOrFail($a['charge_id'])->shop_id,
            'tenant_id' => fn (array $a) => VenueBookingCharge::query()->findOrFail($a['charge_id'])->tenant_id,
            'reserved_pesewas' => fn (array $a) => VenueBookingCharge::query()->findOrFail($a['charge_id'])->amount_pesewas - VenueBookingCharge::query()->findOrFail($a['charge_id'])->fee_pesewas,
            'currency' => 'GHS', 'state' => VenueSettlementReserve::HELD,
        ];
    }

    public function forCharge(VenueBookingCharge $charge): static
    {
        return $this->state(fn (): array => ['charge_id' => $charge->id]);
    }
}
