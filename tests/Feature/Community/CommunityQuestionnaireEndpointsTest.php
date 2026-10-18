<?php

declare(strict_types=1);

use App\Models\CommunityQuestionnaire;
use App\Models\User;
use App\Services\Events\PlatformAttendeeVerification;

require_once __DIR__.'/../../Support/CommunityQuestionnaireScenario.php';

it('lets authorised staff create and open a questionnaire with server-owned question definitions', function () {
    [$tenant,$user] = libraryStaff();
    foreach (['community_polls', 'community_polls_access'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    $created = $this->postJson(route('tenant.community.polls.store', ['subdomain' => $tenant->slug]), questionnaireDefinition())->assertCreated();
    $id = $created->json('questionnaire.id');
    $this->postJson(route('tenant.community.polls.state', ['subdomain' => $tenant->slug, 'questionnaire' => $id]), ['state' => 'open'])->assertOk();
    expect(CommunityQuestionnaire::withoutGlobalScopes()->findOrFail($id)->state)->toBe('open');
});

it('requires a verified contact and current own audience for questionnaire reads', function () {
    [$tenant,$member,$form] = questionnairePilot();
    $url = route('attendee.my.polls.show', ['tenantSlug' => $tenant->slug, 'questionnaire' => $form->id]);
    app('session.store')->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->getJson($url)->assertUnauthorized();
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])->getJson($url)->assertOk()->assertJsonPath('contexts.0.role', 'self')->assertJsonPath('results', null)->assertHeader('Cache-Control', 'no-store, private');
    $member->update(['status' => 'lapsed']);
    $this->getJson($url)->assertNotFound();
});

it('submits only the explicit member context and returns a safe confirmation on retry', function () {
    [$tenant,$member,$form] = questionnairePilot();
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]]);
    $url = route('attendee.my.polls.submit', ['tenantSlug' => $tenant->slug, 'questionnaire' => $form->id]);
    $data = ['member_id' => $member->id, 'revision' => 1, 'answers' => [$form->questions->first()->id => ['response_text' => 'I can volunteer']]];
    $this->postJson($url, $data)->assertOk()->assertJsonPath('received', true);
    $this->postJson($url, $data)->assertOk()->assertJsonPath('already_received', true);
    expect($form->submissions()->count())->toBe(1);
});

it('freezes question definitions after the first response while retaining close and withdrawal controls', function () {
    [$tenant,$member,$form] = questionnairePilot();
    app(App\Services\Community\CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [$form->questions->first()->id => ['response_text' => 'First']], 1);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    makeTenantOwner($user, $tenant);
    $tenant->users()->syncWithoutDetaching([$user->id]);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    $this->postJson(route('tenant.community.polls.update', ['subdomain' => $tenant->slug, 'questionnaire' => $form->id]), [...questionnaireDefinition(), 'revision' => 1])->assertStatus(409);
    $this->postJson(route('tenant.community.polls.state', ['subdomain' => $tenant->slug, 'questionnaire' => $form->id]), ['state' => 'closed'])->assertOk();
    $this->postJson(route('tenant.community.polls.state', ['subdomain' => $tenant->slug, 'questionnaire' => $form->id]), ['state' => 'withdrawn'])->assertOk();
    expect($form->fresh()->state)->toBe('withdrawn')->and($form->submissions()->count())->toBe(1);
});

it('denies management to readers and attributable results to a publisher without results permission', function () {
    [$tenant,$owner] = libraryStaff();
    foreach (['community_polls', 'community_polls_access'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $form = CommunityQuestionnaire::factory()->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('manage community polls');
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    $this->getJson(route('tenant.community.polls.results', ['subdomain' => $tenant->slug, 'questionnaire' => $form->id]))->assertForbidden();
    $user->revokePermissionTo('manage community polls');
    $user->givePermissionTo('read community poll results');
    $this->postJson(route('tenant.community.polls.store', ['subdomain' => $tenant->slug]), questionnaireDefinition())->assertForbidden();
});

it('retains authorised history after downgrade but stops new responses and hides withdrawn questionnaires', function () {
    [$tenant,$member,$form] = questionnairePilot();
    $tenant->features()->where('feature_key', 'community_polls_access')->update(['enabled' => false]);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]]);
    $args = ['tenantSlug' => $tenant->slug, 'questionnaire' => $form->id];
    $this->getJson(route('attendee.my.polls.show', $args))->assertOk()->assertJsonPath('accepting', false);
    $this->postJson(route('attendee.my.polls.submit', $args), ['member_id' => $member->id, 'revision' => 1, 'answers' => [$form->questions->first()->id => ['response_text' => 'No']]])->assertStatus(409);
    $form->update(['state' => 'withdrawn']);
    $this->getJson(route('attendee.my.polls.show', $args))->assertNotFound();
});

it('rejects malformed question definitions, foreign groups and cross-tenant links', function () {
    [$tenant,$user] = libraryStaff();
    foreach (['community_polls', 'community_polls_access'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    $data = questionnaireDefinition();
    $data['questions'][0]['type'] = 'client-invented';
    $this->postJson(route('tenant.community.polls.store', ['subdomain' => $tenant->slug]), $data)->assertUnprocessable();
    $data = questionnaireDefinition();
    $data['audience'] = ['kind' => 'groups', 'group_ids' => [App\Models\CommunityGroup::factory()->create()->id]];
    $this->postJson(route('tenant.community.polls.store', ['subdomain' => $tenant->slug]), $data)->assertUnprocessable();
    $foreign = CommunityQuestionnaire::factory()->create();
    $this->postJson(route('tenant.community.polls.state',['subdomain' => $tenant->slug, 'questionnaire' => $foreign->id]),['state' => 'open'])->assertNotFound();
});
