<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Tenancy\EntitlementService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DuplicateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create role');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->getTenant();
        $allowedPermissions = app(EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);
        $assignablePermissions = array_intersect(Permission::TENANT_SAFE, $allowedPermissions);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->where(fn($query) => $query->where('tenant_id', $tenant->id)),
                Rule::notIn(Role::SYSTEM_ROLES),
            ],
            'permissions' => ['required', 'array'],
            'permissions.*' => [
                'string',
                Rule::in($assignablePermissions),
            ],
            'source_role_id' => [
                'required',
                'integer',
                'exists:roles,id',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $source = Role::find($value);
                    if (! $source || $source->tenant_id !== null) {
                        $fail('The source role must be a platform system role.');
                    }
                },
            ],
        ];
    }
}
