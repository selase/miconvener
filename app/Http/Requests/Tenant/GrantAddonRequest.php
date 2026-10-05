<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Services\Billing\TenantAddonService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GrantAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('access-superadmin-dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'addon_key' => ['required', 'string', Rule::in(array_keys(TenantAddonService::CATALOG))],
            'packs' => ['required', 'integer', 'min:1', 'max:50'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'addon_key.in' => 'Choose an add-on from the list.',
            'reason.required' => 'Say why it is being given, so the organisation and the team can see it later.',
            'reason.min' => 'Say why it is being given, so the organisation and the team can see it later.',
        ];
    }
}
