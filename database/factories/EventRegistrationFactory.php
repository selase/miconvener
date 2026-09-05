<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventRegistration> */
final class EventRegistrationFactory extends Factory
{
    protected $model = EventRegistration::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'full_name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->e164PhoneNumber(),
            'status' => EventRegistration::STATUS_CONFIRMED,
            'ticket_code' => 'EVT-'.mb_strtoupper($this->faker->bothify('????-###')),
            'qr_token' => bin2hex(random_bytes(16)),
            'amount' => 0,
            'currency' => 'GHS',
        ];
    }

    public function pendingPayment(): static
    {
        return $this->state([
            'status' => EventRegistration::STATUS_PENDING_PAYMENT,
            'ticket_code' => null,
            'qr_token' => null,
        ]);
    }

    public function checkedIn(): static
    {
        return $this->state([
            'status' => EventRegistration::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
        ]);
    }
}
