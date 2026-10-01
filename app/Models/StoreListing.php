<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class StoreListing extends Model
{
    use HasUuids;

    public const string KIND_VENUE = 'venue';

    public const string KIND_RENTAL = 'rental';

    public const string KIND_SERVICE = 'service';

    public const string KIND_MERCHANDISE = 'merchandise';

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
        'title',
        'slug',
        'description',
        'rental_price_pesewas',
        'pricing_model',
        'price_visibility',
        'security_deposit_pesewas',
        'capacity_breakdown',
        'floor_area_sqm',
        'ceiling_height_meters',
        'rules_and_policies',
        'status',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rental_price_pesewas' => 'integer',
        'security_deposit_pesewas' => 'integer',
        'capacity_breakdown' => 'array',
        'rules_and_policies' => 'array',
        'floor_area_sqm' => 'decimal:2',
        'ceiling_height_meters' => 'decimal:2',
        'sort_order' => 'integer',
    ];

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

        return $query->join('shops', 'store_listings.shop_id', '=', 'shops.id')
            ->select('store_listings.*')
            ->selectRaw("{$haversine} AS distance_km", [$latitude, $longitude, $latitude])
            ->whereNotNull('shops.latitude')
            ->whereNotNull('shops.longitude')
            ->whereRaw("{$haversine} <= ?", [$latitude, $longitude, $latitude, $radiusKm])
            ->orderBy('distance_km');
    }
}
