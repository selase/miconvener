<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEventBadgeTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'width_mm' => ['required', 'numeric', 'min:40', 'max:210'],
            'height_mm' => ['required', 'numeric', 'min:40', 'max:210'],
            'orientation' => ['required', Rule::in(['landscape', 'portrait'])],
            'layout' => ['required', 'array'],
            'tier_styles' => ['nullable', 'array'],
            'tier_styles.*.background_color' => ['sometimes', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
            'tier_styles.*.text_color' => ['sometimes', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
            'sheet_settings' => ['required', 'array'],
            'sheet_settings.paper' => ['required', Rule::in(['a4', 'letter'])],
            'sheet_settings.margin_mm' => ['required', 'numeric', 'min:3', 'max:30'],
            'sheet_settings.gap_mm' => ['required', 'numeric', 'min:0', 'max:20'],
            'sheet_settings.crop_marks' => ['required', 'boolean'],
            'background' => ['nullable', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg', 'dimensions:max_width=8000,max_height=8000'],
            'remove_background' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['layout', 'tier_styles', 'sheet_settings'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => json_decode((string) $this->input($key), true)]);
            }
        }
    }
}
