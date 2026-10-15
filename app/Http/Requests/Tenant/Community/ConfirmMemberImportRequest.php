<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfirmMemberImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage members');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:80'], 'existing_policy' => ['required', Rule::in(['skip', 'update'])]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['token.required' => 'Preview a spreadsheet before importing.', 'existing_policy.required' => 'Choose how to handle existing members.', 'existing_policy.in' => 'Choose skip or update for existing members.'];
    }
}
