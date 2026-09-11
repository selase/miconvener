<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

final class EventAbstract extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string STATUS_SUBMITTED = 'submitted';

    public const string STATUS_UNDER_REVIEW = 'under_review';

    public const string STATUS_ACCEPTED_ORAL = 'accepted_oral';

    public const string STATUS_ACCEPTED_POSTER = 'accepted_poster';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_WITHDRAWN = 'withdrawn';

    public const array STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_ACCEPTED_ORAL,
        self::STATUS_ACCEPTED_POSTER,
        self::STATUS_REJECTED,
        self::STATUS_WITHDRAWN,
    ];

    public const string PREFERENCE_ORAL = 'oral';

    public const string PREFERENCE_POSTER = 'poster';

    public const string PREFERENCE_EITHER = 'either';

    public const array PREFERENCES = [
        self::PREFERENCE_ORAL,
        self::PREFERENCE_POSTER,
        self::PREFERENCE_EITHER,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'user_id',
        'code',
        'title',
        'track',
        'presentation_preference',
        'structured_abstract',
        'body',
        'keywords',
        'conflict_of_interest',
        'file_path',
        'status',
        'decision_notes',
        'decided_at',
        'decided_by',
    ];

    public static function generateCode(): string
    {
        do {
            $code = 'ABS-'.mb_strtoupper(Str::random(6));
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function authors(): HasMany
    {
        return $this->hasMany(EventAbstractAuthor::class, 'abstract_id')->orderBy('sort_order', 'asc');
    }

    public function presentingAuthor(): HasOne
    {
        return $this->hasOne(EventAbstractAuthor::class, 'abstract_id')->where('is_presenting', true);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(EventAbstractReview::class, 'abstract_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class, 'abstract_id');
    }

    public function averageScore(): ?float
    {
        $completedReviews = $this->reviews->where('status', EventAbstractReview::STATUS_COMPLETED)->whereNotNull('total_score');

        if ($completedReviews->isEmpty()) {
            return null;
        }

        return round((float) $completedReviews->avg('total_score'), 2);
    }

    public function isAccepted(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED_ORAL, self::STATUS_ACCEPTED_POSTER], true);
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED_ORAL, self::STATUS_ACCEPTED_POSTER, self::STATUS_REJECTED], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'structured_abstract' => 'array',
            'keywords' => 'array',
            'decided_at' => 'datetime',
        ];
    }
}
