<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enum\TenantStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class UpdateTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->user()->can('update tenant');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:50'],
            'phone_number' => ['required', 'string', 'max:16'],
            'email' => ['required', 'email'],
            'country' => ['required', 'string'],
            'city' => ['required', 'string'],
            'state_or_region' => ['required', 'string'],
            'zipcode' => ['required', 'string'],
            'status' => ['required', new Enum(TenantStatusEnum::class)],
            'address' => ['required', 'string'],
            'subdomain' => ['required', 'string', 'alpha', Rule::exists('tenants', 'slug')],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,svg'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'isolation_mode' => ['required', Rule::in(['shared', 'db_per_tenant', 'byo'])],
            'db_driver' => ['required', Rule::in(['mysql', 'pgsql', 'sqlite'])],
            'db_secret_ref' => ['nullable', 'string', 'max:255'],
            'allowed_ips' => ['nullable', 'string'],
            'llm_models_whitelist' => ['nullable', 'array'],
            'custom_llm_limit' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
