<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\ReviewMemberClaimRequest;
use App\Models\CommunityAccessClaim;
use App\Models\Member;
use App\Services\Community\MemberClaims;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class CommunityClaimReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('manage members');
        $tenant = app(TenantContext::class)->getTenant();
        $rows = CommunityAccessClaim::query()->where('tenant_id', $tenant->id)->latest()->paginate(25);

        return response()->json(['claims' => $rows->through(fn ($claim) => ['id' => $claim->id, 'claimant_name' => $claim->claimant_name,
            'phone' => $claim->phone, 'role' => $claim->role, 'status' => $claim->status, 'member_id' => $claim->member_id, 'reason' => $claim->reason, 'expires_at' => $claim->expires_at->toIso8601String()])]);
    }

    public function memberOptions(\App\Http\Requests\Tenant\Community\GroupMemberOptionsRequest $request): JsonResponse
    {
        $this->authorize('manage members');
        $tenant = app(TenantContext::class)->getTenant();
        $search = $request->validated('search', '');

        return response()->json(['members' => Member::query()->where('tenant_id', $tenant->id)
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match->where('name', 'ilike', '%'.$search.'%')->orWhere('email', 'ilike', '%'.$search.'%')->orWhere('phone', 'ilike', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')->paginate(30, ['id', 'name', 'email', 'phone', 'status'])]);
    }

    public function approve(ReviewMemberClaimRequest $request, string $subdomain, string $claim): JsonResponse
    {
        $model = $this->find($claim);
        $member = Member::query()->where('tenant_id', $model->tenant_id)->findOrFail($request->validated('member_id'));
        app(MemberClaims::class)->approve($model, $member, $request->user(), $request->string('method')->toString(), $request->string('reason')->toString());

        return response()->json(['approved' => true]);
    }

    public function reject(ReviewMemberClaimRequest $request, string $subdomain, string $claim): JsonResponse
    {
        app(MemberClaims::class)->reject($this->find($claim), $request->user(), $request->string('reason')->toString());

        return response()->json(['rejected' => true]);
    }

    private function find(string $id): CommunityAccessClaim
    {
        return CommunityAccessClaim::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->findOrFail($id);
    }
}
