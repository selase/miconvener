<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;

final class WithdrawInboxMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000'], 'confirmed' => ['required', 'accepted']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Say why this announcement should leave the inbox.', 'confirmed.accepted' => 'Confirm that this removes the in-app copy only.'];
    }
}
