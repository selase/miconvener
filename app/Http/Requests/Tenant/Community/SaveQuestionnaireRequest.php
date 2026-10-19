<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use App\Models\EventPoll;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveQuestionnaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage community polls');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'kind' => ['required', Rule::in(['poll', 'survey'])],
            'revision' => ['sometimes', 'integer', 'min:1'], 'audience' => ['required', 'array:kind,group_ids'], 'audience.kind' => ['required', Rule::in(['all_members', 'groups'])],
            'audience.group_ids' => ['required_if:audience.kind,groups', 'array', 'min:1', 'max:50'], 'audience.group_ids.*' => ['uuid', 'distinct', Rule::exists('community_groups', 'id')->where('tenant_id', app(TenantContext::class)->getTenant()?->id)->whereNull('archived_at')],
            'opens_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date', ...($this->filled('opens_at') ? ['after:opens_at'] : [])],
            'questions' => ['required', 'array', 'list', 'min:1', 'max:30'], 'questions.*' => ['array:title,type,required,options,settings'],
            'questions.*.title' => ['required', 'string', 'max:255'], 'questions.*.type' => ['required', Rule::in(EventPoll::TYPES)], 'questions.*.required' => ['required', 'boolean'],
            'questions.*.options' => ['sometimes', 'array', 'list', 'max:12'], 'questions.*.options.*' => ['required', 'string', 'max:255'],
            'questions.*.settings' => ['sometimes', 'array:min,max,label_min,label_max,unit'], 'questions.*.settings.min' => ['sometimes', 'integer', 'between:0,100'], 'questions.*.settings.max' => ['sometimes', 'integer', 'between:1,100'],
            'questions.*.settings.label_min' => ['nullable', 'string', 'max:40'], 'questions.*.settings.label_max' => ['nullable', 'string', 'max:40'], 'questions.*.settings.unit' => ['nullable', 'string', 'max:20']];
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $questions = $this->input('questions', []);
            if (! is_array($questions)) {
                return;
            }
            if ($this->input('kind') === 'poll' && count($questions) !== 1) {
                $validator->errors()->add('questions', 'A poll has one question. Choose survey for several.');
            }
            foreach ($questions as $i => $q) {
                if (! is_array($q)) {
                    continue;
                }
                if (in_array($q['type'] ?? '', EventPoll::AUTHORED_OPTION_TYPES, true) && count(is_array($q['options'] ?? null) ? $q['options'] : []) < 2) {
                    $validator->errors()->add("questions.$i.options", 'Add between 2 and 12 choices.');
                }
                if (($q['type'] ?? '') === 'scale' && (! isset($q['settings']['min'],$q['settings']['max']) || $q['settings']['max'] <= $q['settings']['min'])) {
                    $validator->errors()->add("questions.$i.settings", 'Choose scale bounds from 0 to 100, with maximum above minimum.');
                }
            }
        }];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['questions.required' => 'Add at least one question.', 'audience.group_ids.required_if' => 'Choose an active group.', 'closes_at.after' => 'Closing time must follow opening time.'];
    }
}
