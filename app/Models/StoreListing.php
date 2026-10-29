<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\EmbedStoreListingJob;
use App\Scopes\MerchandiseVisibilityScope;
use App\Scopes\StaffOnlyScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property array<string, mixed>|null $public_booking_policy
 */
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

    /** Anyone may see and book the room (the marketplace). */
    public const string AUDIENCE_PUBLIC = 'public';

    /** Only the owning organisation's staff may see and request the room. */
    public const string AUDIENCE_STAFF = 'staff';

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
        'audience',
        'requires_approval',
        'public_booking_policy',
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
        'taken_down_at' => 'datetime',
        'requires_approval' => 'boolean',
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
        'public_booking_policy' => 'array',
    ];

    /** @return HasOne<StoreMerchandise, $this> */
    public function merchandise(): HasOne
    {
        return $this->hasOne(StoreMerchandise::class, 'listing_id');
    }

    /** @return HasMany<StoreVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(StoreVariant::class, 'listing_id');
    }

    /**
     * The approval-first public booking policy, with defaults for unset keys.
     *
     * @return array{approval_required: bool, default_hold_hours: int, max_hold_hours: int, timezone: string}
     */
    public function publicBookingPolicy(): array
    {
        $stored = (array) ($this->public_booking_policy ?? []);

        return [
            'approval_required' => (bool) ($stored['approval_required'] ?? true),
            'default_hold_hours' => (int) ($stored['default_hold_hours'] ?? 48),
            'max_hold_hours' => (int) ($stored['max_hold_hours'] ?? 168),
            'timezone' => (string) ($stored['timezone'] ?? config('app.timezone')),
        ];
    }

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

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeCatalogAvailable(Builder $query): Builder
    {
        return $query->where('store_listings.status', self::STATUS_PUBLISHED)->whereNull('store_listings.taken_down_at')
            ->whereHas('merchandise', fn (Builder $details) => $details->whereNull('archived_at')->where(function (Builder $ready): void {
                $ready->whereRaw("collection_instructions ~ '[^[:space:]]'")->orWhereExists(function ($policy): void {
                    $policy->selectRaw('1')->from('store_fulfilment_policies')->whereColumn('store_fulfilment_policies.shop_id', 'store_merchandise.shop_id');
                });
            }))
            ->whereHas('variants', fn (Builder $variants) => $variants->where('is_active', true))
            ->whereHas('shop', fn (Builder $shop) => $shop->where('is_active', true)
                ->whereHas('tenant', fn (Builder $tenant) => $tenant->where('status', 'active')
                    ->whereHas('features', fn (Builder $feature) => $feature->where('feature_key', 'commerce_catalog')->where('enabled', true))));
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

    /**
     * Include staff-only rooms. For the organisation's own console and for
     * code that has already established the person is that organisation's staff.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithStaffOnly(Builder $query): void
    {
        $query->withoutGlobalScope(StaffOnlyScope::class);
    }

    /**
     * Public rooms, plus the staff-only rooms of this organisation's own shop.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleToStaffOf(Builder $query, Tenant $tenant): void
    {
        $query->withoutGlobalScope(StaffOnlyScope::class)->where(function (Builder $rooms) use ($tenant): void {
            $rooms->where('store_listings.audience', self::AUDIENCE_PUBLIC)
                ->orWhereHas('shop', fn (Builder $shop) => $shop->where('tenant_id', $tenant->id));
        });
    }

    /**
     * Console routes bind a room by id: the organisation's own staff-only
     * rooms resolve, every other organisation's (and anyone's when there is
     * no organisation) are not found, so their existence is not revealed.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $tenant = app(TenantContext::class)->getTenant();
        $query = $this->newQuery();

        if ($tenant instanceof Tenant) {
            $query->visibleToStaffOf($tenant);
        }

        return $query->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    protected static function booted(): void
    {
        self::addGlobalScope(new StaffOnlyScope);
        self::addGlobalScope(new MerchandiseVisibilityScope);

        // Search reads the embedding, so a published listing whose searchable
        // content changed is re-embedded. Saving the embedding itself changes
        // none of these fields, so this cannot loop.
        self::created(function (self $listing): void {
            if ($listing->listing_kind !== self::KIND_MERCHANDISE && $listing->status === self::STATUS_PUBLISHED) {
                EmbedStoreListingJob::dispatch($listing->id);
            }
        });

        self::updated(function (self $listing): void {
            $searchable = ['title', 'description', 'category', 'listing_kind', 'capacity_breakdown', 'service_scope', 'floor_area_sqm', 'ceiling_height_meters', 'rules_and_policies', 'status'];

            if ($listing->listing_kind !== self::KIND_MERCHANDISE && $listing->status === self::STATUS_PUBLISHED && $listing->wasChanged($searchable)) {
                EmbedStoreListingJob::dispatch($listing->id);
            }
        });
    }
}
