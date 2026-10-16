<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\MemberCredentialRequest;
use App\Http\Requests\Attendee\StoreMemberClaimRequest;
use App\Models\CommunityAccessClaim;
use App\Models\CommunityAccessCredential;
use App\Models\Member;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Community\MemberAccess;
use App\Services\Community\MemberClaims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunityClaimController extends Controller
{
    public function __construct(private readonly MemberClaims $claims) {}

    public function store(StoreMemberClaimRequest $request, string $tenantSlug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        $claim = $this->claims->request($tenant, $request->session(), $request->string('name')->toString(), $request->string('role')->toString());

        return response()->json(['claim' => $this->payload($claim)], 201);
    }

    public function show(Request $request, string $tenantSlug, string $claim): JsonResponse
    {
        $model = $this->find($tenantSlug, $claim);
        abort_unless($this->claims->owns($request->session(), $model), 404);

        return response()->json(['claim' => $this->payload($model)]);
    }

    public function complete(Request $request, string $tenantSlug, string $claim): JsonResponse
    {
        $model = $this->find($tenantSlug, $claim);
        $credential = $this->claims->complete($request->session(), $model->id, (string) $request->session()->get('community_claim_capabilities.'.$model->id, ''));

        return response()->json($credential)->header('Cache-Control', 'private, no-store');
    }

    public function enrolPhone(Request $request, string $tenantSlug, string $member): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        abort_unless($tenant->communityEnabled(), 404);
        $model = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->findOrFail($member);

        return response()->json(app(MemberAccess::class)->enrolPhone($request->session(), $model))->header('Cache-Control', 'private, no-store');
    }

    public function recover(MemberCredentialRequest $request, string $tenantSlug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        abort_unless($tenant->communityEnabled(), 404);
        $selector = $request->string('selector')->toString();
        $credential = CommunityAccessCredential::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->find($selector);
        $established = $credential !== null && app(MemberAccess::class)->establish($request->session(), $selector, $request->string('secret')->toString());

        return response()->json(['established' => $established, 'message' => $established ? 'Membership access restored.' : 'Check your phone verification and private recovery key.'], $established ? 200 : 422)->header('Cache-Control', 'private, no-store');
    }

    private function find(string $slug, string $id): CommunityAccessClaim
    {
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        abort_unless($tenant->communityEnabled(), 404);

        return CommunityAccessClaim::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->findOrFail($id);
    }

    private function payload(CommunityAccessClaim $claim): array
    {
        return ['id' => $claim->id, 'claimant_name' => $claim->claimant_name, 'role' => $claim->role, 'status' => $claim->status,
            'expires_at' => $claim->expires_at->toIso8601String()];
    }
}
