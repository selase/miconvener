<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Role;
use App\Services\Authorization\PermissionCeiling;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create user');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
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

                    if ($role->tenant_id === null && ! in_array($role->name, \App\Services\Tenancy\OrganisationMemberships::TENANT_ROLES, true)) {
                        $fail('The Superadmin role cannot be assigned within an organization.');
                    }

                    if ($role->isSystemRole() && $role->name === 'Org Superadmin' && ! $this->canAssignOrgSuperadmin()) {
                        $fail('Only an Org Superadmin can assign the Org Superadmin role.');
                    }

                    if (! app(PermissionCeiling::class)->canGrantRole($this->user(), $role)) {
                        $fail('You can only assign a role whose permissions you hold yourself.');
                    }
                },
            ],
            // Empty grants all events to Event Staff and none to Event Organisers.
            'event_ids' => ['sometimes', 'array'],
            'event_ids.*' => [
                'uuid',
                Rule::exists(\App\Models\Event::class, 'id')->where('tenant_id', app(TenantContext::class)->activeTenantId()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_ids.*.exists' => 'Choose events from this organisation.',
            'event_ids.*.uuid' => 'Choose events from this organisation.',
        ];
    }

    private function canAssignOrgSuperadmin(): bool
    {
        return $this->user()->isGlobalSuperAdmin() || $this->user()->hasRole('Org Superadmin');
    }
}
