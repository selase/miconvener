<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class MarkInboxReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['context' => ['required', 'string', 'max:100'], 'reader_key' => ['prohibited'], 'member_id' => ['prohibited'], 'email' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['context.required' => 'Choose the message context to mark read.', 'reader_key.prohibited' => 'Read state uses your current verified access only.', 'member_id.prohibited' => 'Read state uses your current verified access only.'];
    }
}
