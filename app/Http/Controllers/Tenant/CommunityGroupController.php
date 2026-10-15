<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\StoreGroupRequest;
use App\Http\Requests\Tenant\Community\UpdateGroupMembersRequest;
use App\Models\CommunityGroup;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class CommunityGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('manage members');
        $tenant = app(TenantContext::class)->getTenant();

        return response()->json(['groups' => CommunityGroup::query()->where('tenant_id', $tenant->id)->withCount('members')->orderBy('name')->get()]);
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $group = CommunityGroup::query()->create(['tenant_id' => $tenant->id] + $request->validated());

        return response()->json(['group' => $group], 201);
    }

    public function update(StoreGroupRequest $request, string $subdomain, string $group): JsonResponse
    {
        $model = $this->findGroup($group);
        $this->assertEditable($model);
        $model->update($request->validated());

        return response()->json(['group' => $model]);
    }

    public function members(UpdateGroupMembersRequest $request, string $subdomain, string $group): JsonResponse
    {
        $model = $this->findGroup($group);
        $this->assertEditable($model);
        $model->members()->syncWithPivotValues($request->validated('member_ids'), ['tenant_id' => $model->tenant_id]);

        return response()->json(['members_count' => $model->members()->count()]);
    }

    public function archive(string $subdomain, string $group): JsonResponse
    {
        $this->authorize('manage members');
        $model = $this->findGroup($group);
        $model->update(['archived_at' => $model->archived_at ?? now()]);

        return response()->json(['group' => $model]);
    }

    private function findGroup(string $id): CommunityGroup
    {
        return CommunityGroup::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->findOrFail($id);
    }

    private function assertEditable(CommunityGroup $group): void
    {
        if ($group->archived_at !== null) {
            throw ValidationException::withMessages(['group' => 'This group is archived. Its history is kept.']);
        }
    }
}
