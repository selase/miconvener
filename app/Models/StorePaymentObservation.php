<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $observation_version
 * @property string|null $supersedes_id
 * @property string $attempt_id
 * @property string $order_id
 * @property string $account_fingerprint
 * @property string $reference
 * @property bool $account_matches
 * @property bool $reference_matches
 * @property int|null $amount_pesewas
 * @property int|null $gateway_fee_pesewas
 * @property string|null $currency
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property string|null $provider_transaction_id
 * @property array<string, mixed> $metadata_snapshot
 * @property list<string> $integrity_errors
 * @property string $payload_hash
 * @property string $processing_state
 * @property string|null $review_reason
 * @property string|null $ledger_transaction_id
 */
final class StorePaymentObservation extends Model
{
    /** @use HasFactory<\Database\Factories\StorePaymentObservationFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['observation_version', 'supersedes_id', 'attempt_id', 'order_id', 'account_fingerprint', 'reference', 'account_matches', 'reference_matches',
        'amount_pesewas', 'gateway_fee_pesewas', 'currency', 'paid_at', 'provider_transaction_id', 'metadata_snapshot', 'integrity_errors',
        'payload_hash', 'verified_at', 'processing_state', 'review_reason', 'ledger_transaction_id'];

    protected $hidden = ['account_fingerprint'];

    /** @return BelongsTo<StorePaymentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(StorePaymentAttempt::class, 'attempt_id');
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['observation_version' => 'integer', 'account_matches' => 'boolean', 'reference_matches' => 'boolean', 'amount_pesewas' => 'integer',
            'gateway_fee_pesewas' => 'integer', 'metadata_snapshot' => 'array', 'integrity_errors' => 'array',
            'paid_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }
}
