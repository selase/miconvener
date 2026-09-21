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

    /**
     * How late a missed reminder may still be delivered.
     *
     * The scheduler runs every fifteen minutes, but the environment scales to
     * zero, so a gap of a few hours is plausible and must not lose a send.
     * Past a day the reminder is stale -- telling someone an event starts in
     * two hours, a day and a half late, is worse than staying quiet. The
     * window is also what stops a rule left active on a long-finished event
     * from mailing its attendee list the moment the scheduler picks it up.
     */
    public const int CATCH_UP_HOURS = 24;

    /**
     * The fields that decide when a scheduled rule fires.
     *
     * @var list<string>
     */
    private const array TIMING_ATTRIBUTES = [
        'trigger_type',
        'offset_direction',
        'offset_amount',
        'offset_unit',
    ];

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
     *
     * Due means the target moment has arrived and has not long gone: a reminder
     * fires once, and only while it is still worth sending.
     */
    public function isDue(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->trigger_type !== self::TRIGGER_SCHEDULED_OFFSET) {
            return false;
        }

        /*
         * A scheduled reminder is a one-shot. Once it has gone out it never
         * fires again, however long the rule stays active afterwards -- an
         * organiser has no reason to switch off a rule for an event that is
         * over, so the rule outliving the event must not cost its attendees a
         * second copy.
         */
        if ($this->last_dispatched_at) {
            return false;
        }

        $target = $this->calculateTargetTimestamp();
        if (! $target) {
            return false;
        }

        return $target->isPast() && $target->gt(now()->subHours(self::CATCH_UP_HOURS));
    }

    /**
     * Moving a rule to a new moment arms it again.
     *
     * A fired rule never fires twice, so without this an organiser who
     * rescheduled a reminder would get silence: the send it already made
     * would suppress the one they just asked for. Only the timing fields
     * count -- correcting the wording of a reminder that has gone out must
     * not mail everyone a second copy.
     */
    protected static function booted(): void
    {
        self::updating(function (self $rule): void {
            /*
             * An update that sets last_dispatched_at explicitly is the
             * dispatcher recording a send, and that always wins.
             */
            if ($rule->isDirty(self::TIMING_ATTRIBUTES) && ! $rule->isDirty('last_dispatched_at')) {
                $rule->last_dispatched_at = null;
            }
        });
    }
}
