<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;

final class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->user()->can('update user');
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
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
            'phone_no' => ['required', (new Phone)->country(['GH', 'AUTO'])],
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
            'tenant_id' => ['nullable', 'string'],
        ];
    }
}
