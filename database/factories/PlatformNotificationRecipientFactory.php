<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformNotificationRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformNotificationRecipient>
 */
final class PlatformNotificationRecipientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['topic' => PlatformNotificationRecipient::ENTERPRISE_LEADS, 'email' => fake()->unique()->safeEmail()];
    }
}
