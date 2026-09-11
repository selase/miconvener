<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventAbstractReview extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_DECLINED = 'declined';

    public const array STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_COMPLETED,
        self::STATUS_DECLINED,
    ];

    public const string RECOMMENDATION_ORAL = 'accept_oral';

    public const string RECOMMENDATION_POSTER = 'accept_poster';

    public const string RECOMMENDATION_REJECT = 'reject';

    public const array RECOMMENDATIONS = [
        self::RECOMMENDATION_ORAL,
        self::RECOMMENDATION_POSTER,
        self::RECOMMENDATION_REJECT,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'abstract_id',
        'reviewer_id',
        'status',
        'novelty_score',
        'methodology_score',
        'relevance_score',
        'clarity_score',
        'total_score',
        'recommendation',
        'comments_to_author',
        'confidential_comments',
        'completed_at',
    ];

    public function abstract(): BelongsTo
    {
        return $this->belongsTo(EventAbstract::class, 'abstract_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Calculate and save average rubric score
     */
    public function calculateTotalScore(): ?float
    {
        $scores = array_filter([
            $this->novelty_score,
            $this->methodology_score,
            $this->relevance_score,
            $this->clarity_score,
        ], fn ($score) => ! is_null($score));

        if (empty($scores)) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 2);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'novelty_score' => 'integer',
            'methodology_score' => 'integer',
            'relevance_score' => 'integer',
            'clarity_score' => 'integer',
            'total_score' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }
}
