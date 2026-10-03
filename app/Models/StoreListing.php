<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class StoreListing extends Model
{
    use HasFactory;
    use HasUuids;

    public const string KIND_VENUE = 'venue';

    public const string KIND_RENTAL = 'rental';

    public const string KIND_SERVICE = 'service';

    public const string KIND_MERCHANDISE = 'merchandise';

    public const string CATEGORY_VENUE = 'venue';

    public const string CATEGORY_PA_SOUND = 'pa_sound';

    public const string CATEGORY_VIDEOGRAPHY = 'videography_streaming';

    public const string CATEGORY_CANOPIES = 'canopies_tents';

    public const string CATEGORY_CHAIRS_DECOR = 'chairs_decor';

    public const string CATEGORY_CATERING = 'catering_beverages';

    public const string CATEGORY_GENERATORS = 'generators_power';

    public const string CATEGORY_SECURITY = 'security_ushers';

    public const string CATEGORY_OTHER = 'other';

    public const string KIND_EQUIPMENT = self::KIND_RENTAL;

    public const string CATEGORY_GENERATORS_POWER = self::CATEGORY_GENERATORS;

    public const string CATEGORY_VIDEOGRAPHY_STREAMING = self::CATEGORY_VIDEOGRAPHY;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_SUSPENDED = 'suspended';

    public const string PRICE_VISIBILITY_PUBLIC = 'public';

    public const string PRICE_VISIBILITY_ON_REQUEST = 'on_request';

    public const string PRICING_MODEL_PER_DAY = 'per_day';

    public const string PRICING_MODEL_PER_HALF_DAY = 'per_half_day';

    public const string PRICING_MODEL_PER_HOUR = 'per_hour';

    public const string PRICING_MODEL_FLAT_RATE = 'flat_rate';

    protected $connection = 'landlord';

    protected $fillable = [
        'shop_id',
        'listing_kind',
        'category',
        'title',
        'slug',
        'description',
        'rental_price_pesewas',
        'pricing_model',
        'price_visibility',
        'security_deposit_pesewas',
        'min_order_pesewas',
        'lead_time_days',
        'capacity_breakdown',
        'service_scope',
        'floor_area_sqm',
        'ceiling_height_meters',
        'rules_and_policies',
        'status',
        'sort_order',
        'embedding',
        'is_bookable',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'embedding',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rental_price_pesewas' => 'integer',
        'security_deposit_pesewas' => 'integer',
        'min_order_pesewas' => 'integer',
        'lead_time_days' => 'integer',
        'capacity_breakdown' => 'array',
        'service_scope' => 'array',
        'rules_and_policies' => 'array',
        'floor_area_sqm' => 'decimal:2',
        'ceiling_height_meters' => 'decimal:2',
        'sort_order' => 'integer',
        'is_bookable' => 'boolean',
    ];

    public function isBookable(): bool
    {
        return (bool) ($this->is_bookable ?? true);
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasMany<StoreListingAmenity, $this>
     */
    public function amenities(): HasMany
    {
        return $this->hasMany(StoreListingAmenity::class, 'listing_id');
    }

    /**
     * @return HasMany<StoreListingMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(StoreListingMedia::class, 'listing_id')->orderBy('sort_order');
    }

    /**
     * @return HasOne<StoreListingMedia, $this>
     */
    public function primaryMedia(): HasOne
    {
        return $this->hasOne(StoreListingMedia::class, 'listing_id')->where('is_primary', true);
    }

    public function getPrimaryMediaAttribute(): ?StoreListingMedia
    {
        return $this->relationLoaded('primaryMedia')
            ? $this->getRelation('primaryMedia')
            : $this->primaryMedia()->first();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isPriceOnRequest(): bool
    {
        return $this->price_visibility === self::PRICE_VISIBILITY_ON_REQUEST;
    }

    public function capacityFor(string $style): ?int
    {
        $capacities = $this->capacity_breakdown ?? [];

        return isset($capacities[$style]) ? (int) $capacities[$style] : null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('store_listings.status', self::STATUS_PUBLISHED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVenues(Builder $query): Builder
    {
        return $query->where('store_listings.listing_kind', self::KIND_VENUE);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMinCapacity(Builder $query, int $minCapacity, string $style = 'banquet'): Builder
    {
        return $query->whereRaw("CAST(COALESCE(store_listings.capacity_breakdown->>?, '0') AS INTEGER) >= ?", [$style, $minCapacity]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, float $radiusKm = 25.0): Builder
    {
        $earthRadiusKm = 6371.0;

        $haversine = "({$earthRadiusKm} * acos(least(1.0, greatest(-1.0, "
            .'cos(radians(?)) * cos(radians(shops.latitude)) * '
            .'cos(radians(shops.longitude) - radians(?)) + '
            .'sin(radians(?)) * sin(radians(shops.latitude))))))';

        if (! $query->getQuery()->columns) {
            $query->select('store_listings.*');
        }

        return $query->join('shops', 'store_listings.shop_id', '=', 'shops.id')
            ->selectRaw("{$haversine} AS distance_km", [$latitude, $longitude, $latitude])
            ->whereNotNull('shops.latitude')
            ->whereNotNull('shops.longitude')
            ->whereRaw("{$haversine} <= ?", [$latitude, $longitude, $latitude, $radiusKm])
            ->orderBy('distance_km');
    }

    /**
     * @param  array<int, float>|string|null  $value
     */
    public function setEmbeddingAttribute(mixed $value): void
    {
        if (is_array($value)) {
            $this->attributes['embedding'] = '['.implode(',', array_map('floatval', $value)).']';
        } else {
            $this->attributes['embedding'] = $value;
        }
    }

    /**
     * @return array<int, float>|null
     */
    public function getEmbeddingAttribute(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $trimmed = mb_trim((string) $value, '[]');
        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }

    /**
     * @param  Builder<self>  $query
     * @param  array<int, float>  $vector
     * @return Builder<self>
     */
    public function scopeWithSimilarity(Builder $query, array $vector): Builder
    {
        if (empty($vector)) {
            return $query;
        }

        $vectorString = '['.implode(',', array_map('floatval', $vector)).']';

        if (! $query->getQuery()->columns) {
            $query->select('store_listings.*');
        }

        return $query->selectRaw('COALESCE((1 - (store_listings.embedding <=> ?::vector)), 0.0) AS similarity_score', [$vectorString]);
    }

    /**
     * @param  Builder<self>  $query
     * @param  array<int, float>  $vector
     * @return Builder<self>
     */
    public function scopeOrderBySimilarity(Builder $query, array $vector): Builder
    {
        if (empty($vector)) {
            return $query;
        }

        $vectorString = '['.implode(',', array_map('floatval', $vector)).']';

        return $query->orderByRaw('store_listings.embedding <=> ?::vector ASC', [$vectorString]);
    }

    public function getSimilarityPercentageAttribute(): ?int
    {
        if (array_key_exists('similarity_percentage', $this->attributes)) {
            return (int) $this->attributes['similarity_percentage'];
        }

        if (array_key_exists('similarity_score', $this->attributes)) {
            $score = (float) $this->attributes['similarity_score'];

            return (int) round(max(0.0, min(1.0, $score)) * 100);
        }

        return null;
    }
}
