<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class BadgeTemplateDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['version_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'],
            'category_labels' => ['sometimes', 'array', 'max:100'], 'category_labels.*' => ['nullable', 'string', 'max:80'],
            'width_mm' => ['required', 'numeric', 'min:40', 'max:210'], 'height_mm' => ['required', 'numeric', 'min:40', 'max:210'],
            'composition' => ['required', 'in:portrait,landscape'], 'purchase_id' => ['nullable', 'uuid']];
    }
}
