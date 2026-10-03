<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantAddon;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantAddon>
 */
final class TenantAddonFactory extends Factory
{
    protected $model = TenantAddon::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'addon_type' => TenantAddon::TYPE_TEAM_SEAT,
            'name' => 'Additional Team Member Seat',
            'quantity' => 1,
            'unit_price' => 7500,
            'total_price' => 7500,
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
            'status' => TenantAddon::STATUS_ACTIVE,
            'paystack_reference' => 'ADDON-'.mb_strtoupper(Str::random(12)),
            'period_start' => Carbon::now(),
            'period_end' => Carbon::now()->addMonth(),
            'meta' => [],
        ];
    }

    public function teamSeat(int $quantity = 1): self
    {
        return $this->state(fn () => [
            'addon_type' => TenantAddon::TYPE_TEAM_SEAT,
            'name' => "Additional Team Member Seat ({$quantity})",
            'quantity' => $quantity,
            'unit_price' => 7500,
            'total_price' => 7500 * $quantity,
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
        ]);
    }

    public function usherPack(int $quantity = 1): self
    {
        return $this->state(fn () => [
            'addon_type' => TenantAddon::TYPE_USHER_PACK,
            'name' => 'Event Day Usher / Check-in Scanner Pack (5 users)',
            'quantity' => $quantity,
            'unit_price' => 6000,
            'total_price' => 6000 * $quantity,
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
        ]);
    }

    public function livePollingMonthly(): self
    {
        return $this->state(fn () => [
            'addon_type' => TenantAddon::TYPE_LIVE_POLLING,
            'name' => 'Interactive Live Polling & Q&A Module (Monthly)',
            'quantity' => 1,
            'unit_price' => 8500,
            'total_price' => 8500,
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
            'event_id' => null,
        ]);
    }

    public function livePollingEventPass(string $eventId): self
    {
        return $this->state(fn () => [
            'addon_type' => TenantAddon::TYPE_LIVE_POLLING,
            'name' => 'Live Polling Single-Event Pass',
            'quantity' => 1,
            'unit_price' => 5000,
            'total_price' => 5000,
            'billing_interval' => TenantAddon::INTERVAL_EVENT_PASS,
            'event_id' => $eventId,
            'period_end' => Carbon::now()->addDays(7),
        ]);
    }
}
