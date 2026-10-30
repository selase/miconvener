<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Contracts\CommerceOffering;
use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialDecision;
use App\Models\VenueFinancialQuote;
use App\Services\Events\PlatformAttendeeVerification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The rules for paying toward a venue booking. Money is only ever taken for the
 * contract the verified buyer accepted, and only while the host's dates are
 * still theirs to give. This slice validates and describes; confirming a
 * booking from a capture, refunds and payouts arrive with the payment slices
 * and stay closed until then.
 */
final class VenueOffering implements CommerceOffering
{
    private const CLOSED = [VenueBooking::STATUS_CANCELLED, VenueBooking::STATUS_DECLINED, VenueBooking::STATUS_REJECTED];

    public function __construct(private readonly VenuePaymentRollout $rollout, private readonly VenueAvailabilityService $availability, private readonly PlatformAttendeeVerification $verification) {}

    public function hasCharge(StoreOrder $order): bool
    {
        return VenueBookingCharge::query()->where('order_id', $order->id)->exists();
    }

    public function assertCheckout(StoreOrder $order, Session $session, ?string $acceptedHash): void
    {
        $charge = $this->charge($order);
        $quote = $this->quote($charge);
        $booking = $charge->booking;
        $tenant = Tenant::query()->findOrFail($order->tenant_id);
        $decision = $this->acceptance($quote);
        $email = $this->verification->verifiedEmail($session);

        if ($quote === null || $decision === null || ! $this->rollout->enabled($tenant)
            || $acceptedHash === null || ! hash_equals($decision->terms_hash, $acceptedHash)
            || $email === null || ! hash_equals((string) $decision->buyer_email, PlatformAttendeeVerification::normalise($email))) {
            $this->refuse();
        }

        $open = $charge->purpose === VenueBookingCharge::RENTAL_INITIAL
            ? $booking->status === VenueBooking::STATUS_APPROVED_HOLD && $booking->hold_expires_at?->isFuture() === true && $quote->expires_at->isFuture()
            : $booking->status === VenueBooking::STATUS_CONFIRMED;
        if (! $open) {
            $this->refuse();
        }
    }

    public function assertNewAttempt(StoreOrder $order): void
    {
        $charge = $this->charge($order);
        if ($charge->purpose === VenueBookingCharge::RENTAL_INITIAL && ! $this->slotFree($charge->booking)) {
            $this->refuse();
        }
    }

    public function settleWithoutPayment(StoreOrder $order): void
    {
        throw new LogicException('A venue booking with nothing to pay is accepted without an order.');
    }

    public function captureIssues(StoreOrder $order, StorePaymentObservation $capture): array
    {
        $charge = $this->charge($order);
        $quote = $this->quote($charge);
        $booking = $charge->booking;
        if ($quote === null) {
            return ['unknown_charge'];
        }

        $issues = [];
        if ($this->acceptance($quote) === null) {
            $issues[] = 'contract_not_accepted';
        }
        if (in_array($booking->status, self::CLOSED, true)) {
            $issues[] = 'booking_closed';
        } elseif ($charge->purpose === VenueBookingCharge::RENTAL_INITIAL) {
            $paidAt = $capture->paid_at === null ? null : CarbonImmutable::parse($capture->paid_at);
            if ($paidAt === null || $paidAt->greaterThanOrEqualTo($quote->expires_at)) {
                $issues[] = 'capture_after_expiry';
            }
            if ($booking->status === VenueBooking::STATUS_APPROVED_HOLD && ! $this->slotFree($booking)) {
                $issues[] = 'calendar_conflict';
            }
            if (! in_array($booking->status, [VenueBooking::STATUS_APPROVED_HOLD, VenueBooking::STATUS_CONFIRMED], true)) {
                $issues[] = 'booking_closed';
            }
        } elseif ($booking->status !== VenueBooking::STATUS_CONFIRMED) {
            $issues[] = 'booking_not_confirmed';
        }

        return array_values(array_unique($issues));
    }

    public function onCaptureReview(StoreOrder $order): void {}

    public function applyCapture(StoreOrder $order, StorePaymentAttempt $attempt, StorePaymentObservation $capture): void
    {
        throw new LogicException('Confirming a venue booking from a capture is not available yet.');
    }

    public function expectedMetadata(StorePaymentAttempt $attempt): array
    {
        $charge = $this->charge($attempt->order);

        return ['source' => 'miconvener_venue', 'venue_charge_id' => $charge->id, 'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id];
    }

    public function assertRefund(StoreOrder $order): void
    {
        throw ValidationException::withMessages(['refund' => 'Venue refunds are not available yet. Ask a platform administrator.']);
    }

    public function applyRefund(StoreRefund $refund, StoreOrder $order): void
    {
        throw new LogicException('Venue refunds are not available yet.');
    }

    public function receiptContext(StoreOrder $order): array
    {
        $charge = $this->charge($order);
        $quote = $this->quote($charge);
        $purpose = match ($charge->purpose) {
            VenueBookingCharge::RENTAL_INITIAL => 'booking payment',
            VenueBookingCharge::RENTAL_BALANCE => 'balance payment',
            VenueBookingCharge::SECURITY => 'refundable security amount',
            default => 'payment under review',
        };

        return [
            'number' => 'VENUE-'.mb_strtoupper(str_replace('-', '', $order->id)),
            'seller' => ['name' => (string) $charge->booking->shop?->name],
            'currency' => $order->currency,
            'total_pesewas' => $charge->amount_pesewas,
            'lines' => [[
                'title' => 'Venue booking: '.$purpose, 'quantity' => 1,
                'unit_price_pesewas' => $charge->amount_pesewas, 'line_amount_pesewas' => $charge->amount_pesewas,
            ]],
            'buyer_fee_pesewas' => 0, 'collection_terms' => '', 'refund_terms' => $quote === null ? '' : $quote->cancellation_terms,
            'support_contact' => $quote === null ? '' : $quote->support_contact, 'charges' => [], 'production_terms' => null, 'customisation' => null,
        ];
    }

    private function charge(StoreOrder $order): VenueBookingCharge
    {
        return VenueBookingCharge::query()->with('booking.shop')->where('order_id', $order->id)->firstOrFail();
    }

    private function quote(VenueBookingCharge $charge): ?VenueFinancialQuote
    {
        return $charge->quote_id === null ? null : $charge->quote;
    }

    private function acceptance(?VenueFinancialQuote $quote): ?VenueFinancialDecision
    {
        return $quote === null ? null : VenueFinancialDecision::query()->where('quote_id', $quote->id)->where('decision', VenueFinancialDecision::ACCEPTED)->first();
    }

    private function slotFree(VenueBooking $booking): bool
    {
        $listing = StoreListing::query()->withStaffOnly()->findOrFail($booking->store_listing_id);

        return $this->availability->isSlotAvailable($listing, $booking->starts_at, $booking->ends_at, $booking->id);
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['checkout' => 'This venue payment is not available. Check the accepted quote and the dates.']);
    }
}
