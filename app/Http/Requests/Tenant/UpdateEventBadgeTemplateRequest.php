<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'sheet_settings.gap_mm' => ['required', 'numeric', $this->boolean('sheet_settings.crop_marks') ? 'min:3' : 'min:0', 'max:20'],
            'sheet_settings.crop_marks' => ['required', 'boolean'],
            'background' => ['nullable', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg', 'dimensions:max_width=8000,max_height=8000'],
            'remove_background' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $settings = $this->input('sheet_settings');
            $paper = $settings['paper'] === 'letter' ? [215.9, 279.4] : [210.0, 297.0];
            $margin = (float) $settings['margin_mm'];
            if ((float) $this->input('width_mm') > $paper[0] - 2 * $margin || (float) $this->input('height_mm') > $paper[1] - 2 * $margin) {
                $validator->errors()->add('sheet_settings', 'The badge dimensions do not fit on the selected paper.');
            }
        }];
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
