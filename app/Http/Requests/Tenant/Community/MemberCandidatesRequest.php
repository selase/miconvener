<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MemberCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage members') ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'email' => ['nullable', 'string', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:32'],
            'ignore_id' => ['nullable', 'uuid', Rule::exists('landlord.members', 'id')->where('tenant_id', app(TenantContext::class)->getTenant()?->id)]];
    }

    public function messages(): array
    {
        return ['ignore_id.exists' => 'Choose a member from this organisation.'];
    }
}
