<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\PermissionCeiling;
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

        if (! $target instanceof User) {
            return true;
        }

        if ($target->hasRole('Org Superadmin') && ! $this->canAssignOrgSuperadmin()) {
            return false;
        }

        // Editing someone is a way to demote them, so a person whose role
        // reaches beyond the editor's own is out of the editor's hands.
        $ceiling = app(PermissionCeiling::class);

        return $target->roles->every(fn (mixed $role): bool => $role instanceof Role && $ceiling->canGrantRole($this->user(), $role));
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

                    if (! app(PermissionCeiling::class)->canGrantRole($this->user(), $role)) {
                        $fail('You can only assign a role whose permissions you hold yourself.');
                    }

                    $target = $this->route('user');
                    if ($target instanceof User
                        && $target->is($this->user())
                        && ! $target->roles->contains('id', $role->id)
                    ) {
                        $fail('You cannot change your own role. Ask another administrator.');
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
