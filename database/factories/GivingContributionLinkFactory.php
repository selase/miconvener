<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GivingContributionLink;
use App\Models\GivingFund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GivingContributionLink> */
final class GivingContributionLinkFactory extends Factory
{
    protected $model = GivingContributionLink::class;

    public function definition(): array
    {
        return [
            'fund_id' => GivingFund::factory(),
            'tenant_id' => fn (array $attributes) => GivingFund::withoutGlobalScopes()->findOrFail($attributes['fund_id'])->tenant_id,
            'event_contribution_id' => fn (array $attributes) => \App\Models\EventContribution::query()->create([
                'tenant_id' => $attributes['tenant_id'], 'event_id' => \App\Models\Event::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
                'contributor_name' => 'Synthetic giver', 'amount' => 10000, 'currency' => 'GHS', 'status' => 'pending_payment',
                'payment_reference' => 'synthetic_'.\Illuminate\Support\Str::uuid(),
            ])->id,
            'origin' => 'legacy_association', 'category' => 'event', 'associated_at' => now(),
        ];
    }
}
