<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\StoreWrongNumberReportRequest;
use App\Models\Member;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Community\WrongNumberReports;
use Illuminate\Http\JsonResponse;

final class CommunityWrongNumberController extends Controller
{
    public function store(StoreWrongNumberReportRequest $request, string $tenantSlug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        $member = $request->filled('member_id') ? Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->findOrFail($request->validated('member_id')) : null;
        app(WrongNumberReports::class)->report($tenant, $request->session(), $request->string('note')->toString(), $member);

        return response()->json(['accepted' => true, 'message' => 'Your report has been sent for review.']);
    }
}
