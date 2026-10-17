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
 * @property string $payment_key
 * @property string $receipt_number
 * @property array<string, mixed> $snapshot
 * @property string|null $destination
 * @property string $state
 * @property \Carbon\CarbonImmutable|null $sent_at
 */
final class GivingReceiptNotice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\GivingReceiptNoticeFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'fund_id', 'gift_id', 'event_contribution_id', 'payment_key', 'receipt_number', 'snapshot', 'destination', 'state', 'sent_at'];

    protected $hidden = ['snapshot', 'destination'];

    protected $casts = ['snapshot' => 'encrypted:array', 'destination' => 'encrypted', 'sent_at' => 'immutable_datetime'];

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
