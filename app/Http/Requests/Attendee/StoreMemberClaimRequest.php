<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMemberClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'role' => ['required', Rule::in(['self', 'representative'])]];
    }

    public function messages(): array
    {
        return ['name.required' => 'Enter your name.', 'role.required' => 'Choose whether you are applying for yourself or as a representative.'];
    }
}
