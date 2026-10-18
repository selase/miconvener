<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\CommunityQuestion> */
final class CommunityQuestionFactory extends Factory
{
    protected $model = \App\Models\CommunityQuestion::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['questionnaire_id' => \App\Models\CommunityQuestionnaire::factory(), 'tenant_id' => fn (array $a) => \App\Models\CommunityQuestionnaire::withoutGlobalScopes()->findOrFail($a['questionnaire_id'])->tenant_id, 'title' => fake()->sentence(), 'type' => 'open', 'position' => 0, 'required' => true, 'settings' => []];
    }
}
