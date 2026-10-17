<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\LedgerCategoryEnum;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int|null $requested_transfer_fee_amount
 * @property int|null $requested_net_amount
 * @property string|null $event_id
 * @property LedgerCategoryEnum|null $category
 */
final class EventPayout extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string STATUS_SCHEDULED = 'scheduled';

    public const string STATUS_PAID = 'paid';

    /**
     * The provider accepted the transfer but is holding it until a one-time
     * code is supplied. No money has moved and none will without it.
     */
    public const string STATUS_AWAITING_OTP = 'awaiting_otp';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_FAILED = 'failed';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'category',
        'payout_account_id',
        'amount',
        'currency',
        'transfer_fee_amount',
        'requested_transfer_fee_amount',
        'requested_net_amount',
        'net_paid_amount',
        'status',
        'scheduled_at',
        'paid_at',
        'note',
        'provider_reference',
        'provider_transfer_code',
        'failure_reason',
    ];

    protected $casts = [
        'category' => LedgerCategoryEnum::class,
        'amount' => 'integer',
        'transfer_fee_amount' => 'integer',
        'requested_transfer_fee_amount' => 'integer',
        'requested_net_amount' => 'integer',
        'net_paid_amount' => 'integer',
        'scheduled_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<TenantPayoutAccount, $this>
     */
    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(TenantPayoutAccount::class, 'payout_account_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EventLedgerEntry::class, 'payout_id');
    }

    /**
     * Every payout belongs to an accounting category. One for an event is an
     * event payout without anyone having to say so; any other kind of payout
     * must name its category, because there is nothing to infer it from.
     */
    protected static function booted(): void
    {
        self::creating(function (EventPayout $payout): void {
            if ($payout->category !== null) {
                return;
            }

            if (blank($payout->event_id)) {
                throw new LogicException('A payout without an event needs an accounting category.');
            }

            $payout->category = LedgerCategoryEnum::Event;
        });
    }
}
