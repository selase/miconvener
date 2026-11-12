<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewBadgeLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['side' => ['sometimes', 'in:front,back'], 'composition' => ['sometimes', 'in:portrait,landscape'],
            'width_mm' => ['sometimes', 'numeric', 'min:40', 'max:210'], 'height_mm' => ['sometimes', 'numeric', 'min:40', 'max:210'],
            'sample_name' => ['sometimes', 'string', 'max:120'], 'sample_category' => ['sometimes', 'string', 'max:80'],
            'font_family' => ['prohibited'], 'background_path' => ['prohibited'], 'background_disk' => ['prohibited'], 'url' => ['prohibited']];
    }
}
