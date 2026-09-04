<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Role;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;

final class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update user');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
            'phone_no' => ['required', (new Phone)->country(['GH', 'AUTO'])],
            'role' => [
                'required',
                'exists:roles,id',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $role = Role::findById($value);
                    if (! $role) {
                        $fail('The selected role is invalid.');

                        return;
                    }

                    $tenantId = app(TenantContext::class)->activeTenantId();
                    if ($role->tenant_id !== null && $role->tenant_id !== $tenantId) {
                        $fail('You do not have permission to assign this role.');
                    }

                    if ($role->isSystemRole() && $role->name === 'Superadmin' && ! $this->user()->isGlobalSuperAdmin()) {
                        $fail('You are not authorized to assign the Superadmin role.');
                    }
                },
            ],
            'status' => ['required', 'string'],
        ];
    }
}
