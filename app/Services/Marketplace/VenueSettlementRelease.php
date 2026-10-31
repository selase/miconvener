<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialDecision;
use App\Models\VenueSettlementReserve;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Releases the rental earnings of a finished booking to the venue's payout
 * balance. The host records that the event is complete, once; nothing is
 * released while the rental is unpaid, a refund is open or money is under review.
 */
final class VenueSettlementRelease
{
    public function __construct(private readonly CommerceOrderLocks $locks, private readonly VenueBookingApprovals $approvals) {}

    public function complete(VenueBooking $booking, User $actor, string $reason, string $operationKey): VenueFinancialDecision
    {
        $reason = mb_trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || ! Str::isUuid($operationKey)) {
            $this->refuse('Give a note and a valid request key.');
        }
        $requestHash = hash('sha256', "complete:{$booking->id}:{$reason}");

        return $this->locks->forBooking($booking->id, function (StoreListing $listing, VenueBooking $locked) use ($actor, $reason, $operationKey, $requestHash): VenueFinancialDecision {
            $this->approvals->assertHost($locked, $actor);
            $prior = VenueFinancialDecision::query()->where('booking_id', $locked->id)->where('decision', VenueFinancialDecision::COMPLETED)->first();
            if ($prior !== null) {
                if ($prior->operation_key !== $operationKey || ! hash_equals($prior->request_hash, $requestHash)) {
                    $this->refuse('This booking was already completed.');
                }

                return $prior;
            }
            $this->assertReleasable($locked);

            $charges = VenueBookingCharge::query()->where('booking_id', $locked->id)->whereIn('purpose', [VenueBookingCharge::RENTAL_INITIAL, VenueBookingCharge::RENTAL_BALANCE])->get();
            $decision = VenueFinancialDecision::query()->create([
                'quote_id' => $charges->first()->quote_id, 'booking_id' => $locked->id, 'shop_id' => $locked->shop_id, 'tenant_id' => $locked->tenant_id,
                'decision' => VenueFinancialDecision::COMPLETED, 'actor_type' => 'host', 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'reason' => $reason, 'operation_key' => $operationKey, 'request_hash' => $requestHash,
            ]);
            VenueSettlementReserve::query()->where('booking_id', $locked->id)->where('state', VenueSettlementReserve::HELD)
                ->whereIn('charge_id', $charges->pluck('id'))->get()
                ->each(fn (VenueSettlementReserve $reserve) => $reserve->update(['state' => VenueSettlementReserve::RELEASED, 'released_at' => now(), 'release_decision_id' => $decision->id]));

            return $decision;
        });
    }

    private function assertReleasable(VenueBooking $booking): void
    {
        if ($booking->status !== VenueBooking::STATUS_CONFIRMED || $booking->payment_status !== VenueBooking::PAYMENT_FULLY_PAID) {
            $this->refuse('Only a confirmed booking whose rental is fully paid can be completed.');
        }
        if ($booking->ends_at->isFuture()) {
            $this->refuse('The event has not finished yet.');
        }
        $orders = VenueBookingCharge::query()->where('booking_id', $booking->id)->pluck('order_id');
        $open = StoreRefund::query()->whereIn('order_id', $orders)->where('status', '<>', 'confirmed')->exists()
            || StorePaymentObservation::query()->whereIn('order_id', $orders)->where('processing_state', 'pending')->exists()
            || StoreOrder::query()->whereIn('id', $orders)->where('payment_state', 'captured_review')->exists();
        if ($open) {
            $this->refuse('Resolve the open refund or payment review before completing this booking.');
        }
        if (! VenueSettlementReserve::query()->where('booking_id', $booking->id)->where('state', VenueSettlementReserve::HELD)->exists()) {
            $this->refuse('There are no held earnings to release for this booking.');
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['settlement' => $message]);
    }
}
