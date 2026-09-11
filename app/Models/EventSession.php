<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class EventSession extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_KEYNOTE = 'keynote';

    public const string TYPE_PLENARY = 'plenary';

    public const string TYPE_PANEL = 'panel';

    public const string TYPE_WORKSHOP = 'workshop';

    public const string TYPE_ORAL_PRESENTATION = 'oral_presentation';

    public const string TYPE_POSTER_SESSION = 'poster_session';

    public const string TYPE_SIMULATION_SKILLS = 'simulation_skills';

    public const string TYPE_BREAKOUT = 'breakout';

    public const string TYPE_NETWORKING = 'networking';

    public const string TYPE_SESSION = 'session';

    public const array TYPES = [
        self::TYPE_KEYNOTE,
        self::TYPE_PLENARY,
        self::TYPE_PANEL,
        self::TYPE_WORKSHOP,
        self::TYPE_ORAL_PRESENTATION,
        self::TYPE_POSTER_SESSION,
        self::TYPE_SIMULATION_SKILLS,
        self::TYPE_BREAKOUT,
        self::TYPE_NETWORKING,
        self::TYPE_SESSION,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'location',
        'track',
        'type',
        'abstract_id',
        'capacity',
        'sort_order',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'capacity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function abstract(): BelongsTo
    {
        return $this->belongsTo(EventAbstract::class, 'abstract_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'event_session_speakers', 'session_id', 'speaker_id')
            ->withPivot(['role', 'sort_order'])
            ->orderBy('event_session_speakers.sort_order');
    }

    public function registrations(): BelongsToMany
    {
        return $this->belongsToMany(EventRegistration::class, 'event_registration_sessions', 'session_id', 'registration_id');
    }

    public function attendances(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EventSessionAttendance::class, 'session_id');
    }

    public function activeAttendances(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EventSessionAttendance::class, 'session_id')->whereNull('checked_out_at');
    }

    public function liveHeadcount(): int
    {
        return $this->activeAttendances()->count();
    }

    public function isRoomFull(): bool
    {
        return $this->capacity !== null && $this->liveHeadcount() >= $this->capacity;
    }

    public function occupancyPercentage(): int
    {
        if (! $this->capacity || $this->capacity <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->liveHeadcount() / $this->capacity) * 100));
    }

    public function scopeWorkshops(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_WORKSHOP);
    }

    public function signupCount(): int
    {
        return $this->registrations()->count();
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->signupCount() >= $this->capacity;
    }

    /**
     * Other sessions in the same event whose time range overlaps this one,
     * sharing either a room (non-empty location) or a speaker.
     *
     * @return array<int, array{title: string, reason: string}>
     */
    public function clashes(): array
    {
        $overlapping = self::where('event_id', $this->event_id)
            ->where('id', '!=', $this->id)
            ->where('starts_at', '<', $this->ends_at)
            ->where('ends_at', '>', $this->starts_at)
            ->with('speakers:id,name')
            ->get();

        $mySpeakerIds = $this->speakers->pluck('id')->all();

        $clashes = [];
        foreach ($overlapping as $other) {
            if ($this->location && $other->location === $this->location) {
                $clashes[] = ['title' => $other->title, 'reason' => "Same room ({$this->location})"];

                continue;
            }

            $sharedSpeakers = $other->speakers->whereIn('id', $mySpeakerIds);
            if ($sharedSpeakers->isNotEmpty()) {
                $clashes[] = ['title' => $other->title, 'reason' => "Same speaker ({$sharedSpeakers->pluck('name')->implode(', ')})"];
            }
        }

        return $clashes;
    }
}
