<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreCommercialAgreement;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueFinancialQuote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VenueFinancialQuote> */
final class VenueFinancialQuoteFactory extends Factory
{
    protected $model = VenueFinancialQuote::class;

    /**
     * A GH₵1,050 rental (GH₵1,000 rent and GH₵50 extras) paid as a GH₵300
     * deposit and a GH₵750 balance, plus a GH₵200 refundable security amount,
     * at the venue's 3% reservation commission.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => fn () => VenueBooking::factory()->create([
                'status' => VenueBooking::STATUS_APPROVED_HOLD, 'approval_flow_version' => 1, 'hold_expires_at' => '2031-05-01 00:00:00',
                'starts_at' => '2031-06-01 09:00:00', 'ends_at' => '2031-06-01 17:00:00',
            ])->id,
            'shop_id' => fn (array $a) => VenueBooking::query()->findOrFail($a['booking_id'])->shop_id,
            'tenant_id' => fn (array $a) => VenueBooking::query()->findOrFail($a['booking_id'])->tenant_id,
            'version' => 1,
            'agreement_id' => fn (array $a) => StoreCommercialAgreement::factory()->reservation()->create(['shop_id' => $a['shop_id'], 'tenant_id' => $a['tenant_id']])->id,
            'agreement_scope' => 'reservation',
            'hold_action_id' => fn (array $a) => VenueBookingAction::factory()->create([
                'booking_id' => $a['booking_id'], 'shop_id' => $a['shop_id'], 'tenant_id' => $a['tenant_id'],
                'action' => VenueBookingAction::ACTION_APPROVED, 'hold_expires_at' => '2031-05-01 00:00:00',
            ])->id,
            'hold_expires_at' => '2031-05-01 00:00:00',
            'currency' => 'GHS', 'provider' => 'paystack', 'account_fingerprint' => hash('sha256', 'test-account'),
            'rental_pesewas' => 100000, 'extras_pesewas' => 5000, 'security_pesewas' => 20000,
            'initial_pesewas' => 30000, 'balance_pesewas' => 75000,
            'contract_fee_pesewas' => 3150, 'initial_fee_pesewas' => 900, 'balance_fee_pesewas' => 2250,
            'event_starts_at' => '2031-06-01 09:00:00', 'event_ends_at' => '2031-06-01 17:00:00',
            'expires_at' => '2031-05-01 00:00:00', 'balance_due_at' => '2031-05-25 00:00:00', 'security_due_at' => '2031-05-30 00:00:00',
            'cancellation_terms' => 'Full refund of any instalment up to 14 days before the event.',
            'support_contact' => 'bookings@host.example',
            'issued_by' => User::factory(), 'issuer_name' => 'Test host',
            'operation_key' => fn () => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'quote-request'), 'terms_hash' => hash('sha256', 'quote-terms'),
            'snapshot' => ['venue' => 'Test hall'],
        ];
    }
}
