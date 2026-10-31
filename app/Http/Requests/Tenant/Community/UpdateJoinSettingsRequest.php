<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateJoinSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage members') ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['join_mode' => ['required', Rule::in(['approval_required', 'immediate', 'invitation_only'])], 'public_join_enabled' => ['required', 'boolean'], 'public_membership_type_id' => ['nullable', 'uuid']];
    }

    public function messages(): array
    {
        return ['join_mode.required' => 'Choose how people join.'];
    }
}
