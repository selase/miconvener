<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use Illuminate\Foundation\Http\FormRequest;

final class CorrectCashGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage giving') && $this->user()->can('read finance');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['principal' => ['required', 'integer', 'min:1', 'max:999999999999'], 'reason' => ['required', 'string', 'max:1000'], 'operation_key' => ['required', 'uuid']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Provide a reason for the cash correction.'];
    }
}
