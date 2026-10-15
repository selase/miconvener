<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raises a charge for a member of the organisation for the period containing
 * the given date.
 */
final class StoreDuesChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage members');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', Rule::exists('members', 'id')->where('tenant_id', app(TenantContext::class)->getTenant()?->id)],
            'period_start' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_id.required' => 'Choose the member to charge.',
            'member_id.exists' => 'That member does not belong to this organisation.',
            'period_start.required' => 'Choose the period to charge for.',
            'period_start.date' => 'Enter a valid date.',
        ];
    }
}
