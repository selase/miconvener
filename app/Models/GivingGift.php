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
 * @property string|null $member_id
 * @property string|null $payer_name
 * @property string|null $payer_email
 * @property string|null $payer_phone
 * @property string|null $payer_email_hash
 * @property string|null $giver_key
 * @property bool $is_anonymous
 * @property int $amount
 * @property string $currency
 * @property string $source
 * @property string $status
 * @property int|null $recorded_by
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property \Carbon\CarbonImmutable|null $collected_at
 * @property int $gateway_fee_amount
 * @property int $commission_amount
 * @property int $net_amount
 * @property string $submission_key
 */
final class GivingGift extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\GivingGiftFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'fund_id', 'member_id', 'payer_name', 'payer_email', 'payer_phone', 'payer_email_hash', 'giver_key', 'is_anonymous', 'amount', 'currency', 'source', 'status', 'recorded_by', 'paid_at', 'collected_at', 'gateway_fee_amount', 'commission_amount', 'net_amount', 'submission_key'];

    protected $hidden = ['payer_name', 'payer_email', 'payer_phone', 'payer_email_hash', 'giver_key'];

    protected $casts = ['payer_name' => 'encrypted', 'payer_email' => 'encrypted', 'payer_phone' => 'encrypted', 'is_anonymous' => 'boolean', 'amount' => 'integer', 'gateway_fee_amount' => 'integer', 'commission_amount' => 'integer', 'net_amount' => 'integer', 'paid_at' => 'immutable_datetime', 'collected_at' => 'immutable_datetime'];

    /** @return BelongsTo<GivingFund, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(GivingFund::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
