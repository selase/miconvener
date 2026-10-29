<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\VenueBookingAction;
use App\Models\VenuePaymentResolution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VenuePaymentResolution> */
final class VenuePaymentResolutionFactory extends Factory
{
    protected $model = VenuePaymentResolution::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'action_id' => VenueBookingAction::factory()->state(['action' => VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT, 'actor_type' => VenueBookingAction::ACTOR_SYSTEM]),
            'booking_id' => fn (array $a) => VenueBookingAction::query()->findOrFail($a['action_id'])->booking_id,
            'shop_id' => fn (array $a) => VenueBookingAction::query()->findOrFail($a['action_id'])->shop_id,
            'tenant_id' => fn (array $a) => VenueBookingAction::query()->findOrFail($a['action_id'])->tenant_id,
            'step' => VenuePaymentResolution::RETAINED,
            'provider' => 'paystack', 'account_fingerprint' => hash('sha256', 'test-account'), 'reference' => 'pstk_late_1',
            'verified_amount_pesewas' => null, 'currency' => 'GHS', 'reason' => 'Held while staff investigate.',
            'actor_id' => User::factory(), 'actor_name' => 'Test finance',
            'operation_key' => fn () => (string) Str::uuid(), 'request_hash' => hash('sha256', 'resolution-request'), 'evidence' => null,
        ];
    }

    public function forAction(VenueBookingAction $action): static
    {
        return $this->state(fn (): array => ['action_id' => $action->id]);
    }
}
