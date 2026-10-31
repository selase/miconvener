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
            'public_profile' => ['sometimes', 'array:description,contact_email,website,instagram,x,facebook,linkedin,youtube'],
            'public_profile.description' => ['nullable', 'string', 'max:500'],
            'public_profile.contact_email' => ['nullable', 'email', 'max:255'],
            'public_profile.website' => ['nullable', 'url:http,https', 'max:2048'],
            'public_profile.instagram' => ['nullable', 'url:http,https', 'max:2048'],
            'public_profile.x' => ['nullable', 'url:http,https', 'max:2048'],
            'public_profile.facebook' => ['nullable', 'url:http,https', 'max:2048'],
            'public_profile.linkedin' => ['nullable', 'url:http,https', 'max:2048'],
            'public_profile.youtube' => ['nullable', 'url:http,https', 'max:2048'],
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
            'public_profile.array' => 'Use only the supported public profile fields.',
            'public_profile.*.url' => 'Enter a complete public URL starting with https:// or http://.',
            'public_profile.contact_email.email' => 'Enter a valid email address to publish for event enquiries.',
        ];
    }
}
