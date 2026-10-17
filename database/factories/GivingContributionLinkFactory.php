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
            'tenant_id' => fn (array $attributes) => GivingFund::withoutGlobalScopes()->findOrFail($attributes['fund_id'])->tenant_id,
            'fund_id' => GivingFund::factory(),
        ];
    }
}
