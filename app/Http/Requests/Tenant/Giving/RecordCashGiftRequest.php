<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordCashGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage giving') && $this->user()->can('read finance');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $individual = Rule::prohibitedIf($this->input('source') === 'bulk_cash');

        return [
            'submission_key' => ['required', 'uuid'], 'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'currency' => ['required', 'string', 'size:3'], 'source' => ['required', Rule::in(['recorded_cash', 'bulk_cash'])],
            'collected_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'payer_name' => [$individual, 'nullable', 'string', 'max:160'],
            'payer_email' => [$individual, 'nullable', 'email', 'max:254'],
            'payer_phone' => [$individual, 'nullable', 'string', 'max:40'],
            'member_id' => [$individual, 'nullable', 'uuid'], 'reuse_giver_key' => [$individual, 'nullable', 'string', 'max:100'],
            'payer_is_member' => [$individual, 'sometimes', 'boolean'], 'identity_confirmed' => ['sometimes', 'boolean'], 'is_anonymous' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['amount.required' => 'Enter the collected amount.', 'payer_email.prohibited' => 'Offering batches do not name an individual giver.'];
    }
}
