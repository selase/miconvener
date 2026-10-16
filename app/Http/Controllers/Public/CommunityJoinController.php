<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\JoinCommunityRequest;
use App\Models\CommunityJoinRequest;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Community\MembershipJoining;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunityJoinController extends Controller
{
    public function __construct(private readonly MembershipJoining $joining) {}

    public function store(JoinCommunityRequest $request, string $tenantSlug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        try {
            $row = $this->joining->submit($tenant, $request->session(), $request->validated(), $request->string('request_key')->toString());
        } catch (DomainException) {
            return response()->json(['message' => 'Joining is temporarily unavailable. Please contact the organisation.'], 422);
        }
        abort_unless($this->joining->owns($request->session(), $row), 404);

        return response()->json(['request' => $this->payload($row)], 201);
    }

    public function show(Request $request, string $tenantSlug, string $join): JsonResponse
    {
        $row = $this->find($tenantSlug, $join);
        abort_unless($this->joining->owns($request->session(), $row), 404);

        return response()->json(['request' => $this->payload($row)]);
    }

    public function credential(Request $request, string $tenantSlug, string $join): JsonResponse
    {
        return response()->json($this->joining->credential($request->session(), $this->find($tenantSlug, $join)))->header('Cache-Control', 'private, no-store');
    }

    private function find(string $slug, string $id): CommunityJoinRequest
    {
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        abort_unless($tenant->communityEnabled(), 404);

        return CommunityJoinRequest::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->findOrFail($id);
    }

    /** @return array<string,mixed> */
    private function payload(CommunityJoinRequest $row): array
    {
        return ['id' => $row->id, 'name' => $row->submitted_name, 'status' => $row->status === 'review_required' ? 'pending' : $row->status,
            'can_save_recovery' => $row->channel === 'sms' && $row->member_access_version !== null && $row->credential_issued_at === null && $row->status === 'approved'];
    }
}
