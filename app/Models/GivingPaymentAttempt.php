<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $tenant_id
 * @property string $fund_id
 * @property string|null $gift_id
 * @property string|null $event_contribution_id
 * @property string $submission_key
 * @property int $amount
 * @property string $currency
 * @property string $rate
 * @property int|null $cap_amount
 * @property string $bearer
 * @property string $terms_source
 * @property int $commission_amount
 * @property string $provider
 * @property string $account_identity
 * @property string $settlement_mode
 * @property array<string, mixed> $commission_settlement
 * @property string $reference
 * @property string|null $provider_session_id
 * @property string|null $provider_reference
 * @property string|null $checkout_url
 * @property string $category
 * @property string $state
 * @property \Carbon\CarbonImmutable|null $confirmed_at
 */
final class GivingPaymentAttempt extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\GivingPaymentAttemptFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'fund_id', 'gift_id', 'event_contribution_id', 'submission_key', 'amount', 'currency', 'rate', 'cap_amount', 'bearer', 'terms_source', 'commission_amount', 'provider', 'account_identity', 'settlement_mode', 'commission_settlement', 'reference', 'provider_session_id', 'provider_reference', 'checkout_url', 'category', 'state', 'confirmed_at'];

    protected $hidden = ['commission_settlement', 'checkout_url'];

    protected $casts = ['amount' => 'integer', 'rate' => 'decimal:2', 'cap_amount' => 'integer', 'commission_amount' => 'integer', 'commission_settlement' => 'array', 'confirmed_at' => 'immutable_datetime'];

    /** @return BelongsTo<GivingFund, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(GivingFund::class);
    }

    /** @return BelongsTo<GivingGift, $this> */
    public function gift(): BelongsTo
    {
        return $this->belongsTo(GivingGift::class);
    }

    /** @return BelongsTo<EventContribution, $this> */
    public function eventContribution(): BelongsTo
    {
        return $this->belongsTo(EventContribution::class);
    }
}
