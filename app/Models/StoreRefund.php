<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $order_id
 * @property string $shop_id
 * @property string $tenant_id
 * @property string|null $attempt_id
 * @property string|null $observation_id
 * @property string $operation_key
 * @property string $account_fingerprint
 * @property string $reason
 * @property int $amount_pesewas
 * @property int $seller_debit_pesewas
 * @property int $commission_return_pesewas
 * @property int $gateway_fee_pesewas
 * @property int $review_liability_pesewas
 * @property string $fee_bearer
 * @property bool $restock
 * @property string $status
 * @property \Carbon\CarbonImmutable|null $submitted_at
 * @property string|null $provider_refund_id
 * @property string|null $provider_status
 * @property \Carbon\CarbonImmutable|null $provider_processed_at
 * @property string|null $review_reason
 * @property string|null $ledger_transaction_id
 */
final class StoreRefund extends Model
{
    /** @use HasFactory<\Database\Factories\StoreRefundFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['order_id', 'shop_id', 'tenant_id', 'attempt_id', 'observation_id', 'operation_key', 'reason', 'requested_by', 'account_fingerprint',
        'amount_pesewas', 'seller_debit_pesewas', 'commission_return_pesewas', 'gateway_fee_pesewas', 'review_liability_pesewas', 'fee_bearer', 'restock',
        'status', 'submitted_at', 'provider_refund_id', 'provider_status', 'provider_processed_at', 'review_reason', 'ledger_transaction_id'];

    protected $hidden = ['account_fingerprint'];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /** @return BelongsTo<StorePaymentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(StorePaymentAttempt::class, 'attempt_id');
    }

    /** @return BelongsTo<StorePaymentObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(StorePaymentObservation::class, 'observation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_pesewas' => 'integer', 'seller_debit_pesewas' => 'integer', 'commission_return_pesewas' => 'integer',
            'gateway_fee_pesewas' => 'integer', 'review_liability_pesewas' => 'integer', 'restock' => 'boolean',
            'submitted_at' => 'immutable_datetime', 'provider_processed_at' => 'immutable_datetime'];
    }
}
