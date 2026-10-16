<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class JoinCommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'channel' => ['required', Rule::in(['email', 'sms'])], 'request_key' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return ['name.required' => 'Enter your name.', 'request_key.uuid' => 'Refresh the joining form and try again.'];
    }
}
