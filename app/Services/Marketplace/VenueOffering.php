<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Contracts\CommerceOffering;
use App\Models\LedgerTransaction;
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
use App\Models\VenueSettlementReserve;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Finance\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The rules for paying toward a venue booking. Money is only ever taken for the
 * contract the verified buyer accepted, and only while the host's dates are
 * still theirs to give. A clean rental capture is posted and confirms the
 * booking in one transaction; security money, refunds and payouts arrive with
 * the later payment slices and stay closed until then.
 */
final class VenueOffering implements CommerceOffering
{
    private const CLOSED = [VenueBooking::STATUS_CANCELLED, VenueBooking::STATUS_DECLINED, VenueBooking::STATUS_REJECTED];

    public function __construct(private readonly VenuePaymentRollout $rollout, private readonly VenueAvailabilityService $availability, private readonly PlatformAttendeeVerification $verification, private readonly LedgerService $ledger) {}

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
        if ($charge->purpose === VenueBookingCharge::LEGACY_REVIEW) {
            return ['legacy_review_import'];
        }
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

    public function importsHistoricalCapture(StoreOrder $order): bool
    {
        return $this->charge($order)->purpose === VenueBookingCharge::LEGACY_REVIEW;
    }

    public function onCaptureReview(StoreOrder $order): void {}

    public function applyCapture(StoreOrder $order, StorePaymentAttempt $attempt, StorePaymentObservation $capture): void
    {
        $charge = $this->charge($order);
        $booking = $charge->booking;
        $quote = $this->quote($charge);
        if ($quote === null || ! in_array($charge->purpose, [VenueBookingCharge::RENTAL_INITIAL, VenueBookingCharge::RENTAL_BALANCE, VenueBookingCharge::SECURITY], true)) {
            throw new LogicException('Only a payment of an accepted contract can be applied.');
        }

        if ($charge->purpose === VenueBookingCharge::SECURITY) {
            $this->settle($order, $attempt, $capture, $this->ledger->recordVenueSecurityCapture($order, $attempt, $capture));
            app(StoreReceipts::class)->capture($order);

            return;
        }

        $transaction = $this->ledger->recordStoreSale($order, $attempt, ['observation_id' => $capture->id]);
        $this->settle($order, $attempt, $capture, $transaction);
        VenueSettlementReserve::query()->create([
            'tenant_id' => $charge->tenant_id, 'shop_id' => $charge->shop_id, 'booking_id' => $charge->booking_id, 'charge_id' => $charge->id,
            'reserved_pesewas' => $order->charged_pesewas - (int) $capture->gateway_fee_pesewas - $order->platform_fee_pesewas,
        ]);

        $settled = $charge->purpose === VenueBookingCharge::RENTAL_BALANCE || $quote->balance_pesewas === 0;
        $booking->update([
            'status' => VenueBooking::STATUS_CONFIRMED,
            'payment_status' => $settled ? VenueBooking::PAYMENT_FULLY_PAID : VenueBooking::PAYMENT_DEPOSIT_PAID,
            'paid_at' => $booking->paid_at ?? $capture->paid_at,
            'amount_paid_pesewas' => $booking->amount_paid_pesewas + $charge->amount_pesewas,
        ]);
        app(StoreReceipts::class)->capture($order);
    }

    public function expectedMetadata(StorePaymentAttempt $attempt): array
    {
        $charge = $this->charge($attempt->order);
        if ($charge->purpose === VenueBookingCharge::LEGACY_REVIEW) {
            return ['venue_booking_id' => $charge->booking_id];
        }

        return ['source' => 'miconvener_venue', 'venue_charge_id' => $charge->id, 'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id];
    }

    public function assertRefund(StoreOrder $order): void
    {
        $charge = $this->charge($order);
        if ($charge->purpose === VenueBookingCharge::LEGACY_REVIEW || $charge->purpose === VenueBookingCharge::SECURITY) {
            return;
        }
        if (VenueSettlementReserve::query()->where('charge_id', $charge->id)->where('state', VenueSettlementReserve::RELEASED)->exists()) {
            throw ValidationException::withMessages(['refund' => 'This rental was settled to the venue after the event. Ask a platform administrator.']);
        }
    }

    public function applyRefund(StoreRefund $refund, StoreOrder $order): void
    {
        $charge = $this->charge($order);
        if ($charge->purpose === VenueBookingCharge::LEGACY_REVIEW) {
            $order->update(['state' => 'cancelled', 'payment_state' => 'refunded', 'ledger_state' => 'posted', 'review_reason' => null]);

            return;
        }
        if ($refund->observation?->processing_state !== 'posted') {
            if ($order->payment_state === 'captured_review') {
                $order->update(['state' => 'cancelled', 'payment_state' => 'refunded', 'ledger_state' => 'posted', 'review_reason' => null]);
            }

            return;
        }

        if ($charge->purpose === VenueBookingCharge::SECURITY) {
            $order->update(['payment_state' => 'refunded', 'ledger_state' => 'posted', 'review_reason' => null]);

            return;
        }

        $order->update(['state' => 'cancelled', 'payment_state' => 'refunded', 'ledger_state' => 'posted', 'review_reason' => null]);
        $booking = $charge->booking;
        $remaining = max(0, $booking->amount_paid_pesewas - $charge->amount_pesewas);
        $booking->update($remaining === 0
            ? ['status' => VenueBooking::STATUS_CANCELLED, 'payment_status' => VenueBooking::PAYMENT_REFUNDED, 'amount_paid_pesewas' => 0]
            : ['payment_status' => VenueBooking::PAYMENT_DEPOSIT_PAID, 'amount_paid_pesewas' => $remaining]);
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

    private function settle(StoreOrder $order, StorePaymentAttempt $attempt, StorePaymentObservation $capture, LedgerTransaction $transaction): void
    {
        $capture->update(['ledger_transaction_id' => $transaction->id, 'processing_state' => 'posted', 'review_reason' => null]);
        $order->update(['state' => 'confirmed', 'payment_state' => 'paid', 'ledger_state' => 'posted', 'paid_at' => $capture->paid_at, 'review_reason' => null]);
        $attempt->update(['status' => 'succeeded', 'last_verified_at' => now()]);
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
