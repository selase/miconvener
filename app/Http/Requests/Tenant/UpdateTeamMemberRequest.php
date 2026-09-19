<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;

final class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('update user')) {
            return false;
        }

        $target = $this->route('user');

        return ! ($target instanceof User
            && $target->hasRole('Org Superadmin')
            && ! $this->canAssignOrgSuperadmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone_no' => ['required', (new Phone)->country(['GH', 'AUTO'])],
            'role' => [
                'required',
                'exists:roles,id',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // findById() throws when the id is unknown, so the guard below never ran
                    // and a bad id 500ed instead of failing validation.
                    $role = Role::query()->whereKey($value)->first();
                    if (! $role) {
                        $fail('The selected role is invalid.');

                        return;
                    }

                    $tenantId = app(TenantContext::class)->activeTenantId();
                    if ($role->tenant_id !== null && $role->tenant_id !== $tenantId) {
                        $fail('You do not have permission to assign this role.');
                    }

                    if ($role->isSystemRole() && $role->name === 'Superadmin') {
                        $fail('The Superadmin role cannot be assigned within an organization.');
                    }

                    if ($role->isSystemRole() && $role->name === 'Org Superadmin' && ! $this->canAssignOrgSuperadmin()) {
                        $fail('Only an Org Superadmin can assign the Org Superadmin role.');
                    }
                },
            ],
            'status' => ['required', 'string'],
        ];
    }

    private function canAssignOrgSuperadmin(): bool
    {
        return $this->user()->isGlobalSuperAdmin() || $this->user()->hasRole('Org Superadmin');
    }
}
