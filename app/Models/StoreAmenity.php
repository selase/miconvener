<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StoreAmenity extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'category',
        'slug',
        'name',
        'icon',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * @return HasMany<StoreListingAmenity, $this>
     */
    public function listingAmenities(): HasMany
    {
        return $this->hasMany(StoreListingAmenity::class, 'amenity_id');
    }
}
