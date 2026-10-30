<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreCommercialAgreement;
use App\Models\StoreOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreOrder> */
final class StoreOrderFactory extends Factory
{
    protected $model = StoreOrder::class;

    public function definition(): array
    {
        return [
            'agreement_id' => StoreCommercialAgreement::factory(),
            'shop_id' => fn (array $a) => StoreCommercialAgreement::findOrFail($a['agreement_id'])->shop_id,
            'tenant_id' => fn (array $a) => StoreCommercialAgreement::findOrFail($a['agreement_id'])->tenant_id,
            'buyer_email' => 'buyer@example.test', 'submission_key' => (string) Str::uuid(), 'cart_hash' => hash('sha256', 'test-cart'),
            'principal_pesewas' => 3000, 'platform_fee_pesewas' => 60, 'gateway_estimate_pesewas' => 59, 'charged_pesewas' => 3000,
            'fee_bearer' => 'organizer', 'currency' => 'GHS', 'offering_type' => 'purchase',
            'provider' => 'paystack', 'settlement_mode' => 'platform_default', 'account_fingerprint' => hash('sha256', 'test-account'),
            'seller_snapshot' => ['name' => 'Test shop'], 'terms_snapshot' => ['collection' => 'Office'],
            'terms_hash' => hash('sha256', 'test-terms'), 'expires_at' => now()->addMinutes(10),
        ];
    }

    /** A venue reservation payment order resting on the shop's reservation agreement. */
    public function reservation(): static
    {
        return $this->state(fn (): array => [
            'offering_type' => 'reservation',
            'agreement_id' => StoreCommercialAgreement::factory()->reservation(),
        ]);
    }
}
