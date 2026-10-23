<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Store;

use App\Enum\LedgerCategoryEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Store\FinalizeStorePayoutRequest;
use App\Http\Requests\Tenant\Store\StorePayoutRequest;
use App\Http\Requests\Tenant\Store\StoreRefundRequest;
use App\Models\EventPayout;
use App\Models\StoreOrder;
use App\Models\StoreRefund;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\StorePayouts;
use App\Services\Marketplace\StoreRefunds;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class StoreFinanceController extends Controller
{
    public function refund(StoreRefundRequest $request, string $subdomain, string $order, StoreRefunds $refunds): JsonResponse
    {
        $tenant = $this->tenant();
        $owned = StoreOrder::query()->where('tenant_id', $tenant->id)->findOrFail($order);
        $data = $request->validated();
        $refund = $refunds->request($owned, $request->user(), $data['operation_key'], $data['reason'], $data['observation_id'] ?? null);

        return $this->refundResponse($refund);
    }

    public function cancelFree(StoreRefundRequest $request, string $subdomain, string $order, StoreRefunds $refunds): JsonResponse
    {
        $owned = StoreOrder::query()->where('tenant_id', $this->tenant()->id)->findOrFail($order);
        $data = $request->validated();

        return $this->refundResponse($refunds->cancelFree($owned, $request->user(), $data['operation_key'], $data['reason']));
    }

    public function reconcileRefund(Request $request, string $subdomain, string $refund, StoreRefunds $refunds): JsonResponse
    {
        $tenant = $this->authorizeFinance($request->user());
        $owned = StoreRefund::query()->where('tenant_id', $tenant->id)->findOrFail($refund);

        return $this->refundResponse($refunds->reconcile($owned));
    }

    public function reserve(StorePayoutRequest $request, StorePayouts $payouts): JsonResponse
    {
        $data = $request->validated();
        $data['amount'] = (int) $data['amount'];
        $payout = $payouts->reserve($this->tenant(), $request->user(), $data);

        return response()->json(['payout_id' => $payout->id, 'status' => $payout->status])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, string $subdomain, string $payout, StorePayouts $payouts): JsonResponse
    {
        $tenant = $this->authorizeFinance($request->user());
        $result = $payouts->send($this->payout($tenant, $payout));

        return response()->json(Arr::except($result, 'http_status'), $result['http_status'])->header('Cache-Control', 'private, no-store');
    }

    public function finalize(FinalizeStorePayoutRequest $request, string $subdomain, string $payout, StorePayouts $payouts): JsonResponse
    {
        $tenant = $this->authorizeFinance($request->user());
        $result = $payouts->finalize($this->payout($tenant, $payout), $request->validated('otp'));

        return response()->json(Arr::except($result, 'http_status'), $result['http_status'])->header('Cache-Control', 'private, no-store');
    }

    public function reconcilePayout(Request $request, string $subdomain, string $payout, StorePayouts $payouts): JsonResponse
    {
        $tenant = $this->authorizeFinance($request->user());
        $updated = $payouts->reconcile($this->payout($tenant, $payout));

        return response()->json(['payout_id' => $updated->id, 'status' => $updated->status, 'message' => $updated->failure_reason])->header('Cache-Control', 'private, no-store');
    }

    private function payout(Tenant $tenant, string $id): EventPayout
    {
        return EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Commerce)->whereNull('event_id')->findOrFail($id);
    }

    private function authorizeFinance(User $actor): Tenant
    {
        $tenant = $this->tenant();
        abort_unless(User::query()->whereKey($actor->id)->where('tenant_id', $tenant->id)->exists() || $actor->tenants()->whereKey($tenant->id)->exists(), 403);
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            abort_unless(Gate::forUser($actor->fresh())->allows('manage store finance'), 403);
        } finally {
            setPermissionsTeamId($team);
        }

        return $tenant;
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(403);
    }

    private function refundResponse(StoreRefund $refund): JsonResponse
    {
        return response()->json(['refund_id' => $refund->id, 'status' => $refund->status, 'message' => $refund->review_reason])->header('Cache-Control', 'private, no-store');
    }
}
