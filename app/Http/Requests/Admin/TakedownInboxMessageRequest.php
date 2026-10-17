<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class TakedownInboxMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000'], 'confirmed' => ['required', 'accepted']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Say why this announcement should leave the inbox.', 'confirmed.accepted' => 'Confirm that this removes the in-app copy only.'];
    }
}
