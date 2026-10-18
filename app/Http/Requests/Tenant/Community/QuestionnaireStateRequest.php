<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class QuestionnaireStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage community polls');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['state' => ['required_without:results_published', Rule::in(['open', 'closed', 'withdrawn'])], 'results_published' => ['required_without:state', 'boolean']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['state.required_without' => 'Choose an action.'];
    }
}
