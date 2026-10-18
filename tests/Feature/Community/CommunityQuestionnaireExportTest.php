<?php

declare(strict_types=1);

use App\Services\Events\PlatformAttendeeVerification;

require_once __DIR__.'/../../Support/CommunityQuestionnaireScenario.php';

it('publishes counts without free text or respondent identities to members', function () {
    [$tenant,$member,$form] = questionnairePilot();
    app(App\Services\Community\CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [$form->questions->first()->id => ['response_text' => 'Private pastoral concern']], 1);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]]);
    $url = route('attendee.my.polls.show', ['tenantSlug' => $tenant->slug, 'questionnaire' => $form->id]);
    $this->getJson($url)->assertJsonPath('results', null);
    $form->update(['results_published_at' => now()]);
    $response = $this->getJson($url)->assertOk()->assertJsonPath('results.responses', 1);
    $results = json_encode($response->json('results'));
    expect($results)->not->toContain($member->email, $member->name, $member->id, 'Private pastoral concern');
});

it('streams attributable staff exports and neutralises spreadsheet formulas', function () {
    [$tenant,$member,$form] = questionnairePilot();
    $member->update(['name' => '=CMD()']);
    app(App\Services\Community\CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [$form->questions->first()->id => ['response_text' => '  +SUM(1,1)']], 1);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    makeTenantOwner($user, $tenant);
    $tenant->users()->syncWithoutDetaching([$user->id]);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    $response = $this->get(route('tenant.community.polls.export', ['subdomain' => $tenant->slug, 'questionnaire' => $form->id]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $csv = $response->streamedContent();
    expect($csv)->toContain("'=CMD()", "'  +SUM(1,1)", $member->email);
});

it('aggregates fixed choices and multi-select without exposing option or respondent identifiers', function (string $type) {
    [$tenant,$member,$form] = questionnairePilot();
    $question = $form->questions->first();
    $question->update(['type' => $type]);
    foreach (['Welcome', 'Set up'] as $i => $label) {
        $question->options()->create(['tenant_id' => $tenant->id, 'questionnaire_id' => $form->id, 'label' => $label, 'position' => $i]);
    }
    $ids = $question->options()->orderBy('position')->pluck('id')->all();
    $input = $type === 'multi_select' ? ['option_ids' => $ids] : ['option_id' => $ids[0]];
    app(App\Services\Community\CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [$question->id => $input], 1);
    $summary = app(App\Services\Community\CommunityQuestionnaireResults::class)->summary($form->fresh());
    expect($summary['questions'][0]['distribution'][0])->toBe(['label' => 'Welcome', 'count' => 1]);
    expect(json_encode($summary))->not->toContain($member->id,$ids[0],$member->email);
})->with(['multiple_choice', 'multi_select']);
