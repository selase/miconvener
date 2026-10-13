<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\MemberStatusEnum;
use App\Models\DuesClass;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Member> */
final class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'dues_class_id' => fn (array $attributes) => DuesClass::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('024#######'),
            'status' => MemberStatusEnum::Active,
        ];
    }
}
