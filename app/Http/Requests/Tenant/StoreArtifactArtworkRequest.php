<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class StoreArtifactArtworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'artwork' => [
                'required',
                'file',
                'max:10240',
                'mimetypes:image/png,image/jpeg',
                'dimensions:max_width=8000,max_height=8000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'artwork.mimetypes' => 'Artwork must be a PNG or JPEG image.',
            'artwork.dimensions' => 'Artwork dimensions must not exceed 8000 by 8000 pixels.',
            'artwork.max' => 'Artwork must not exceed 10 MB.',
        ];
    }
}
