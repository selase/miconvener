<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TenantFeature;
use App\Services\Tenancy\OrganisationMemberships;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = app(TenantContext::class)->getTenant();
        if ($tenant !== null && ! $this->resource->isGlobalSuperAdmin() && ! app(OrganisationMemberships::class)->isActive($this->resource, $tenant)) {
            $tenant = null;
        } elseif ($tenant === null) {
            $tenant = $this->resource->tenants()->first();
        }

        $access = $tenant
            ? app(OrganisationMemberships::class)->inTenant($tenant, [$this->resource], fn (): array => [
                'roles' => $this->resource->getRoleNames()->all(),
                'permissions' => $this->resource->getAllPermissions()->pluck('name')->all(),
            ])
            : ['roles' => [], 'permissions' => []];

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'photo' => $this->whenHas('photo'),
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'roles' => $access['roles'],
            'permissions' => $access['permissions'],
            'features' => $tenant ? TenantFeature::where('tenant_id', $tenant->id)
                ->where('enabled', true)
                ->pluck('feature_key')
                ->toArray() : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
