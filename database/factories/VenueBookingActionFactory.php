<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VenueBookingAction>
 */
final class VenueBookingActionFactory extends Factory
{
    protected $model = VenueBookingAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => VenueBooking::factory(),
            'shop_id' => fn (array $attributes) => VenueBooking::query()->findOrFail($attributes['booking_id'])->shop_id,
            'tenant_id' => fn (array $attributes) => VenueBooking::query()->findOrFail($attributes['booking_id'])->tenant_id,
            'actor_type' => VenueBookingAction::ACTOR_HOST,
            'actor_id' => null,
            'actor_name' => 'Test host',
            'action' => VenueBookingAction::ACTION_APPROVED,
            'operation_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'fixture'),
            'previous_status' => VenueBooking::STATUS_PENDING_HOST_APPROVAL,
            'result_status' => VenueBooking::STATUS_APPROVED_HOLD,
        ];
    }
}
