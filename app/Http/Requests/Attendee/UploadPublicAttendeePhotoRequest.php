<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class UploadPublicAttendeePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'max:2048']];
    }

    public function messages(): array
    {
        return ['photo.required' => 'Choose a photo.', 'photo.max' => 'Choose a photo up to 2 MiB.'];
    }
}
