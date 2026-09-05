<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventPoll extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_MULTIPLE_CHOICE = 'multiple_choice';

    public const string TYPE_OPEN = 'open';

    public const string TYPE_QUIZ = 'quiz';

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_LIVE = 'live';

    public const string STATUS_CLOSED = 'closed';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'question',
        'type',
        'status',
        'timer_seconds',
        'points',
        'went_live_at',
        'requires_moderation',
    ];

    protected $casts = [
        'timer_seconds' => 'integer',
        'points' => 'integer',
        'went_live_at' => 'datetime',
        'requires_moderation' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(EventPollOption::class, 'poll_id')->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(EventPollResponse::class, 'poll_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_LIVE);
    }

    public function timeUp(): bool
    {
        if (! $this->timer_seconds || ! $this->went_live_at) {
            return false;
        }

        return now()->greaterThan($this->went_live_at->addSeconds($this->timer_seconds));
    }
}
