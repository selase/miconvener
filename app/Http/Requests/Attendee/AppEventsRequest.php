<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class AppEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['period' => ['sometimes', 'in:upcoming,past'], 'organiser' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['period.in' => 'Choose upcoming or past tickets.', 'page.min' => 'Choose a valid page.'];
    }
}
