<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreCommercialAgreement> */
final class StoreCommercialAgreementFactory extends Factory
{
    protected $model = StoreCommercialAgreement::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'tenant_id' => fn (array $a) => Shop::findOrFail($a['shop_id'])->tenant_id,
            'version' => 1, 'percentage' => '2.00', 'cap_pesewas' => null, 'fee_bearer' => 'organizer',
            'gateway_estimate_rate' => '1.95', 'currency' => 'GHS', 'settlement_mode' => 'platform_default',
            'provider' => 'paystack', 'account_fingerprint' => hash('sha256', 'test-account'),
            'refund_gateway_fee_bearer' => 'seller', 'collection_terms' => 'Collect weekdays from the office.',
            'refund_terms' => 'Full refund before collection.', 'support_contact' => 'support@example.test',
            'receipt_terms' => 'Payment receipt; no VAT asserted.', 'payout_policy' => ['holdback_pesewas' => 0, 'manual_release' => true],
            'approved_by' => User::factory(), 'approved_at' => now(),
        ];
    }
}
