<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\CommunityAnswer> */
final class CommunityAnswerFactory extends Factory
{
    protected $model = \App\Models\CommunityAnswer::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['submission_id' => \App\Models\CommunitySubmission::factory(), 'tenant_id' => fn (array $a) => \App\Models\CommunitySubmission::withoutGlobalScopes()->whereKey($a['submission_id'])->firstOrFail()->tenant_id, 'questionnaire_id' => fn (array $a) => \App\Models\CommunitySubmission::withoutGlobalScopes()->whereKey($a['submission_id'])->firstOrFail()->questionnaire_id, 'question_id' => fn (array $a) => \App\Models\CommunityQuestion::factory()->create(['tenant_id' => $a['tenant_id'], 'questionnaire_id' => $a['questionnaire_id']])->id, 'response_text' => fake()->sentence()];
    }
}
