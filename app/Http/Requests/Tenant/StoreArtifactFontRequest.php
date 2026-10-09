<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class StoreArtifactFontRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'font' => ['required_without:source', 'nullable', 'file', 'max:4096'],
            'source' => ['required_without:font', 'nullable', 'string', 'max:500'],
            'name' => ['nullable', 'string', 'max:80'],
            'license_confirmed' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['font.max' => 'Font files must not exceed 4 MB.', 'license_confirmed.accepted' => 'Confirm that you have permission to use and embed this font.'];
    }
}
