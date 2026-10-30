<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreCheckoutRequest;
use App\Models\VenueBookingCharge;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\StorePayments;
use Illuminate\Http\JsonResponse;

/**
 * Starts or continues the payment of one charge of an accepted venue contract.
 * The amount, the account and the source come from the stored charge, never
 * from the request; confirmation arrives through the shared callback and webhook.
 */
final class VenueChargeController extends Controller
{
    public function start(StoreCheckoutRequest $request, string $charge, StorePayments $payments): JsonResponse
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        abort_if($email === null, 404, 'Payment unavailable.');
        $charge = VenueBookingCharge::query()->with('order')->whereHas('order', fn ($order) => $order->where('offering_type', 'reservation')->where('buyer_email', PlatformAttendeeVerification::normalise($email)))->find($charge);
        abort_if($charge === null, 404, 'Payment unavailable.');
        $attempt = $payments->start($charge->order, $request->session(), $request->validated('submission_key'), $request->validated('accepted_quote_hash'));

        return response()->json(['charge_id' => $charge->id, 'status' => $attempt?->status ?? 'confirmed', 'checkout_url' => $attempt?->status === 'ready' ? $attempt->checkout_url : null])
            ->header('Cache-Control', 'private, no-store');
    }
}
