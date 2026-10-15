<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityGroup;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityGroup> */
final class CommunityGroupFactory extends Factory
{
    protected $model = CommunityGroup::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'name' => fake()->unique()->words(3, true), 'description' => null];
    }
}
