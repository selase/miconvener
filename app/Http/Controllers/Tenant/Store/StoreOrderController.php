<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Store;

use App\Enum\LedgerCategoryEnum;
use App\Http\Controllers\Controller;
use App\Models\EventPayout;
use App\Models\StoreOrder;
use App\Models\StoreRefund;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\StoreBalance;
use App\Services\Marketplace\StoreOrderPayload;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class StoreOrderController extends Controller
{
    public function index(Request $request, StoreOrderPayload $payload): Response
    {
        $tenant = $this->authorizeSeller($request->user(), 'manage store orders');
        $orders = StoreOrder::query()->where('tenant_id', $tenant->id)->where('state', '<>', 'quoted')->with(['lines', 'tenant.features', 'receipt.outbox', 'attempts'])->latest()->paginate(20);
        $finance = Gate::forUser($request->user()->fresh())->allows('manage store finance');
        $orders->through(function (StoreOrder $order) use ($payload, $finance): array {
            $captures = $finance ? \App\Models\StorePaymentObservation::query()->where('order_id', $order->id)->whereNotNull('ledger_transaction_id')
                ->orderByDesc('observation_version')->get()->unique('attempt_id')->values()->map(fn (\App\Models\StorePaymentObservation $capture): array => ['id' => $capture->id, 'amount_pesewas' => $capture->amount_pesewas, 'state' => $capture->processing_state, 'reason' => $capture->review_reason])->all() : [];

            return [...$payload->buyer($order), 'buyer_email' => $order->buyer_email, 'review_reason' => $order->review_reason, 'captures' => $captures,
                'can_reconcile' => $finance,
                'can_refund' => $finance && (($order->payment_state === 'paid' && $order->fulfilment_state !== 'collected') || collect($captures)->contains('state', 'review')),
                'can_cancel_free' => $finance && $order->payment_state === 'not_required' && $order->fulfilment_state !== 'collected'];
        });

        return $this->respond($request, ['orders' => $orders, 'finance' => false]);
    }

    public function finance(Request $request, StoreBalance $balance): Response
    {
        $tenant = $this->authorizeSeller($request->user(), 'manage store finance');
        $payouts = EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Commerce)->latest()->paginate(20, ['*'], 'payout_page');
        $refunds = StoreRefund::query()->where('tenant_id', $tenant->id)->latest()->paginate(20, ['*'], 'refund_page');

        return $this->respond($request, ['finance' => true, 'available_pesewas' => $balance->available($tenant),
            'accounts' => \App\Models\TenantPayoutAccount::query()->where('tenant_id', $tenant->id)->where('is_verified', true)->get()->map(fn (\App\Models\TenantPayoutAccount $account): array => ['id' => $account->id, 'label' => ($account->label ?? 'Verified account').' · '.$account->maskedAccountNumber()])->all(),
            'payouts' => $payouts->through(fn (EventPayout $payout): array => ['id' => $payout->id, 'amount' => $payout->amount, 'status' => $payout->status, 'destination' => $payout->commerce_recipient_display, 'message' => $payout->failure_reason]),
            'refunds' => $refunds->through(fn (StoreRefund $refund): array => ['id' => $refund->id, 'order_id' => $refund->order_id, 'amount' => $refund->amount_pesewas, 'status' => $refund->status, 'reason' => $refund->reason, 'message' => $refund->review_reason])]);
    }

    private function authorizeSeller(User $actor, string $permission): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant() ?? abort(403);
        abort_unless(User::query()->whereKey($actor->id)->where('tenant_id', $tenant->id)->exists() || $actor->tenants()->whereKey($tenant->id)->exists(), 403);
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            abort_unless(Gate::forUser($actor->fresh())->allows($permission), 403);
        } finally {
            setPermissionsTeamId($team);
        }

        return $tenant;
    }

    /** @param array<string, mixed> $data */
    private function respond(Request $request, array $data): Response
    {
        $response = $request->expectsJson() ? response()->json($data) : Inertia::render('Tenant/Store/Orders/Index', $data)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
