<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventPromoCode;
use App\Models\EventRegistration;
use App\Models\EventTicketType;

final class PromoCodeService
{
    /**
     * Validate a promo code against an event, ticket type, and attendee email.
     *
     * @return array{
     *     valid: bool,
     *     promo_code: ?EventPromoCode,
     *     discount_amount: int,
     *     final_amount: int,
     *     error: ?string
     * }
     */
    public function validateCode(
        Event $event,
        string $rawCode,
        ?EventTicketType $ticketType,
        string $email,
        int $currentAmount
    ): array {
        $code = mb_strtoupper(mb_trim($rawCode));

        if ($code === '') {
            return [
                'valid' => false,
                'promo_code' => null,
                'discount_amount' => 0,
                'final_amount' => $currentAmount,
                'error' => 'Please enter a promo code.',
            ];
        }

        $promoCode = EventPromoCode::where('tenant_id', $event->tenant_id)
            ->where(fn ($q) => $q->where('event_id', $event->id)->orWhereNull('event_id'))
            ->where('code', $code)
            ->first();

        if (! $promoCode) {
            return [
                'valid' => false,
                'promo_code' => null,
                'discount_amount' => 0,
                'final_amount' => $currentAmount,
                'error' => 'Invalid promo code.',
            ];
        }

        if (! $promoCode->is_active) {
            return [
                'valid' => false,
                'promo_code' => $promoCode,
                'discount_amount' => 0,
                'final_amount' => $currentAmount,
                'error' => 'This promo code is inactive.',
            ];
        }

        if (! $promoCode->hasStarted()) {
            return [
                'valid' => false,
                'promo_code' => $promoCode,
                'discount_amount' => 0,
                'final_amount' => $currentAmount,
                'error' => 'This promo code is not yet active.',
            ];
        }

        if ($promoCode->isExpired()) {
            return [
                'valid' => false,
                'promo_code' => $promoCode,
                'discount_amount' => 0,
                'final_amount' => $currentAmount,
                'error' => 'This promo code has expired.',
            ];
        }

        if ($promoCode->isRedemptionLimitReached()) {
            return [
                'valid' => false,
                'promo_code' => $promoCode,
                'discount_amount' => 0,
                'final_amount' => $currentAmount,
                'error' => 'This promo code has reached its maximum redemptions limit.',
            ];
        }

        if (! $promoCode->isValidForTicketType($ticketType)) {
            return [
                'valid' => false,
                'promo_code' => $promoCode,
                'discount_amount' => 0,
                'final_amount' => $currentAmount,
                'error' => 'This promo code is not valid for the selected ticket type.',
            ];
        }

        if ($email !== '' && $promoCode->max_per_attendee > 0) {
            $existingRedemptions = EventRegistration::where('promo_code_id', $promoCode->id)
                ->where('email', $email)
                ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
                ->count();

            if ($existingRedemptions >= $promoCode->max_per_attendee) {
                return [
                    'valid' => false,
                    'promo_code' => $promoCode,
                    'discount_amount' => 0,
                    'final_amount' => $currentAmount,
                    'error' => 'You have already reached the redemption limit for this promo code.',
                ];
            }
        }

        $discount = $promoCode->calculateDiscount($currentAmount);
        $finalAmount = max(0, $currentAmount - $discount);

        return [
            'valid' => true,
            'promo_code' => $promoCode,
            'discount_amount' => $discount,
            'final_amount' => $finalAmount,
            'error' => null,
        ];
    }

    public function recordRedemption(EventPromoCode $promoCode): void
    {
        $promoCode->incrementRedemptions();
    }
}
