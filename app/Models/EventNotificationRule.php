<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventNotificationRule extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string ROLE_ATTENDEE = 'attendee';

    public const string ROLE_SPEAKER = 'speaker';

    public const string ROLE_ORGANIZER = 'organizer';

    public const string TRIGGER_SCHEDULED_OFFSET = 'scheduled_offset';

    public const string TRIGGER_ON_REGISTRATION = 'on_registration';

    public const string TRIGGER_ON_CHECKIN = 'on_checkin';

    public const string TRIGGER_ON_MATERIALS = 'on_materials_uploaded';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'name',
        'target_role',
        'target_audience',
        'trigger_type',
        'offset_direction',
        'offset_amount',
        'offset_unit',
        'channels',
        'subject',
        'body_template',
        'is_active',
        'last_dispatched_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'is_active' => 'boolean',
        'offset_amount' => 'integer',
        'last_dispatched_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EventNotificationLog::class, 'rule_id');
    }

    /**
     * Compute the exact target execution timestamp for scheduled rules.
     */
    public function calculateTargetTimestamp(): ?CarbonInterface
    {
        if ($this->trigger_type !== self::TRIGGER_SCHEDULED_OFFSET) {
            return null;
        }

        $baseDate = $this->offset_direction === 'before'
            ? $this->event->starts_at
            : ($this->event->ends_at ?? $this->event->starts_at);

        if (! $baseDate) {
            return null;
        }

        $carbon = Carbon::parse($baseDate);
        $amount = (int) $this->offset_amount;

        return match ($this->offset_unit) {
            'days' => $this->offset_direction === 'before' ? $carbon->subDays($amount) : $carbon->addDays($amount),
            'hours' => $this->offset_direction === 'before' ? $carbon->subHours($amount) : $carbon->addHours($amount),
            'minutes' => $this->offset_direction === 'before' ? $carbon->subMinutes($amount) : $carbon->addMinutes($amount),
            default => $carbon->subDays($amount),
        };
    }

    /**
     * Check if this rule is currently due to fire.
     */
    public function isDue(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->trigger_type !== self::TRIGGER_SCHEDULED_OFFSET) {
            return false;
        }

        $target = $this->calculateTargetTimestamp();
        if (! $target) {
            return false;
        }

        // Must be in the past or right now, and not dispatched within the past 12 hours
        return $target->isPast() && (! $this->last_dispatched_at || $this->last_dispatched_at->diffInHours(now()) >= 12);
    }
}
