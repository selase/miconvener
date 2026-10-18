<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitCommunityQuestionnaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['member_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'], 'answers' => ['present', 'array', 'max:30'], 'answers.*' => ['array:option_id,option_ids,response_text,response_number,words'], 'answers.*.option_ids' => ['sometimes', 'array', 'max:12'], 'answers.*.words' => ['sometimes', 'array', 'max:3']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['member_id.required' => 'Choose which membership you are answering for.', 'revision.required' => 'Reload the current questions before answering.'];
    }
}
