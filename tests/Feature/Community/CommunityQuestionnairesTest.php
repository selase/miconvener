<?php

declare(strict_types=1);

use App\Models\CommunityQuestionnaire;
use App\Models\CommunitySubmission;
use App\Models\Member;
use App\Services\Community\CommunityQuestionnaires;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/CommunityLibraryScenario.php';

/** @return array{App\Models\Tenant,Member,CommunityQuestionnaire} */
function questionnaireReader(string $type = 'open'): array
{
    [$tenant,$member] = libraryReader();
    foreach (['community_polls', 'community_polls_access'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $form = CommunityQuestionnaire::factory()->create(['tenant_id' => $tenant->id, 'state' => 'open']);
    $question = $form->questions()->create(['tenant_id' => $tenant->id, 'title' => 'Your response', 'type' => $type, 'position' => 0, 'required' => true, 'settings' => ['min' => 1, 'max' => 5]]);
    foreach (['First', 'Second'] as $position => $label) {
        $question->options()->create(['tenant_id' => $tenant->id, 'questionnaire_id' => $form->id, 'label' => $label, 'position' => $position]);
    }

    return [$tenant, $member, $form->load('questions.options')];
}

it('accepts all existing question types for an active member without dues', function (string $type) {
    [$tenant,$member,$form] = questionnaireReader($type);
    $question = $form->questions->first();
    $options = $question->options->pluck('id')->all();
    $answer = match ($type) {
        'multiple_choice','quiz','yes_no','rating' => ['option_id' => $options[0]],
        'multi_select','ranking' => ['option_ids' => $options],
        'word_cloud' => ['words' => [' Hope! ', 'hope', 'service']],
        'scale','number' => ['response_number' => 3],
        default => ['response_text' => 'Welcome'],
    };
    $submission = app(CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [$question->id => $answer], $form->revision);
    expect($submission->member_id)->toBe($member->id)->and($submission->answers()->count())->toBe(1);
})->with(App\Models\EventPoll::TYPES);

it('rejects incomplete and foreign answers atomically', function () {
    [$tenant,$member,$form] = questionnaireReader('multiple_choice');
    $second = $form->questions()->create(['tenant_id' => $tenant->id, 'title' => 'Required second', 'type' => 'open', 'position' => 1, 'required' => true]);
    try {
        app(CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [$form->questions->first()->id => ['option_id' => (string) Illuminate\Support\Str::uuid()]], $form->revision);
        $this->fail('Invalid options must fail.');
    } catch (ValidationException) {
    }
    expect(CommunitySubmission::withoutGlobalScopes()->count())->toBe(0);
    expect(fn () => app(CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [], $form->revision))->toThrow(ValidationException::class);
});

it('returns the existing submission for a lost-response retry and never multiplies members', function () {
    [$tenant,$member,$form] = questionnaireReader();
    $engine = app(CommunityQuestionnaires::class);
    $answers = [$form->questions->first()->id => ['response_text' => 'One']];
    $one = $engine->submit($tenant, app('session.store'), $form->id, $member->id, $answers, $form->revision);
    $two = $engine->submit($tenant, app('session.store'), $form->id, $member->id, $answers, $form->revision);
    expect($one->id)->toBe($two->id)->and(CommunitySubmission::withoutGlobalScopes()->count())->toBe(1);
    $foreign = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $member->membership_type_id]);
    expect(fn () => $engine->submit($tenant, app('session.store'), $form->id, $foreign->id, $answers, $form->revision))->toThrow(HttpException::class);
});

it('rechecks current eligibility, window, version and rollout before accepting', function (string $change) {
    [$tenant,$member,$form] = questionnaireReader();
    match ($change) {
        'lapsed' => $member->update(['status' => 'lapsed']),
        'closed' => $form->update(['closes_at' => now()]),
        'future' => $form->update(['opens_at' => now()->addHour()]),
        'revision' => $form->increment('revision'),
        'rollout' => $tenant->features()->where('feature_key', 'community_polls')->update(['enabled' => false]),
    };
    expect(fn () => app(CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [$form->questions->first()->id => ['response_text' => 'one']], 1))->toThrow(HttpException::class);
    expect(CommunitySubmission::withoutGlobalScopes()->count())->toBe(0);
})->with(['lapsed', 'closed', 'future', 'revision', 'rollout']);

it('rejects invalid data for each question type', function (string $type, array $value) {
    [$tenant,$member,$form] = questionnaireReader($type);
    expect(fn () => app(CommunityQuestionnaires::class)->submit($tenant, app('session.store'), $form->id, $member->id, [$form->questions->first()->id => $value], 1))->toThrow(ValidationException::class);
    expect(CommunitySubmission::withoutGlobalScopes()->count())->toBe(0);
})->with([
    ['multiple_choice', ['option_id' => 'foreign']], ['quiz', ['option_id' => 'foreign']], ['yes_no', ['response_number' => 1]], ['rating', ['option_id' => 'foreign']],
    ['open', ['response_text' => str_repeat('x', 1001)]], ['scale', ['response_number' => 6]], ['number', ['response_number' => 1000000001]],
    ['multi_select', ['option_ids' => ['foreign']]], ['word_cloud', ['words' => ['!!!']]], ['ranking', ['option_ids' => []]],
]);

it('uses one explicitly selected self membership for a shared mailbox', function () {
    [$tenant,$member,$form] = questionnaireReader();
    $second = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $member->membership_type_id, 'email' => $member->email]);
    $engine = app(CommunityQuestionnaires::class);
    $engine->submit($tenant, app('session.store'), $form->id, $second->id, [$form->questions->first()->id => ['response_text' => 'Selected second']], 1);
    expect($form->submissions()->pluck('member_id')->all())->toBe([$second->id]);
});

it('allows representative reading but never a representative response', function () {
    [$tenant,$member,$form] = questionnaireReader();
    app('session.store')->put(App\Services\Community\PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    app(App\Services\Community\MemberAccess::class)->issue(app('session.store'), $member, '+233241234567', 'representative', null, 'staff_review');
    app('session.store')->forget(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY);
    $engine = app(CommunityQuestionnaires::class);
    expect($engine->readers(app('session.store'), $tenant, $form))->toHaveCount(1);
    expect(fn () => $engine->submit($tenant, app('session.store'), $form->id, $member->id, [$form->questions->first()->id => ['response_text' => 'No']], 1))->toThrow(HttpException::class);
});

it('rechecks removal from the selected active group before submission', function () {
    [$tenant,$member,$form] = questionnaireReader();
    $group = App\Models\CommunityGroup::factory()->create(['tenant_id' => $tenant->id]);
    $member->groups()->attach($group->id, ['tenant_id' => $tenant->id]);
    $form->update(['audience' => ['kind' => 'groups', 'group_ids' => [$group->id]]]);
    expect(app(CommunityQuestionnaires::class)->readers(app('session.store'), $tenant, $form))->toHaveCount(1);
    $member->groups()->detach($group->id);
    expect(fn () => app(CommunityQuestionnaires::class)->submit($tenant,app('session.store'),$form->id,$member->id,[$form->questions->first()->id => ['response_text' => 'No']],1))->toThrow(HttpException::class);
});
