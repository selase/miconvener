<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Giving\RefundGivingGiftRequest;
use App\Services\Giving\GivingRefunds;
use App\Services\Giving\GivingSources;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class GivingRefundController extends Controller
{
    public function preview(RefundGivingGiftRequest $request, string $subdomain, string $type, string $source, GivingSources $sources, GivingRefunds $refunds): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $model = $sources->find($tenant, $type, $source);

        return response()->json($refunds->preview($model, $request->user(), (int) $request->validated('principal')))->header('Cache-Control', 'private, no-store');
    }

    public function store(RefundGivingGiftRequest $request, string $subdomain, string $type, string $source, GivingSources $sources, GivingRefunds $refunds): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $model = $sources->find($tenant, $type, $source);
        $adjustment = $refunds->request($model, $request->user(), (int) $request->validated('principal'), $request->validated('reason'), $request->validated('operation_key'));

        return response()->json(['adjustment' => $adjustment], 201)->header('Cache-Control', 'private, no-store');
    }

    public function reconcile(\App\Http\Requests\Tenant\Giving\ReconcileGivingRefundRequest $request, string $subdomain, string $adjustment, GivingRefunds $refunds): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $model = \App\Models\GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('kind', 'refund')->findOrFail($adjustment);
        $refunds->reconcile($model, $request->validated('provider_refund_reference'));

        return response()->json(['adjustment' => $model->refresh()])->header('Cache-Control', 'private, no-store');
    }
}
