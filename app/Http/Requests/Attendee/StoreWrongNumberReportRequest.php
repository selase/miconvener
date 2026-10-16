<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWrongNumberReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:500'], 'member_id' => ['nullable', 'uuid']];
    }

    public function messages(): array
    {
        return ['note.required' => 'Briefly explain the number issue.'];
    }
}
