<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Services\Tenancy\FeatureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOrgSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage organization settings') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->getTenant();

        return [
            'name' => ['required', 'string', 'min:3', 'max:50'],
            'email' => ['required', 'email'],
            'phone_number' => ['required', 'string', 'max:16'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,svg'],
            'primary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'require_2fa' => ['nullable', 'boolean'],
            'custom_domain' => [
                Rule::prohibitedIf(! $tenant->featureEnabled(FeatureService::FEATURE_CUSTOM_DOMAINS)),
                'nullable',
                'string',
                'max:255',
                Rule::unique('tenants', 'custom_domain')->ignore($tenant->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_domain.prohibited' => 'Your current plan does not support custom domains.',
        ];
    }
}
