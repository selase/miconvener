<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TenantFeature;
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
        $tenant = app(TenantContext::class)->getTenant()
            ?? $this->resource->tenants()->first();

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
            'roles' => $this->getRoleNames()->toArray(),
            'permissions' => $this->getAllPermissions()->pluck('name')->toArray(),
            'features' => $tenant ? TenantFeature::where('tenant_id', $tenant->id)
                ->where('enabled', true)
                ->pluck('feature_key')
                ->toArray() : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
