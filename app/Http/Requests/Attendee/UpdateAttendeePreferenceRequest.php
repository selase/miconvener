<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAttendeePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['channel' => ['required', 'in:email,sms'], 'marketing_enabled' => ['required', 'boolean'], 'resume_marketing' => ['required_if:marketing_enabled,true', 'accepted_if:marketing_enabled,true'], 'address' => ['prohibited'], 'member_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['resume_marketing.accepted_if' => 'Confirm you want to receive announcements again.', 'address.prohibited' => 'Preferences use your verified contact only.'];
    }
}
