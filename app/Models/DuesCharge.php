<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\DuesChargeStatusEnum;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DuesChargeStatusEnum $status
 */
final class DuesCharge extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\DuesChargeFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The smallest charge, in minor units, that can be collected. Below it the
     * gateway's fee and the commission can swallow the whole payment, which
     * the ledger cannot book.
     */
    public const int MINIMUM_AMOUNT = 100;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'member_id',
        'dues_class_id',
        'period_start',
        'period_end',
        'due_on',
        'amount',
        'currency',
        'status',
        'paid_at',
        'payment_method',
        'payment_reference',
        'charged_amount',
        'gateway_fee_amount',
        'commission_amount',
        'recorded_by',
        'note',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'due_on' => 'date',
        'amount' => 'integer',
        'charged_amount' => 'integer',
        'gateway_fee_amount' => 'integer',
        'commission_amount' => 'integer',
        'recorded_by' => 'integer',
        'status' => DuesChargeStatusEnum::class,
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<DuesClass, $this>
     */
    public function duesClass(): BelongsTo
    {
        return $this->belongsTo(DuesClass::class);
    }

    /**
     * @param  Builder<DuesCharge>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', DuesChargeStatusEnum::Open);
    }

    /**
     * @param  Builder<DuesCharge>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', DuesChargeStatusEnum::Open)
            ->whereDate('due_on', '<', today());
    }
}
