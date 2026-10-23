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
 * @property int $version
 * @property string $percentage
 * @property int $cap_pesewas
 * @property string $fee_bearer
 * @property string $gateway_estimate_rate
 * @property string $currency
 * @property string $settlement_mode
 * @property string $provider
 * @property string $account_fingerprint
 * @property string $refund_gateway_fee_bearer
 * @property string $collection_terms
 * @property string $refund_terms
 * @property string $support_contact
 * @property string $receipt_terms
 * @property array<string, mixed> $payout_policy
 * @property string $approved_by
 * @property \Carbon\CarbonImmutable|null $approved_at
 */
final class StoreCommercialAgreement extends Model
{
    /** @use HasFactory<\Database\Factories\StoreCommercialAgreementFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $table = 'store_commercial_agreements';

    protected $fillable = ['tenant_id', 'shop_id', 'version', 'percentage', 'cap_pesewas', 'fee_bearer', 'gateway_estimate_rate', 'currency', 'settlement_mode', 'provider', 'account_fingerprint', 'refund_gateway_fee_bearer', 'collection_terms', 'refund_terms', 'support_contact', 'receipt_terms', 'payout_policy', 'approved_by', 'approved_at'];

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['percentage' => 'decimal:2', 'cap_pesewas' => 'integer', 'gateway_estimate_rate' => 'decimal:2', 'payout_policy' => 'array', 'version' => 'integer', 'approved_at' => 'immutable_datetime'];
    }
}
