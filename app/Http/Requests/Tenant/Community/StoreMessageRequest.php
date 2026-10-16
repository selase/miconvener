<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send community messages') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:10000'],
            'channels' => ['required', 'array', 'min:1', 'max:2'], 'channels.*' => ['required', Rule::in(['email', 'sms']), 'distinct'],
            'audience' => ['required', 'array:kind,status,group_ids'],
            'audience.kind' => ['required', Rule::in(['all_members', 'status', 'groups', 'arrears'])],
            'audience.status' => ['sometimes', Rule::in(['pending', 'active', 'lapsed', 'exited'])],
            'audience.group_ids' => ['required_if:audience.kind,groups', 'array', 'min:1', 'max:100'],
            'audience.group_ids.*' => ['required', 'uuid', 'distinct'],
            'idempotency_key' => ['required', 'uuid'], 'mode' => ['sometimes', Rule::in(['draft', 'send'])],
            'scheduled_at' => ['nullable', 'string', 'max:35'],
            'confirm_authority' => $this->input('mode', 'send') === 'draft' ? ['sometimes', 'accepted'] : ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirm_authority.accepted' => 'Confirm you are authorised to contact this audience.', 'audience.kind.in' => 'Choose a Community audience.', 'channels.*.in' => 'Choose email or SMS.', 'audience.group_ids.required_if' => 'Choose at least one active group.'];
    }
}
