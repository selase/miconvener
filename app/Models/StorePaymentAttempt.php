<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \Carbon\Carbon $created_at
 * @property string $id
 * @property string $order_id
 * @property string $shop_id
 * @property string $tenant_id
 * @property string $reference
 * @property string $provider
 * @property string $settlement_mode
 * @property string $account_fingerprint
 * @property string $submission_key
 * @property int $amount_pesewas
 * @property string $currency
 * @property string $status
 * @property string|null $checkout_url
 * @property string|null $review_reason
 */
final class StorePaymentAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\StorePaymentAttemptFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['order_id', 'shop_id', 'tenant_id', 'submission_key', 'reference', 'provider', 'settlement_mode', 'account_fingerprint', 'amount_pesewas', 'currency', 'status', 'checkout_url', 'review_reason', 'last_verified_at'];

    protected $hidden = ['checkout_url', 'account_fingerprint'];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<StorePaymentObservation, $this> */
    public function observations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StorePaymentObservation::class, 'attempt_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_pesewas' => 'integer', 'checkout_url' => 'encrypted', 'last_verified_at' => 'immutable_datetime'];
    }
}
