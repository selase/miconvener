<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\ReviewWrongNumberReportRequest;
use App\Models\CommunityAccessCredential;
use App\Models\CommunityWrongNumberReport;
use App\Models\Member;
use App\Services\Community\MemberAccess;
use App\Services\Community\WrongNumberReports;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class CommunityContactReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('manage members');
        $rows = CommunityWrongNumberReport::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->latest()->paginate(25);

        return response()->json(['reports' => $rows->through(fn ($row) => ['id' => $row->id, 'phone' => $row->phone, 'note' => $row->note, 'status' => $row->status, 'reason' => $row->reason])]);
    }

    public function review(ReviewWrongNumberReportRequest $request, string $subdomain, string $report): JsonResponse
    {
        $row = CommunityWrongNumberReport::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->findOrFail($report);
        app(WrongNumberReports::class)->review($row, $request->user(), $request->boolean('confirmed'), $request->string('reason')->toString());

        return response()->json(['reviewed' => true]);
    }

    public function resolve(ReviewWrongNumberReportRequest $request, string $subdomain, string $report): JsonResponse
    {
        $row = CommunityWrongNumberReport::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->findOrFail($report);
        app(WrongNumberReports::class)->resolve($row, $request->user(), $request->string('method')->toString(), $request->string('reason')->toString());

        return response()->json(['resolved' => true]);
    }

    public function revokeCredential(ReviewWrongNumberReportRequest $request, string $subdomain, string $credential): JsonResponse
    {
        $row = CommunityAccessCredential::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->findOrFail($credential);
        app(MemberAccess::class)->revokeCredential($row, $request->user(), $request->string('reason')->toString());

        return response()->json(['revoked' => true]);
    }

    public function revoke(ReviewWrongNumberReportRequest $request, string $subdomain, string $member): JsonResponse
    {
        $model = Member::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->findOrFail($member);
        app(MemberAccess::class)->revoke($model, $request->user(), $request->string('reason')->toString());

        return response()->json(['revoked' => true]);
    }

    public function credentials(string $subdomain, string $member): JsonResponse
    {
        $this->authorize('manage members');
        $model = Member::query()->where('tenant_id', app(TenantContext::class)->getTenant()->id)->findOrFail($member);
        $rows = CommunityAccessCredential::query()->where('tenant_id', $model->tenant_id)->where('member_id', $model->id)->get();

        return response()->json(['credentials' => $rows->map(fn ($row) => ['id' => $row->id, 'phone' => $row->phone, 'role' => $row->role,
            'approved_at' => $row->approved_at?->toIso8601String(), 'revoked_at' => $row->revoked_at?->toIso8601String(), 'suspended_at' => $row->suspended_at?->toIso8601String()])]);
    }
}
