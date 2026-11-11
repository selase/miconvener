<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class ApplyBadgeLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version_id' => ['required_without:revision_id', 'nullable', 'uuid'],
            'revision_id' => ['required_without:version_id', 'nullable', 'uuid', 'prohibits:version_id'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'category_labels' => ['sometimes', 'array', 'max:100'],
            'category_labels.*' => ['nullable', 'string', 'max:80'],
            'width_mm' => ['sometimes', 'numeric', 'min:40', 'max:210'],
            'height_mm' => ['sometimes', 'numeric', 'min:40', 'max:210'],
            'composition' => ['sometimes', 'in:portrait,landscape'],
        ];
    }
}
