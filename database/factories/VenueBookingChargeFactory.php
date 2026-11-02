<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreCommercialAgreement;
use App\Models\StoreOrder;
use App\Models\VenueBooking;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialQuote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VenueBookingCharge> */
final class VenueBookingChargeFactory extends Factory
{
    protected $model = VenueBookingCharge::class;

    /**
     * By default the initial rental charge of a new quote, with the reservation
     * order that carries its payment.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_id' => VenueFinancialQuote::factory(),
            'booking_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->booking_id,
            'shop_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->shop_id,
            'tenant_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->tenant_id,
            'purpose' => VenueBookingCharge::RENTAL_INITIAL,
            'amount_pesewas' => fn (array $a) => $this->quotedAmount($a, 'amount'),
            'fee_pesewas' => fn (array $a) => $this->quotedAmount($a, 'fee'),
            'order_id' => fn (array $a) => StoreOrder::factory()->reservation()->create([
                'agreement_id' => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->agreement_id,
                'principal_pesewas' => $a['amount_pesewas'], 'platform_fee_pesewas' => $a['fee_pesewas'], 'charged_pesewas' => $a['amount_pesewas'],
            ])->id,
            'due_at' => null,
        ];
    }

    /** The charge of one purpose under an existing quote, for exactly the amount that quote names. */
    public function forQuote(VenueFinancialQuote $quote, string $purpose = VenueBookingCharge::RENTAL_INITIAL): static
    {
        return $this->state(fn (): array => ['quote_id' => $quote->id, 'purpose' => $purpose]);
    }

    /** Money received before any contract existed, held as an exception for staff. */
    public function legacyReview(): static
    {
        return $this->state(function (): array {
            $booking = VenueBooking::factory()->create();
            $agreement = StoreCommercialAgreement::factory()->reservation()->create(['shop_id' => $booking->shop_id, 'tenant_id' => $booking->tenant_id]);

            return [
                'quote_id' => null, 'booking_id' => $booking->id, 'shop_id' => $booking->shop_id, 'tenant_id' => $booking->tenant_id,
                'purpose' => VenueBookingCharge::LEGACY_REVIEW, 'amount_pesewas' => 20000, 'fee_pesewas' => 0,
                'order_id' => StoreOrder::factory()->reservation()->create([
                    'agreement_id' => $agreement->id, 'principal_pesewas' => 20000, 'platform_fee_pesewas' => 0, 'charged_pesewas' => 20000,
                    'submission_key' => (string) Str::uuid(),
                ])->id,
            ];
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function quotedAmount(array $attributes, string $kind): int
    {
        $quote = VenueFinancialQuote::query()->findOrFail($attributes['quote_id']);

        return match ($attributes['purpose']) {
            VenueBookingCharge::RENTAL_BALANCE => $kind === 'amount' ? $quote->balance_pesewas : $quote->balance_fee_pesewas,
            VenueBookingCharge::SECURITY => $kind === 'amount' ? $quote->security_pesewas : 0,
            default => $kind === 'amount' ? $quote->initial_pesewas : $quote->initial_fee_pesewas,
        };
    }
}
