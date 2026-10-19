<?php

declare(strict_types=1);

require_once __DIR__.'/CommunityLibraryScenario.php';

/** @return array{App\Models\Tenant,App\Models\Member,App\Models\CommunityQuestionnaire} */
function questionnairePilot(): array
{
    [$tenant,$member] = libraryReader();
    foreach (['community_polls', 'community_polls_access'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $form = App\Models\CommunityQuestionnaire::factory()->create(['tenant_id' => $tenant->id, 'state' => 'open']);
    $form->questions()->create(['tenant_id' => $tenant->id, 'title' => 'How can we help?', 'type' => 'open', 'position' => 0, 'required' => true]);

    return [$tenant, $member, $form->load('questions.options')];
}

/** @return array<string,mixed> */
function questionnaireDefinition(): array
{
    return ['title' => 'Volunteer availability', 'kind' => 'survey', 'audience' => ['kind' => 'all_members'], 'questions' => [['title' => 'Where can you help?', 'type' => 'multiple_choice', 'required' => true, 'options' => ['Welcome', 'Set up']]]];
}
