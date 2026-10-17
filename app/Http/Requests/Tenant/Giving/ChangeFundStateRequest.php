<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use Illuminate\Foundation\Http\FormRequest;

final class ChangeFundStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage giving');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Provide a reason for this change.'];
    }
}
