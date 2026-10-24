<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\StoreOrder;
use App\Models\StoreReceipt;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\StoreOrderPayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class StoreOrderController extends Controller
{
    public function index(Request $request, StoreOrderPayload $payload): Response
    {
        $email = $this->email($request);
        if ($email instanceof Response) {
            return $email;
        }
        $orders = StoreOrder::query()->where('buyer_email', $email)->with(['lines', 'tenant.features', 'receipt.outbox', 'attempts', 'fulfilmentArrangement', 'collection'])->latest()->paginate(20);
        $orders->through(fn (StoreOrder $order): array => $payload->buyer($order));

        return $this->respond($request, ['orders' => $orders, 'verified_until' => $request->session()->get(PlatformAttendeeVerification::SESSION_KEY.'.expires_at')]);
    }

    public function show(Request $request, string $order, StoreOrderPayload $payload): Response
    {
        $email = $this->email($request);
        if ($email instanceof Response) {
            return $email;
        }
        $owned = StoreOrder::query()->where('buyer_email', $email)->with(['lines', 'tenant.features', 'receipt.outbox', 'attempts', 'fulfilmentArrangement', 'collection'])->find($order);
        abort_if($owned === null, 404, 'Order unavailable.');

        return $this->respond($request, ['order' => [...$payload->buyer($owned), 'ready_email_state' => app(\App\Services\Marketplace\StoreFulfilmentNotices::class)->status($owned), 'can_issue_pass' => app(\App\Services\Marketplace\StoreFulfilment::class)->enabled($owned) && app(\App\Services\Marketplace\StoreFulfilment::class)->eligible($owned) && (($owned->fulfilmentArrangement?->method === 'collection' && $owned->fulfilment_state === 'ready') || ($owned->fulfilmentArrangement?->method === 'delivery' && $owned->fulfilment_state === 'dispatched')), 'fulfilment_paused' => ! app(\App\Services\Marketplace\StoreFulfilment::class)->enabled($owned), 'fulfilment_revision' => $owned->fulfilmentEvents()->max('sequence'), 'destination' => $owned->fulfilmentArrangement?->destination], 'verified_until' => $request->session()->get(PlatformAttendeeVerification::SESSION_KEY.'.expires_at')], ['orderId' => $order]);
    }

    public function receipt(Request $request, string $order): Response
    {
        $email = $this->email($request);
        if ($email instanceof Response) {
            return $email;
        }
        $owned = StoreOrder::query()->where('buyer_email', $email)->find($order);
        abort_if($owned === null, 404, 'Order unavailable.');
        $receipt = StoreReceipt::query()->where('order_id', $owned->id)->first();
        abort_if($receipt === null, 404, 'Receipt unavailable.');

        return $this->respond($request, ['receipt' => $receipt->snapshot, 'verified_until' => $request->session()->get(PlatformAttendeeVerification::SESSION_KEY.'.expires_at')], ['orderId' => $order, 'receipt' => true]);
    }

    public function cancel(Request $request, string $order): Response
    {
        $email = $this->email($request);
        if ($email instanceof Response) {
            return $email;
        }
        $owned = StoreOrder::query()->where('buyer_email', $email)->find($order);
        abort_if($owned === null, 404, 'Order unavailable.');
        $owned->getConnection()->transaction(function () use ($owned): void {
            $current = app(\App\Services\Marketplace\CommerceInventoryLocks::class)->order($owned);
            if ($current->state === 'cancelled') {
                return;
            }
            abort_unless(in_array($current->state, ['quoted', 'awaiting_payment'], true) && $current->payment_state === 'awaiting'
                && ! $current->attempts()->whereNotIn('status', ['failed', 'expired'])->exists(), 409, 'Reconcile the existing payment before releasing this hold.');
            app(\App\Services\Marketplace\PurchaseReservations::class)->release($current, 'cancelled');
            $current->update(['state' => 'cancelled', 'fulfilment_state' => 'cancelled']);
        });

        return response()->json(['status' => 'cancelled'])->header('Cache-Control', 'private, no-store');
    }

    private function email(Request $request): string|Response
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        if ($email === null) {
            $response = $request->expectsJson() ? response()->json(['message' => 'Verify your email to see your orders.'], 401)
                : redirect('/my?return_to='.rawurlencode($request->getPathInfo()));
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        return PlatformAttendeeVerification::normalise($email);
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>  $props
     */
    private function respond(Request $request, array $data, array $props = []): Response
    {
        $response = $request->expectsJson() ? response()->json($data) : Inertia::render('Public/Marketplace/Orders/Index', $props)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
