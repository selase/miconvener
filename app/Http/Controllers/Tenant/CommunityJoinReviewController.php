<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\ReviewCommunityJoinRequest;
use App\Http\Requests\Tenant\Community\UpdateJoinSettingsRequest;
use App\Models\CommunityJoinRequest;
use App\Services\Community\JoinSettings;
use App\Services\Community\MembershipJoining;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;

final class CommunityJoinReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('manage members');
        $tenant = app(TenantContext::class)->getTenant();

        return response()->json(['requests' => CommunityJoinRequest::query()->where('tenant_id', $tenant->id)->latest()->paginate(25)->through(fn (CommunityJoinRequest $row): array => [
            'id' => $row->id, 'name' => $row->submitted_name, 'channel' => $row->channel, 'address' => $row->address, 'status' => $row->status, 'member_id' => $row->member_id,
            'membership_type_id' => $row->membership_type_id, 'submitted_at' => $row->submitted_at->toIso8601String(), 'reason' => $row->review_reason])]);
    }

    public function approve(ReviewCommunityJoinRequest $request, string $subdomain, string $join): JsonResponse
    {
        try {
            app(MembershipJoining::class)->approve($this->find($join), $request->user(), $request->boolean('create_distinct'), $request->validated('reason'), $request->validated('membership_type_id'));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['approved' => true]);
    }

    public function reject(ReviewCommunityJoinRequest $request, string $subdomain, string $join): JsonResponse
    {
        try {
            app(MembershipJoining::class)->reject($this->find($join), $request->user(), $request->string('reason')->toString());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['rejected' => true]);
    }

    public function settings(UpdateJoinSettingsRequest $request): JsonResponse
    {
        app(JoinSettings::class)->update(app(TenantContext::class)->getTenant(), $request->user(), $request->validated());

        return response()->json(['updated' => true]);
    }

    private function find(string $id): CommunityJoinRequest
    {
        return CommunityJoinRequest::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->findOrFail($id);
    }
}
