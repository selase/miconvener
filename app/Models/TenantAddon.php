<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantAddon extends Model
{
    /** @use HasFactory<\Database\Factories\TenantAddonFactory> */
    use HasFactory;

    use HasUuids;

    public const TYPE_TEAM_SEAT = 'team_seat';

    public const TYPE_USHER_PACK = 'usher_pack';

    public const TYPE_LIVE_POLLING = 'live_polling';

    public const TYPE_SMS_PACK = 'sms_pack';

    public const TYPE_EMAIL_PACK = 'email_pack';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    public const INTERVAL_ONE_OFF = 'one_off';

    public const INTERVAL_EVENT_PASS = 'event_pass';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $connection = 'landlord';

    protected $table = 'tenant_addons';

    protected $fillable = [
        'tenant_id',
        'addon_type',
        'name',
        'quantity',
        'unit_price',
        'total_price',
        'billing_interval',
        'status',
        'event_id',
        'paystack_reference',
        'period_start',
        'period_end',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total_price' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('status', self::STATUS_ACTIVE)
                ->orWhere(function (Builder $sub): void {
                    $sub->where('status', self::STATUS_CANCELLED)
                        ->whereNotNull('period_end')
                        ->where('period_end', '>=', Carbon::now());
                });
        })->where(function (Builder $q): void {
            $q->whereNull('period_end')
                ->orWhere('period_end', '>=', Carbon::now());
        });
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('addon_type', $type);
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isActive(): bool
    {
        if ($this->period_end !== null && $this->period_end->isPast()) {
            return false;
        }

        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_CANCELLED], true);
    }

    public function formattedPrice(): string
    {
        return number_format($this->total_price / 100, 2);
    }
}
