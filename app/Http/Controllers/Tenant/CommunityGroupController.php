<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\GroupMemberOptionsRequest;
use App\Http\Requests\Tenant\Community\StoreGroupRequest;
use App\Http\Requests\Tenant\Community\UpdateGroupMembersRequest;
use App\Models\CommunityGroup;
use App\Models\Member;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class CommunityGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('manage members');
        $tenant = app(TenantContext::class)->getTenant();

        $groups = CommunityGroup::query()->where('tenant_id', $tenant->id)->withCount('members')->orderBy('name')->paginate(30);

        return response()->json(['groups' => $groups->items(), 'next_page_url' => $groups->nextPageUrl()]);
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

        return $model->getConnection()->transaction(function () use ($model, $request): JsonResponse {
            $model = CommunityGroup::query()->lockForUpdate()->findOrFail($model->id);
            $this->assertEditable($model);
            $ids = $request->validated('member_ids');
            if ($request->isMethod('PATCH')) {
                if ($request->validated('action') === 'attach') {
                    $model->members()->syncWithoutDetaching(array_fill_keys($ids, ['tenant_id' => $model->tenant_id]));
                } else {
                    $model->members()->detach($ids);
                }
            } else {
                $model->members()->syncWithPivotValues($ids, ['tenant_id' => $model->tenant_id]);
            }

            return response()->json(['members_count' => $model->members()->count()]);
        });
    }

    public function memberOptions(GroupMemberOptionsRequest $request, string $subdomain, string $group): JsonResponse
    {
        $this->authorize('manage members');
        $model = $this->findGroup($group);
        $search = (string) $request->validated('search', '');
        $members = Member::query()->where('tenant_id', $model->tenant_id)
            ->select(['id', 'name', 'status'])->withExists(['groups as selected' => fn ($query) => $query->where('community_groups.id', $model->id)])
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))->orderBy('name')->paginate(30)->withQueryString();

        return response()->json(['members' => $members]);
    }

    public function archive(string $subdomain, string $group): JsonResponse
    {
        $this->authorize('manage members');
        $model = $this->findGroup($group);

        return $model->getConnection()->transaction(function () use ($model): JsonResponse {
            $model = CommunityGroup::query()->lockForUpdate()->findOrFail($model->id);
            $model->update(['archived_at' => $model->archived_at ?? now()]);

            return response()->json(['group' => $model]);
        });
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
