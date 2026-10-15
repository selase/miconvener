<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateGroupMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage members') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'member_ids' => ['present', 'array', 'max:10000'],
            'member_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('landlord.members', 'id')->where('tenant_id', app(TenantContext::class)->getTenant()?->id)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['member_ids.*.exists' => 'Choose members from this organisation only.', 'member_ids.max' => 'Update at most 10,000 members at a time.'];
    }
}
