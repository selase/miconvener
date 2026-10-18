<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityQuestionnaire;
use App\Models\CommunitySubmission;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Events\QuestionAnswerValidator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CommunityQuestionnaires
{
    public function __construct(private CommunityContentAccess $access, private MemberAccess $members, private CommunityLibraryRollout $base, private QuestionAnswerValidator $answers) {}

    public function available(Tenant $tenant): bool
    {
        return $this->base->baseEnabled($tenant) && ($tenant->features()->where('feature_key', 'community_polls')->where('enabled', true)->exists()
            || CommunityQuestionnaire::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists());
    }

    public function canPublish(Tenant $tenant): bool
    {
        $feature = $tenant->features()->where('feature_key', 'community_polls_access')->first();
        $entitled = $feature !== null ? $feature->enabled : in_array($tenant->package?->slug, ['growth', 'enterprise'], true);

        return $this->base->baseEnabled($tenant) && $entitled && $tenant->features()->where('feature_key', 'community_polls')->where('enabled', true)->exists();
    }

    /** @return Collection<int,Member> */
    public function readers(Session $session, Tenant $tenant, CommunityQuestionnaire $form): Collection
    {
        return $form->tenant_id === $tenant->id && $this->available($tenant) && in_array($form->state, ['open', 'closed'], true)
            && ! $form->opens_at?->isFuture() ? $this->access->readers($session, $tenant, $form->audience) : new Collection();
    }

    /** @param array<string,array<string,mixed>> $input */
    public function submit(Tenant $tenant, Session $session, string $questionnaireId, string $memberId, array $input, int $revision): CommunitySubmission
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $session, $questionnaireId, $memberId, $input, $revision): CommunitySubmission {
            $form = CommunityQuestionnaire::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($questionnaireId);
            $member = $this->readers($session, $tenant, $form)->firstWhere('id', $memberId);
            abort_unless($member !== null && $this->members->role($session, $member) === 'self', 403, 'Choose an eligible membership that you control. Representatives may read but cannot respond.');
            $existing = $form->submissions()->where('tenant_id', $tenant->id)->where('member_id', $memberId)->first();
            if ($existing !== null) {
                return $existing;
            }
            abort_unless($this->canPublish($tenant) && $form->state === 'open' && ! $form->closes_at?->lessThanOrEqualTo(now()), 409, 'This questionnaire is no longer accepting responses.');
            abort_unless($form->revision === $revision, 409, 'The questions changed. Reload before answering.');
            $form->load(['questions' => fn ($q) => $q->orderBy('position'), 'questions.options' => fn ($q) => $q->orderBy('position')]);
            if ($form->questions->isEmpty() || array_diff(array_keys($input), $form->questions->pluck('id')->all()) !== []) {
                throw ValidationException::withMessages(['answers' => 'Answer the questions shown on this form.']);
            }
            $validated = [];
            foreach ($form->questions as $question) {
                $value = $input[$question->id] ?? null;
                if ($value === null || $value === []) {
                    if ($question->required) {
                        throw ValidationException::withMessages(['answers.'.$question->id => 'This question requires an answer.']);
                    }

                    continue;
                }
                try {
                    $validated[$question->id] = $this->answers->read(new Request($value), $question->type, $question->options->pluck('id')->all(), $question->settings ?? []);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['answers.'.$question->id => collect($e->errors())->flatten()->all()]);
                }
            }
            $submission = $form->submissions()->create(['tenant_id' => $tenant->id, 'member_id' => $memberId, 'revision' => $form->revision]);
            foreach ($validated as $questionId => $answer) {
                $submission->answers()->create([...$answer, 'tenant_id' => $tenant->id, 'questionnaire_id' => $form->id, 'question_id' => $questionId]);
            }

            return $submission;
        });
    }
}
