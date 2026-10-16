<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FollowCommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['channel' => ['required', Rule::in(['email', 'sms'])], 'resume_marketing' => ['sometimes', 'boolean'], 'source_event_id' => ['nullable', 'uuid']];
    }

    public function messages(): array
    {
        return ['channel.required' => 'Choose the verified address where you want announcements.'];
    }
}
