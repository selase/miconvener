<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

final class UploadMerchandisePhotoRequest extends CatalogRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['photo.mimes' => 'Choose a JPEG, PNG or WebP image.', 'photo.max' => 'Photos must be 5MB or smaller.'];
    }
}
