<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\CommunityQuestionOption> */
final class CommunityQuestionOptionFactory extends Factory
{
    protected $model = \App\Models\CommunityQuestionOption::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['question_id' => \App\Models\CommunityQuestion::factory(), 'questionnaire_id' => fn (array $a) => \App\Models\CommunityQuestion::withoutGlobalScopes()->findOrFail($a['question_id'])->questionnaire_id, 'tenant_id' => fn (array $a) => \App\Models\CommunityQuestion::withoutGlobalScopes()->findOrFail($a['question_id'])->tenant_id, 'label' => fake()->word(), 'position' => 0];
    }
}
