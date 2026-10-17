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
 * @property string $reason
 * @property int $principal
 * @property int $commission_reversal
 * @property int $gateway_credit
 * @property string $kind
 * @property string $status
 * @property int|null $actor_id
 * @property string $operation_key
 * @property string|null $provider_refund_reference
 * @property \Carbon\CarbonImmutable|null $confirmed_at
 */
final class GivingAdjustment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\GivingAdjustmentFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'fund_id', 'gift_id', 'event_contribution_id', 'reason', 'principal', 'commission_reversal', 'gateway_credit', 'kind', 'status', 'actor_id', 'operation_key', 'provider_refund_reference', 'confirmed_at'];

    protected $hidden = [];

    protected $casts = ['principal' => 'integer', 'commission_reversal' => 'integer', 'gateway_credit' => 'integer', 'confirmed_at' => 'immutable_datetime'];

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
