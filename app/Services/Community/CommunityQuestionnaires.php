<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityQuestion;
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

    /** @return \Illuminate\Database\Eloquent\Builder<CommunityQuestionnaire> */
    public function visible(Session $session, Tenant $tenant): \Illuminate\Database\Eloquent\Builder
    {
        $members = $this->access->readers($session, $tenant, ['kind' => 'all_members']);
        $ids = $members->load(['groups' => fn ($q) => $q->where('community_groups.tenant_id', $tenant->id)->whereNull('archived_at')])->flatMap(fn ($m) => $m->groups->pluck('id'))->unique()->all();

        return CommunityQuestionnaire::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('state', ['open', 'closed'])
            ->whereRaw("(audience - 'kind' - 'group_ids') = '{}'::jsonb")
            ->when($members->isEmpty(), fn ($q) => $q->whereRaw('1=0'))
            ->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
            ->where(function ($q) use ($ids): void {
                $q->where('audience->kind', 'all_members');
                foreach ($ids as $id) {
                    $q->orWhere(fn ($groups) => $groups->where('audience->kind', 'groups')->whereRaw("jsonb_typeof(audience->'group_ids') = 'array'")->whereJsonContains('audience->group_ids', $id));
                }
            });
    }

    /** @param array<string,mixed> $data */
    public function save(Tenant $tenant, array $data, ?string $id, int $actor): CommunityQuestionnaire
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $data, $id, $actor): CommunityQuestionnaire {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            abort_unless($this->canPublish($locked), 403, 'An eligible plan and the Polls feature are required to publish. History is retained.');
            $form = $id === null ? new CommunityQuestionnaire(['tenant_id' => $tenant->id, 'created_by' => $actor]) : CommunityQuestionnaire::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            if ($form->exists) {
                abort_if($form->submissions()->exists(), 409, 'Questions and audience are frozen after the first response. Close this questionnaire and create a new one.');
                abort_unless(($data['revision'] ?? null) === $form->revision, 409, 'This changed in another window. Reload before editing.');
                $form->questions()->delete();
                $form->revision++;
            }
            $form->fill(array_intersect_key($data, array_flip(['title', 'description', 'kind', 'audience', 'opens_at', 'closes_at'])));
            $form->state = 'draft';
            $form->results_published_at = null;
            $form->save();
            foreach ($data['questions'] as $position => $definition) {
                $type = $definition['type'];
                $settings = $definition['settings'] ?? [];
                $settings = match ($type) {
                    'scale' => array_intersect_key($settings, array_flip(['min', 'max', 'label_min', 'label_max'])),'number' => array_intersect_key($settings, ['unit' => true]),default => []
                };
                $question = $form->questions()->create(['tenant_id' => $tenant->id, 'title' => $definition['title'], 'type' => $type, 'required' => $definition['required'], 'position' => $position, 'settings' => $settings]);
                $labels = match ($type) {
                    'yes_no' => ['Yes', 'No'],'rating' => ['1', '2', '3', '4', '5'],default => in_array($type, \App\Models\EventPoll::AUTHORED_OPTION_TYPES, true) ? $definition['options'] : []
                };
                foreach ($labels as $index => $label) {
                    $question->options()->create(['tenant_id' => $tenant->id, 'questionnaire_id' => $form->id, 'label' => $label, 'position' => $index]);
                }
            }

            return $form;
        });
    }

    public function accepting(Tenant $tenant, CommunityQuestionnaire $form): bool
    {
        return $this->canPublish($tenant) && $form->state === 'open' && ! $form->opens_at?->isFuture() && ! $form->closes_at?->lessThanOrEqualTo(now());
    }

    /** @return array<string,mixed> */
    public function payload(CommunityQuestionnaire $form, bool $staff = false): array
    {
        $form->loadMissing(['questions' => fn ($q) => $q->orderBy('position'), 'questions.options' => fn ($q) => $q->orderBy('position')]);

        return ['id' => $form->id, 'title' => $form->title, 'description' => $form->description, 'kind' => $form->kind, 'state' => $form->state, 'revision' => $form->revision, 'opens_at' => $form->opens_at?->toIso8601String(), 'closes_at' => $form->closes_at?->toIso8601String(), 'results_published' => $form->results_published_at !== null,
            'questions' => $form->questions->map($this->questionPayload(...))->values()->all(),
            ...($staff ? ['audience' => $form->audience, 'frozen' => $form->submissions()->exists()] : [])];
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

    /** @return array<string,mixed> */
    private function questionPayload(CommunityQuestion $question): array
    {
        return ['id' => $question->id, 'title' => $question->title, 'type' => $question->type, 'required' => $question->required, 'settings' => $question->settings ?? [],
            'options' => $question->options->map(fn ($option) => ['id' => $option->id, 'label' => $option->label])->values()->all()];
    }
}
