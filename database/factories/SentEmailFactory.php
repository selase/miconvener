<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SentEmail;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SentEmail>
 */
final class SentEmailFactory extends Factory
{
    protected $model = SentEmail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => null,
            'recipient_email' => fake()->safeEmail(),
            'subject' => 'Your ticket',
            'mailable' => 'EventTicketLink',
            'sent_at' => now(),
        ];
    }
}
