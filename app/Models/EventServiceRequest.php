<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventServiceRequest extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_REFRESHMENT = 'refreshment';

    public const string TYPE_ASSISTANCE = 'assistance';

    public const string TYPE_TECHNICAL = 'technical';

    public const string TYPE_ACCESSIBILITY = 'accessibility';

    public const string TYPE_MEDICAL = 'medical';

    public const string TYPE_OTHER = 'other';

    public const array TYPES = [
        self::TYPE_REFRESHMENT,
        self::TYPE_ASSISTANCE,
        self::TYPE_TECHNICAL,
        self::TYPE_ACCESSIBILITY,
        self::TYPE_MEDICAL,
        self::TYPE_OTHER,
    ];

    public const string PRIORITY_LOW = 'low';

    public const string PRIORITY_NORMAL = 'normal';

    public const string PRIORITY_HIGH = 'high';

    public const string PRIORITY_URGENT = 'urgent';

    public const string STATUS_OPEN = 'open';

    public const string STATUS_ACKNOWLEDGED = 'acknowledged';

    public const string STATUS_IN_PROGRESS = 'in_progress';

    public const string STATUS_RESOLVED = 'resolved';

    public const string STATUS_CANCELLED = 'cancelled';

    public const array STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'registration_id',
        'assigned_to',
        'type',
        'priority',
        'status',
        'location',
        'note',
        'acknowledged_at',
        'resolved_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_CANCELLED]);
    }

    public function isMedical(): bool
    {
        return $this->type === self::TYPE_MEDICAL;
    }

    public function ageInMinutes(): int
    {
        return (int) $this->created_at->diffInMinutes(now());
    }
}
