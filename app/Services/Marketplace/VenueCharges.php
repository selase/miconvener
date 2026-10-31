<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StoreOrderLine;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialDecision;
use App\Models\VenueFinancialQuote;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\FeeTerms;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Creates the common reservation order that carries each payment of an accepted
 * venue contract. The amount, fee and deadlines come from the immutable quote
 * and never from the caller, and each purpose exists once. Creating an order
 * takes no money, confirms nothing and issues no receipt.
 */
final class VenueCharges
{
    public const PURPOSES = [VenueBookingCharge::RENTAL_INITIAL, VenueBookingCharge::RENTAL_BALANCE, VenueBookingCharge::SECURITY];

    public function __construct(private readonly CommerceOrderLocks $locks, private readonly FeeCalculator $fees) {}

    /**
     * The purposes this contract actually needs a payment for.
     *
     * @return list<string>
     */
    public function dueFor(VenueFinancialQuote $quote): array
    {
        return array_values(array_filter(self::PURPOSES, fn (string $purpose): bool => $this->amount($quote, $purpose) > 0));
    }

    /**
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function order(VenueFinancialQuote $quote, string $purpose): StoreOrder
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException("Unknown venue charge purpose [{$purpose}].");
        }

        return $this->locks->forBooking($quote->booking_id, function () use ($quote, $purpose): StoreOrder {
            $existing = VenueBookingCharge::query()->where('quote_id', $quote->id)->where('purpose', $purpose)->first();
            if ($existing !== null) {
                return $existing->order;
            }

            $accepted = VenueFinancialDecision::query()->where('quote_id', $quote->id)->where('decision', VenueFinancialDecision::ACCEPTED)->first();
            $amount = $this->amount($quote, $purpose);
            if ($accepted === null || $amount < 1) {
                throw ValidationException::withMessages(['charge' => 'This contract has no such payment to make.']);
            }

            return $this->create($quote, $purpose, (string) $accepted->buyer_email, $amount);
        });
    }

    private function create(VenueFinancialQuote $quote, string $purpose, string $buyerEmail, int $amount): StoreOrder
    {
        $agreement = $quote->agreement;
        $booking = $quote->booking;
        $fee = $this->fee($quote, $purpose);
        $split = $this->fees->forTerms($amount, new FeeTerms((float) $agreement->percentage, $agreement->cap_pesewas, 'organizer', (float) $agreement->gateway_estimate_rate));
        $terms = $agreement->only(['id', 'version', 'percentage', 'cap_pesewas', 'fee_bearer', 'gateway_estimate_rate', 'refund_gateway_fee_bearer', 'collection_terms', 'refund_terms', 'support_contact', 'receipt_terms', 'payout_policy'])
            + ['quote_id' => $quote->id, 'quote_terms_hash' => $quote->terms_hash, 'charge_purpose' => $purpose];
        $expiresAt = $purpose === VenueBookingCharge::RENTAL_INITIAL ? $quote->expires_at : $quote->event_starts_at;
        $dueAt = match ($purpose) {
            VenueBookingCharge::RENTAL_BALANCE => $quote->balance_due_at,
            VenueBookingCharge::SECURITY => $quote->security_due_at,
            default => null,
        };

        $order = StoreOrder::query()->create([
            'tenant_id' => $quote->tenant_id, 'shop_id' => $quote->shop_id, 'agreement_id' => $agreement->id,
            'buyer_email' => $buyerEmail, 'submission_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, "venue-charge:{$quote->id}:{$purpose}")->toString(),
            'cart_hash' => hash('sha256', "{$quote->id}:{$purpose}"), 'currency' => 'GHS', 'offering_type' => 'reservation',
            'principal_pesewas' => $amount, 'platform_fee_pesewas' => $fee, 'gateway_estimate_pesewas' => $split->gatewayFeeEstimate,
            'charged_pesewas' => $amount, 'fee_bearer' => 'organizer',
            'settlement_mode' => $agreement->settlement_mode, 'provider' => $agreement->provider, 'account_fingerprint' => $agreement->account_fingerprint,
            'seller_snapshot' => ['name' => (string) $booking->shop?->name], 'terms_snapshot' => $terms, 'terms_hash' => $quote->terms_hash,
            'expires_at' => $expiresAt,
        ]);
        StoreOrderLine::query()->create([
            'order_id' => $order->id, 'shop_id' => $quote->shop_id, 'variant_id' => null, 'booking_id' => $booking->id, 'listing_id' => $booking->store_listing_id,
            'offering_type' => 'reservation', 'title_snapshot' => 'Venue booking: '.$booking->listing?->title, 'sku_snapshot' => 'RESERVATION', 'options_snapshot' => [],
            'quantity' => 1, 'unit_price_pesewas' => $amount, 'line_amount_pesewas' => $amount,
        ]);
        VenueBookingCharge::query()->create([
            'tenant_id' => $quote->tenant_id, 'shop_id' => $quote->shop_id, 'booking_id' => $quote->booking_id, 'quote_id' => $quote->id,
            'order_id' => $order->id, 'purpose' => $purpose, 'amount_pesewas' => $amount, 'fee_pesewas' => $fee, 'due_at' => $dueAt,
        ]);

        return $order;
    }

    private function amount(VenueFinancialQuote $quote, string $purpose): int
    {
        return match ($purpose) {
            VenueBookingCharge::RENTAL_INITIAL => $quote->initial_pesewas,
            VenueBookingCharge::RENTAL_BALANCE => $quote->balance_pesewas,
            default => $quote->security_pesewas,
        };
    }

    private function fee(VenueFinancialQuote $quote, string $purpose): int
    {
        return match ($purpose) {
            VenueBookingCharge::RENTAL_INITIAL => $quote->initial_fee_pesewas,
            VenueBookingCharge::RENTAL_BALANCE => $quote->balance_fee_pesewas,
            default => 0,
        };
    }
}
