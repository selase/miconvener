<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\TenantPaymentGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePaymentGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage payment settings') ?? false;
    }

    /**
     * The secret fields are never sent back to the browser, so a blank one
     * means "keep what is saved". A secret key is only required the first
     * time a provider is connected.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:stripe,paystack'],
            'api_key' => [Rule::requiredIf(fn (): bool => $this->existingGateway() === null), 'nullable', 'string'],
            'public_key' => ['nullable', 'string'],
            'webhook_secret' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_key.required' => 'Enter the secret key to connect this provider.',
        ];
    }

    public function existingGateway(): ?TenantPaymentGateway
    {
        $provider = $this->input('provider');

        if (! is_string($provider)) {
            return null;
        }

        return TenantPaymentGateway::query()
            ->where('tenant_id', app(TenantContext::class)->getTenant()->id)
            ->where('provider', $provider)
            ->first();
    }
}
