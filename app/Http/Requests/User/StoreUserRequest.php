<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;

final class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // The provided code snippet for the authorize method appears to be a test assertion
        // and is not suitable for a FormRequest's authorization logic.
        // Retaining the original authorization logic.
        return $this->user()->can('create user');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'phone_no' => ['required', (new Phone)->country(['GH', 'AUTO'])],
            'role' => ['nullable', 'exists:roles,id'],
            'roles' => [
                'required',
                'array',
                function ($attribute, $value, $fail): void {
                    foreach ($value as $roleId) {
                        $role = \App\Models\Role::findById($roleId);
                        if (! $role) {
                            $fail('The selected role is invalid.');

                            return;
                        }

                        // Check if role belongs to this tenant or is global
                        $tenantId = app(\App\Services\Tenancy\TenantContext::class)->activeTenantId();
                        if ($role->tenant_id !== null && $role->tenant_id !== $tenantId) {
                            $fail('You do not have permission to assign this role.');
                        }

                        if ($role->isSystemRole() && $role->name === 'Superadmin' && ! auth()->user()->isGlobalSuperAdmin()) {
                            $fail('You are not authorized to assign the Superadmin role.');
                        }
                    }
                },
            ],
            'status' => ['required', 'string'],
            'photo' => ['nullable', 'image', 'mimes:png,jpg,gif,svg', 'max:2048'],
            'tenant_id' => ['nullable', 'string'],
        ];
    }
}
