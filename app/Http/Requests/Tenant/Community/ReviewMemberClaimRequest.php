<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewMemberClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage members') ?? false;
    }

    public function rules(): array
    {
        $approval = $this->routeIs('tenant.community.claims.approve');

        return ['member_id' => [$approval ? 'required' : 'nullable', 'uuid', Rule::exists('landlord.members', 'id')->where('tenant_id', app(TenantContext::class)->getTenant()?->id)],
            'method' => [$approval ? 'required' : 'nullable', Rule::in(['in_person', 'known_contact'])],
            'reason' => ['required', 'string', 'min:10', 'max:1000']];
    }

    public function messages(): array
    {
        return ['reason.min' => 'Explain the independent check in at least 10 characters.', 'member_id.exists' => 'Choose a member of this organisation.'];
    }
}
