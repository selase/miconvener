<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Giving\CorrectCashGiftRequest;
use App\Http\Requests\Tenant\Giving\RecordCashGiftRequest;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Services\Giving\GivingCash;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class GivingGiftController extends Controller
{
    public function memberOptions(\App\Http\Requests\Tenant\Giving\SearchGivingMemberRequest $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless(app(\App\Services\Giving\GivingRollout::class)->enabled($tenant), 404);
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->validated('q'));
        $members = \App\Models\Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'ilike', '%'.$term.'%')->orderBy('name')->orderBy('id')->limit(15)->get(['id', 'name']);

        return response()->json(['members' => $members])->header('Cache-Control', 'private, no-store');
    }

    public function store(RecordCashGiftRequest $request, string $subdomain, string $fund, GivingCash $cash): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $model = GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($fund);
        $input = $request->validated();
        $input['amount'] = (int) $input['amount'];

        return response()->json(['gift' => $cash->record($model, $request->user(), $input, $input['submission_key'])], 201);
    }

    public function correct(CorrectCashGiftRequest $request, string $subdomain, string $gift, GivingCash $cash): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $model = GivingGift::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($gift);

        return response()->json(['adjustment' => $cash->correct($model, $request->user(), (int) $request->validated('principal'), $request->validated('reason'), $request->validated('operation_key'))], 201);
    }
}
