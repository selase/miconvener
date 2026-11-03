<?php

declare(strict_types=1);

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;

final class ToggleAttendeeProfilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['enabled.required' => 'Choose whether to offer public attendee profiles.'];
    }
}
