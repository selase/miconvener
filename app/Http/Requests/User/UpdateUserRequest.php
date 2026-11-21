<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\NormalisesPhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;

final class UpdateUserRequest extends FormRequest
{
    use NormalisesPhoneNumber { prepareForValidation as private normalizeProfilePhone; }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('access-superadmin-dashboard') && $this->user()->can('update user');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
            'phone_no' => ['required', (new Phone)->international()->country('GH')],
            'roles' => [
                'bail',
                'present',
                'array',
                function ($attribute, $value, $fail): void {
                    foreach ($value as $roleId) {
                        if (! is_int($roleId) && (! is_string($roleId) || ! ctype_digit($roleId))) {
                            $fail('The selected role is invalid.');

                            return;
                        }
                        // findById() throws when the id is unknown, so the guard below
                        // never ran and a bad id 500ed instead of failing validation.
                        $role = \App\Models\Role::query()->whereKey($roleId)->first();
                        if (! $role) {
                            $fail('The selected role is invalid.');

                            return;
                        }

                        // Check if role belongs to this tenant or is global
                        $tenantId = $this->route('user')->tenant_id;
                        if ($role->tenant_id !== null && $role->tenant_id !== $tenantId) {
                            $fail('You do not have permission to assign this role.');
                        }

                        if ($role->isSystemRole() && $role->name === 'Superadmin' && ! auth()->user()->isGlobalSuperAdmin()) {
                            $fail('You are not authorized to assign the Superadmin role.');
                        }
                    }
                },
            ],
            'status' => ['required', Rule::in(User::STATUSES)],
            'tenant_id' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeProfilePhone();
        if (! $this->has('roles')) {
            $this->merge(['roles' => []]);
        }
    }
}
