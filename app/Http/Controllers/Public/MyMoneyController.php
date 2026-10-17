<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\MyMoneyRequest;
use App\Models\Tenant;
use App\Services\Community\CommunityHistory;
use App\Services\Community\MyMoneySummary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class MyMoneyController extends Controller
{
    public function index(MyMoneyRequest $request, MyMoneySummary $summary): JsonResponse
    {
        return response()->json($summary->for($request->session(), $request->validated()))->header('Cache-Control', 'private, no-store');
    }

    public function statement(MyMoneyRequest $request, string $tenantSlug, string $member, CommunityHistory $history): Response
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->where('status', '!=', \App\Enum\TenantStatusEnum::BANNED)->firstOrFail();
        $model = $history->member($tenant, $request->session(), $member);
        $dates = $request->validated();
        $charges = $model->charges()->where('tenant_id', $tenant->id)->whereDate('period_start', '>=', $dates['from'])->whereDate('period_start', '<=', $dates['to'])->orderBy('period_start')->get();

        return Pdf::loadView('pdf.community-member-statement', ['member' => $model, 'tenant' => $tenant, 'charges' => $charges, 'from' => $dates['from'], 'to' => $dates['to']])->setPaper('a4')->download('dues-statement.pdf')->header('Cache-Control','private, no-store');
    }
}
