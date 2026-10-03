<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $shop_id
 * @property string|null $store_listing_id
 * @property string|null $marketplace_quote_id
 * @property string|null $planner_tenant_id
 * @property string $planner_name
 * @property string $planner_email
 * @property int $rating
 * @property string|null $title
 * @property string $comment
 * @property bool $is_verified_booking
 * @property bool $is_published
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop|null $shop
 * @property-read StoreListing|null $listing
 * @property-read MarketplaceQuote|null $quote
 * @property-read Tenant|null $plannerTenant
 *
 * @method static Builder<self> query()
 * @method static self create(array<string, mixed> $attributes = [])
 */
final class MarketplaceReview extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'shop_id',
        'store_listing_id',
        'marketplace_quote_id',
        'planner_tenant_id',
        'planner_name',
        'planner_email',
        'rating',
        'title',
        'comment',
        'is_verified_booking',
        'is_published',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rating' => 'integer',
        'is_verified_booking' => 'boolean',
        'is_published' => 'boolean',
    ];

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return BelongsTo<StoreListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(StoreListing::class, 'store_listing_id');
    }

    /**
     * @return BelongsTo<MarketplaceQuote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(MarketplaceQuote::class, 'marketplace_quote_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function plannerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'planner_tenant_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('marketplace_reviews.is_published', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'planner_name' => $this->planner_name,
            'rating' => $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'is_verified_booking' => $this->is_verified_booking,
            'created_at' => $this->created_at?->diffForHumans(),
        ];
    }

    protected static function booted(): void
    {
        self::saved(function (self $review): void {
            $review->shop?->recalculateRating();
        });

        self::deleted(function (self $review): void {
            $review->shop?->recalculateRating();
        });
    }
}
