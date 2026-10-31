<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage members') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->getTenant();

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('landlord.community_groups', 'name')->where('tenant_id', $tenant?->id)->ignore($this->route('group'))],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => 'A group with this name already exists.', 'name.required' => 'Give this group a name.'];
    }
}
