<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventOperationTask extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string STATUS_NOT_STARTED = 'not_started';

    public const string STATUS_IN_PROGRESS = 'in_progress';

    public const string STATUS_BLOCKED = 'blocked';

    public const string STATUS_DONE = 'done';

    public const array STATUSES = [
        self::STATUS_NOT_STARTED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_BLOCKED,
        self::STATUS_DONE,
    ];

    public const string PRIORITY_LOW = 'low';

    public const string PRIORITY_MEDIUM = 'medium';

    public const string PRIORITY_HIGH = 'high';

    public const string PRIORITY_URGENT = 'urgent';

    public const array PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'pillar_id',
        'title',
        'description',
        'owner_id',
        'owner_name',
        'due_date',
        'priority',
        'status',
        'dependency_task_id',
        'estimated_budget',
        'actual_budget',
        'completed_at',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function pillar(): BelongsTo
    {
        return $this->belongsTo(EventOperationPillar::class, 'pillar_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function dependencyTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'dependency_task_id');
    }

    public function dependentTasks(): HasMany
    {
        return $this->hasMany(self::class, 'dependency_task_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'estimated_budget' => 'integer',
            'actual_budget' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
