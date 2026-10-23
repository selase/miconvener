<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $agreement_id
 * @property string $buyer_email
 * @property string $member_id
 * @property string $submission_key
 * @property string $cart_hash
 * @property string $currency
 * @property string $offering_type
 * @property int $principal_pesewas
 * @property int $platform_fee_pesewas
 * @property int $gateway_estimate_pesewas
 * @property int $charged_pesewas
 * @property string $fee_bearer
 * @property string $settlement_mode
 * @property string $provider
 * @property string $account_fingerprint
 * @property array<string, mixed> $seller_snapshot
 * @property array<string, mixed> $terms_snapshot
 * @property string $terms_hash
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property string $state
 * @property string $payment_state
 * @property string $ledger_state
 * @property string $fulfilment_state
 * @property string|null $review_reason
 * @property \Carbon\CarbonImmutable|null $accepted_at
 * @property \Carbon\CarbonImmutable|null $paid_at
 */
final class StoreOrder extends Model
{
    /** @use HasFactory<\Database\Factories\StoreOrderFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $table = 'store_orders';

    protected $fillable = ['tenant_id', 'shop_id', 'agreement_id', 'buyer_email', 'member_id', 'submission_key', 'cart_hash', 'currency', 'offering_type', 'principal_pesewas', 'platform_fee_pesewas', 'gateway_estimate_pesewas', 'charged_pesewas', 'fee_bearer', 'settlement_mode', 'provider', 'account_fingerprint', 'seller_snapshot', 'terms_snapshot', 'terms_hash', 'expires_at', 'state', 'payment_state', 'ledger_state', 'fulfilment_state', 'review_reason', 'accepted_at', 'paid_at'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<StoreCommercialAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(StoreCommercialAgreement::class, 'agreement_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<StoreOrderLine, $this> */
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StoreOrderLine::class, 'order_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasOne<StoreReceipt, $this> */
    public function receipt(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StoreReceipt::class, 'order_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<StorePaymentAttempt, $this> */
    public function attempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StorePaymentAttempt::class, 'order_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['principal_pesewas' => 'integer', 'platform_fee_pesewas' => 'integer', 'gateway_estimate_pesewas' => 'integer', 'charged_pesewas' => 'integer', 'seller_snapshot' => 'array', 'terms_snapshot' => 'array', 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime'];
    }
}
