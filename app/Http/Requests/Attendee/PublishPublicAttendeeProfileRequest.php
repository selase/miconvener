<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class PublishPublicAttendeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:80', 'regex:/^[^<>\x00-\x1F\x7F]+$/u'],
            'consent' => ['required', 'accepted'],
            'adult' => ['required', 'accepted'],
            'operation_key' => ['required', 'uuid'],
            'revision' => ['nullable', 'uuid'],
            'image_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'display_name.regex' => 'Use a display name without markup or line breaks.',
            'consent.accepted' => 'Choose whether to show your profile publicly before publishing.',
            'adult.accepted' => 'Confirm that you are an adult sharing your own profile.',
            'operation_key.uuid' => 'Reload this profile editor before trying again.',
        ];
    }
}
