<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateBadgeSheetRequest extends FormRequest
{
    public const int MAX_BADGES = 100;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registration_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BADGES],
            'registration_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'registration_ids.max' => 'Generate at most 100 badges at a time. Split larger groups into multiple PDFs.',
        ];
    }
}
