<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventLedgerEntry> */
final class EventLedgerEntryFactory extends Factory
{
    protected $model = EventLedgerEntry::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'gross_amount' => 10_000,
            'gateway_fee_amount' => 150,
            'commission_amount' => 500,
            'net_amount' => 9_350,
            'currency' => 'GHS',
            'provider' => 'paystack',
            'provider_reference' => 'ref_'.$this->faker->uuid(),
        ];
    }

    public function refund(): self
    {
        return $this->state(fn (): array => [
            'type' => EventLedgerEntry::TYPE_REFUND,
            'net_amount' => -9_350,
        ]);
    }

    public function payout(): self
    {
        return $this->state(fn (): array => [
            'type' => EventLedgerEntry::TYPE_PAYOUT,
            'gateway_fee_amount' => 0,
            'commission_amount' => 0,
        ]);
    }
}
