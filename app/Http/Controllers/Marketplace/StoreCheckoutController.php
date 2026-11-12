<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreCheckoutRequest;
use App\Http\Requests\Marketplace\StoreQuoteRequest;
use App\Models\Shop;
use App\Models\StoreOrder;
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

        return response()->json(['order_id' => $order->id, 'order' => app(\App\Services\Marketplace\StoreOrderPayload::class)->buyer($order)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function start(StoreCheckoutRequest $request, string $order, StorePayments $payments): JsonResponse
    {
        $email = app(\App\Services\Events\PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        abort_if($email === null, 404, 'Order unavailable.');
        $order = StoreOrder::query()->where('offering_type', 'purchase')->where('buyer_email', \App\Services\Events\PlatformAttendeeVerification::normalise($email))->find($order);
        abort_if($order === null, 404, 'Order unavailable.');
        $attempt = $payments->start($order, $request->session(), $request->validated('submission_key'), $request->validated('accepted_quote_hash'));

        return response()->json(['order_id' => $order->id, 'status' => $attempt->status ?? 'confirmed', 'checkout_url' => $attempt?->status === 'ready' ? $attempt->checkout_url : null])
            ->header('Cache-Control', 'private, no-store');
    }

    public function callback(\Illuminate\Http\Request $request, string $reference, \App\Services\Marketplace\StorePaymentNotifications $notifications): \Illuminate\Http\RedirectResponse
    {
        $attempt = \App\Models\StorePaymentAttempt::query()->where('reference', $reference)->firstOrFail();
        $notifications->reconcile($attempt);

        if ($attempt->order->offering_type === 'badge_template') {
            $purchase = \App\Models\BadgeTemplatePurchase::query()->where('order_id', $attempt->order_id)->firstOrFail();

            return redirect()->to(mb_rtrim((string) config('app.url'), '/').route('badge-template-purchases.show', $purchase->id, false));
        }

        if ($attempt->order->offering_type === 'reservation') {
            $charge = \App\Models\VenueBookingCharge::query()->with('booking')->where('order_id', $attempt->order_id)->firstOrFail();

            return redirect()->route('marketplace.bookings.show', $charge->booking->booking_reference);
        }

        return redirect('/my?return_to='.rawurlencode('/my/store-orders/'.$attempt->order_id));
    }
}
