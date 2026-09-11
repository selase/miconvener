<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventPayoutSchedule extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const TYPE_MANUAL = 'manual';

    public const TYPE_IMMEDIATE = 'immediate';

    public const TYPE_POST_EVENT = 'post_event';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SETTLED = 'settled';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'schedule_type',
        'days_after_event',
        'holdback_percentage',
        'holdback_release_days',
        'minimum_payout_amount',
        'auto_payout_enabled',
        'preferred_account_id',
        'status',
        'last_reconciled_at',
        'next_scheduled_run_at',
    ];

    protected $casts = [
        'days_after_event' => 'integer',
        'holdback_percentage' => 'float',
        'holdback_release_days' => 'integer',
        'minimum_payout_amount' => 'integer',
        'auto_payout_enabled' => 'boolean',
        'last_reconciled_at' => 'datetime',
        'next_scheduled_run_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function preferredAccount(): BelongsTo
    {
        return $this->belongsTo(TenantPayoutAccount::class, 'preferred_account_id');
    }

    public function isPostEvent(): bool
    {
        return $this->schedule_type === self::TYPE_POST_EVENT;
    }

    public function isImmediate(): bool
    {
        return $this->schedule_type === self::TYPE_IMMEDIATE;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
