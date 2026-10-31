<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\AcceptVenueQuoteRequest;
use App\Models\StoreReceipt;
use App\Models\VenueBooking;
use App\Models\VenueBookingCharge;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\VenueCommercialQuotes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The verified requester's side of the final quote: accept the exact terms they
 * were shown, and read the receipt of a payment they made. Both answer 404 to
 * anyone but the verified requester of that booking.
 */
final class VenueQuoteAcceptanceController extends Controller
{
    public function accept(AcceptVenueQuoteRequest $request, string $reference, VenueCommercialQuotes $quotes): RedirectResponse
    {
        $booking = VenueBooking::query()->where('booking_reference', $reference)->where('kind', VenueBooking::KIND_MARKETPLACE)->whereNotNull('approval_flow_version')->firstOrFail();
        $quote = $quotes->activeQuote($booking);
        abort_if($quote === null, 404);

        try {
            $quotes->accept($quote, $request->session(), (string) $request->validated('accepted_quote_hash'), (string) $request->validated('operation_key'));
        } catch (AuthorizationException) {
            abort(404);
        } catch (ValidationException $e) {
            return redirect()->route('marketplace.bookings.show', $booking->booking_reference)->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return redirect()->route('marketplace.bookings.show', $booking->booking_reference)->with('success', 'Quote accepted. You can now make your first payment.');
    }

    public function receipt(Request $request, string $charge): JsonResponse
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        abort_if($email === null, 404, 'Receipt unavailable.');
        $owned = VenueBookingCharge::query()->whereHas('order', fn ($order) => $order->where('offering_type', 'reservation')->where('buyer_email', PlatformAttendeeVerification::normalise($email)))->find($charge);
        abort_if($owned === null, 404, 'Receipt unavailable.');
        $receipt = StoreReceipt::query()->where('order_id', $owned->order_id)->first();
        abort_if($receipt === null, 404, 'Receipt unavailable.');

        return response()->json(['receipt' => $receipt->snapshot])->header('Cache-Control', 'private, no-store');
    }
}
