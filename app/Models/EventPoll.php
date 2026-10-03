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

    public const string TYPE_YES_NO = 'yes_no';

    public const string TYPE_RATING = 'rating';

    public const string TYPE_SCALE = 'scale';

    public const string TYPE_NUMBER = 'number';

    public const string TYPE_MULTI_SELECT = 'multi_select';

    public const string TYPE_WORD_CLOUD = 'word_cloud';

    public const string TYPE_RANKING = 'ranking';

    /** Every type an organiser can create. */
    public const array TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_OPEN,
        self::TYPE_QUIZ,
        self::TYPE_YES_NO,
        self::TYPE_RATING,
        self::TYPE_SCALE,
        self::TYPE_NUMBER,
        self::TYPE_MULTI_SELECT,
        self::TYPE_WORD_CLOUD,
        self::TYPE_RANKING,
    ];

    /** Types whose answers are chosen from the poll's options. */
    public const array OPTION_TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_QUIZ,
        self::TYPE_YES_NO,
        self::TYPE_RATING,
        self::TYPE_MULTI_SELECT,
        self::TYPE_RANKING,
    ];

    /** Types where the organiser writes the options, rather than the type fixing them. */
    public const array AUTHORED_OPTION_TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_QUIZ,
        self::TYPE_MULTI_SELECT,
        self::TYPE_RANKING,
    ];

    /**
     * Types whose chart would describe individuals in a small room. Below this
     * many respondents they show a count and nothing else: a histogram of
     * answers from three people is not an aggregate, it is three answers.
     */
    public const array SUPPRESSED_TYPES = [
        self::TYPE_RATING,
        self::TYPE_SCALE,
        self::TYPE_NUMBER,
    ];

    public const int SUPPRESS_BELOW = 5;

    /** Types whose answers are free text and so pass a moderator when asked. */
    public const array MODERATED_TYPES = [
        self::TYPE_OPEN,
        self::TYPE_WORD_CLOUD,
    ];

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_LIVE = 'live';

    public const string STATUS_CLOSED = 'closed';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'deck_id',
        'position',
        'question',
        'type',
        'status',
        'timer_seconds',
        'points',
        'went_live_at',
        'requires_moderation',
        'settings',
    ];

    protected $casts = [
        'position' => 'integer',
        'timer_seconds' => 'integer',
        'points' => 'integer',
        'went_live_at' => 'datetime',
        'requires_moderation' => 'boolean',
        'settings' => 'array',
    ];

    public function usesOptions(): bool
    {
        return in_array($this->type, self::OPTION_TYPES, true);
    }

    /**
     * One of the type's own settings -- a scale's ends, a number's unit.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<PollDeck, $this>
     */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(PollDeck::class, 'deck_id');
    }

    /**
     * @return HasMany<EventPollOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(EventPollOption::class, 'poll_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<EventPollResponse, $this>
     */
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

    /**
     * A poll outside a deck is always its own current question; inside one, only
     * the deck's pointer decides.
     *
     * Safe to call in a `foreach ($deck->polls as $poll)` loop despite
     * `Model::shouldBeStrict()`: AutomaticallyEagerLoadRelationships is on
     * (config/essentials.php), so the first access loads `deck` for the whole
     * collection in one query rather than lazy loading per row.
     */
    public function isCurrent(): bool
    {
        if ($this->deck_id === null) {
            return true;
        }

        return $this->deck?->current_poll_id === $this->id;
    }
}
