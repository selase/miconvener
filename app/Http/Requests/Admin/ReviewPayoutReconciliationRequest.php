<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewPayoutReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['preview', 'record'])], 'external_cash' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'statement' => ['required_if:action,record', 'nullable', 'string', 'min:5', 'max:200'],
            'note' => ['required_if:action,record', 'nullable', 'string', 'min:20', 'max:1000']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['external_cash.required' => 'Specify separately reconciled external cash in pesewas, including an explicit zero.',
            'note.min' => 'Explain the provider statement and any funds belonging to other applications.'];
    }
}
