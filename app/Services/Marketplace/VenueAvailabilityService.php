<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreListing;
use App\Models\VenueBooking;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class VenueAvailabilityService
{
    /**
     * Check if a requested time slot is available for booking.
     */
    public function isSlotAvailable(
        StoreListing $listing,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?string $excludeBookingId = null
    ): bool {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            return false;
        }

        $query = VenueBooking::query()
            ->where('store_listing_id', $listing->id)
            ->blockingCalendar()
            ->overlapping($startsAt, $endsAt);

        if ($excludeBookingId !== null) {
            $query->where('id', '!=', $excludeBookingId);
        }

        return ! $query->exists();
    }

    /**
     * Get all blocking booking slots within a date range for calendar rendering.
     *
     * @return Collection<int, array{starts_at: string, ends_at: string, status: string}>
     */
    public function getBookedSlots(StoreListing $listing, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return VenueBooking::query()
            ->where('store_listing_id', $listing->id)
            ->blockingCalendar()
            ->overlapping($from, $to)
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at', 'status'])
            ->map(fn (VenueBooking $booking): array => [
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status,
            ]);
    }

    /**
     * Calculate pricing, duration units, and required reservation deposit.
     *
     * @return array{
     *     time_slot_type: string,
     *     duration_units: float,
     *     rate_pesewas: int,
     *     rental_amount_pesewas: int,
     *     security_deposit_pesewas: int,
     *     total_amount_pesewas: int,
     *     deposit_required_pesewas: int,
     *     is_price_on_request: bool,
     *     capacity_check: array{valid: bool, max_capacity: ?int, message: ?string}
     * }
     */
    public function calculatePricing(
        StoreListing $listing,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        int $guestCount = 1,
        string $layoutStyle = 'banquet'
    ): array {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('Booking end time must be strictly after start time.');
        }

        $isPriceOnRequest = $listing->isPriceOnRequest();
        $ratePesewas = $isPriceOnRequest ? 0 : (int) $listing->rental_price_pesewas;
        $securityDeposit = $isPriceOnRequest ? 0 : (int) ($listing->security_deposit_pesewas ?? 0);

        // Determine slot type and duration units
        $diffMinutes = $startsAt->diffInMinutes($endsAt);
        $diffHours = (float) ceil($diffMinutes / 60.0);
        $diffDays = (float) max(1, ceil($diffHours / 24.0));

        $pricingModel = $listing->pricing_model ?? StoreListing::PRICING_MODEL_PER_DAY;

        if ($pricingModel === StoreListing::PRICING_MODEL_PER_HOUR) {
            $slotType = VenueBooking::SLOT_HOURLY;
            $durationUnits = max(1.0, $diffHours);
            $rentalAmount = (int) round($ratePesewas * $durationUnits);
        } elseif ($pricingModel === StoreListing::PRICING_MODEL_FLAT_RATE) {
            $slotType = $diffDays > 1 ? VenueBooking::SLOT_MULTI_DAY : VenueBooking::SLOT_FULL_DAY;
            $durationUnits = 1.0;
            $rentalAmount = $ratePesewas;
        } elseif ($diffDays > 1) {
            $slotType = VenueBooking::SLOT_MULTI_DAY;
            $durationUnits = $diffDays;
            $rentalAmount = (int) round($ratePesewas * $durationUnits);
        } else {
            $slotType = VenueBooking::SLOT_FULL_DAY;
            $durationUnits = 1.0;
            $rentalAmount = $ratePesewas;
        }

        $totalAmount = $rentalAmount + $securityDeposit;

        // Deposit policy: 25% of rental fee + 100% of refundable security deposit
        if ($isPriceOnRequest || $rentalAmount <= 0) {
            $depositRequired = 0;
        } else {
            $baseDeposit = (int) round($rentalAmount * 0.25);
            // Minimum deposit of GHS 50 (5,000 pesewas) or total amount if lower
            $minDeposit = min(5000, $rentalAmount);
            $depositRequired = max($baseDeposit, $minDeposit) + $securityDeposit;
            $depositRequired = min($depositRequired, $totalAmount);
        }

        // Capacity check
        $capacities = $listing->capacity_breakdown ?? [];
        $styleCapacity = isset($capacities[$layoutStyle]) ? (int) $capacities[$layoutStyle] : null;
        $capacityValid = true;
        $capacityMessage = null;

        if ($styleCapacity !== null && $styleCapacity > 0 && $guestCount > $styleCapacity) {
            $capacityValid = false;
            $capacityMessage = "Requested {$guestCount} attendees exceeds the maximum {$layoutStyle} capacity of {$styleCapacity}.";
        }

        return [
            'time_slot_type' => $slotType,
            'duration_units' => $durationUnits,
            'rate_pesewas' => $ratePesewas,
            'rental_amount_pesewas' => $rentalAmount,
            'security_deposit_pesewas' => $securityDeposit,
            'total_amount_pesewas' => $totalAmount,
            'deposit_required_pesewas' => $depositRequired,
            'is_price_on_request' => $isPriceOnRequest,
            'capacity_check' => [
                'valid' => $capacityValid,
                'max_capacity' => $styleCapacity,
                'message' => $capacityMessage,
            ],
        ];
    }
}
