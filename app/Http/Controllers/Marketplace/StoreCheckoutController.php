<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreCheckoutRequest;
use App\Http\Requests\Marketplace\StoreQuoteRequest;
use App\Models\Shop;
use App\Models\StoreOrder;
use App\Services\Marketplace\StoreOrderAccess;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\StoreQuotes;
use Illuminate\Http\JsonResponse;

final class StoreCheckoutController extends Controller
{
    public function quote(StoreQuoteRequest $request, Shop $shop, StoreQuotes $quotes): JsonResponse
    {
        $data = $request->validated();
        $lines = array_map(fn (array $line): array => ['variant_id' => $line['variant_id'], 'quantity' => (int) $line['quantity']], $data['lines']);
        $order = $quotes->create($shop->tenant, $request->session(), $lines, $data['submission_key']);

        return response()->json(['order_id' => $order->id], 201)->header('Cache-Control', 'private, no-store');
    }

    public function start(StoreCheckoutRequest $request, StoreOrder $order, StorePayments $payments, StoreOrderAccess $access): JsonResponse
    {
        abort_unless($access->buyer($order, $request->session()), 404);
        $attempt = $payments->start($order, $request->session(), $request->validated('submission_key'));

        return response()->json(['order_id' => $order->id, 'status' => $attempt->status ?? 'confirmed', 'checkout_url' => $attempt?->status === 'ready' ? $attempt->checkout_url : null])
            ->header('Cache-Control', 'private, no-store');
    }

    public function callback(\Illuminate\Http\Request $request, string $reference, \App\Services\Marketplace\StorePaymentNotifications $notifications): \Illuminate\Http\RedirectResponse
    {
        $attempt = \App\Models\StorePaymentAttempt::query()->where('reference', $reference)->firstOrFail();
        $notifications->reconcile($attempt);

        return redirect('/my?return_to='.rawurlencode('/my/store-orders/'.$attempt->order_id));
    }
}
