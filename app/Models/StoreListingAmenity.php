<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StoreListingAmenity extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'listing_id',
        'amenity_id',
        'is_included',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_included' => 'boolean',
    ];

    /**
     * @return BelongsTo<StoreListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(StoreListing::class, 'listing_id');
    }

    /**
     * @return BelongsTo<StoreAmenity, $this>
     */
    public function amenity(): BelongsTo
    {
        return $this->belongsTo(StoreAmenity::class, 'amenity_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeIncluded(Builder $query): Builder
    {
        return $query->where('is_included', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExcluded(Builder $query): Builder
    {
        return $query->where('is_included', false);
    }
}
