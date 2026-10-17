<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GivingHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->can('manage giving') || $this->user()?->can('read finance'));
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'gifts_page' => ['nullable', 'integer', 'min:1'], 'adjustments_page' => ['nullable', 'integer', 'min:1'], 'refund_status' => ['nullable', Rule::in(['all', 'unresolved'])]];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['q.max' => 'Search using at most 100 characters.', 'gifts_page.min' => 'Choose a valid contribution page.', 'adjustments_page.min' => 'Choose a valid adjustment page.'];
    }
}
