<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scan at a door: who, which door, when, and what the door decided.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $registration_id
 * @property string|null $staff_link_id
 * @property int|null $user_id
 * @property string|null $client_scan_id
 * @property \Illuminate\Support\Carbon $event_day
 * @property \Illuminate\Support\Carbon $scanned_at
 * @property bool $was_offline
 * @property string $outcome
 */
final class EventDoorScan extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** The first admission of that guest that day. */
    public const string OUTCOME_ADMITTED = 'admitted';

    /** A live scan turned away: already admitted that day. */
    public const string OUTCOME_ALREADY_IN = 'already_in';

    /** An offline admission that, on sync, was the second that day. */
    public const string OUTCOME_DUPLICATE = 'duplicate';

    /** Not a confirmed ticket for this event. */
    public const string OUTCOME_REFUSED = 'refused';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'registration_id',
        'staff_link_id',
        'user_id',
        'client_scan_id',
        'event_day',
        'scanned_at',
        'was_offline',
        'outcome',
    ];

    protected $casts = [
        'event_day' => 'date',
        'scanned_at' => 'datetime',
        'was_offline' => 'boolean',
    ];

    /**
     * Scans that let someone in on that day: the first admission and any
     * offline admission that turned out to be a second.
     *
     * @param  Builder<EventDoorScan>  $query
     * @return Builder<EventDoorScan>
     */
    public function scopeAdmittedOn(Builder $query, string $day): Builder
    {
        return $query->whereDate('event_day', $day)
            ->whereIn('outcome', [self::OUTCOME_ADMITTED, self::OUTCOME_DUPLICATE]);
    }

    /**
     * @return BelongsTo<EventRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    /**
     * @return BelongsTo<EventStaffLink, $this>
     */
    public function staffLink(): BelongsTo
    {
        return $this->belongsTo(EventStaffLink::class, 'staff_link_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who let them in, as the door log shows it.
     */
    public function doorLabel(): string
    {
        if ($this->staffLink !== null) {
            return $this->staffLink->name;
        }

        return $this->user !== null ? mb_trim($this->user->first_name.' '.$this->user->last_name) : 'Console';
    }
}
