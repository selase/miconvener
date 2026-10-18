<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\CommunityQuestionnaire> */
final class CommunityQuestionnaireFactory extends Factory
{
    protected $model = \App\Models\CommunityQuestionnaire::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['tenant_id' => \App\Models\Tenant::factory(), 'title' => fake()->sentence(), 'description' => null, 'kind' => 'poll', 'audience' => ['kind' => 'all_members'], 'state' => 'draft'];
    }
}
