<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventPayout> */
final class EventPayoutFactory extends Factory
{
    protected $model = EventPayout::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'payout_account_id' => TenantPayoutAccount::factory(),
            'amount' => 120_000,
            'status' => EventPayout::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(3),
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (): array => [
            'status' => EventPayout::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }
}
