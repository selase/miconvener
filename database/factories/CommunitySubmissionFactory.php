<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\CommunitySubmission> */
final class CommunitySubmissionFactory extends Factory
{
    protected $model = \App\Models\CommunitySubmission::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['questionnaire_id' => \App\Models\CommunityQuestionnaire::factory(), 'tenant_id' => fn (array $a) => \App\Models\CommunityQuestionnaire::withoutGlobalScopes()->findOrFail($a['questionnaire_id'])->tenant_id, 'member_id' => fn (array $a) => \App\Models\Member::factory()->create(['tenant_id' => $a['tenant_id']])->id, 'revision' => 1];
    }
}
