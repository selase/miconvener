<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StorePaymentObservation> */
final class StorePaymentObservationFactory extends Factory
{
    protected $model = StorePaymentObservation::class;

    public function definition(): array
    {
        return ['observation_version' => 1, 'attempt_id' => StorePaymentAttempt::factory(),
            'order_id' => fn (array $a) => StorePaymentAttempt::findOrFail($a['attempt_id'])->order_id,
            'reference' => fn (array $a) => StorePaymentAttempt::findOrFail($a['attempt_id'])->reference,
            'account_fingerprint' => fn (array $a) => StorePaymentAttempt::findOrFail($a['attempt_id'])->account_fingerprint,
            'account_matches' => true, 'reference_matches' => true,
            'amount_pesewas' => fn (array $a) => StorePaymentAttempt::findOrFail($a['attempt_id'])->amount_pesewas,
            'gateway_fee_pesewas' => 195, 'currency' => 'GHS', 'paid_at' => now(),
            'metadata_snapshot' => [], 'integrity_errors' => [], 'payload_hash' => hash('sha256', 'fixture'), 'verified_at' => now()];
    }
}
