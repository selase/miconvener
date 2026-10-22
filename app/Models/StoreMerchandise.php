<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $listing_id
 * @property string $shop_id
 * @property string $visibility
 * @property string $currency
 * @property string|null $collection_instructions
 * @property \Carbon\CarbonImmutable|null $archived_at
 */
final class StoreMerchandise extends Model
{
    /** @use HasFactory<\Database\Factories\StoreMerchandiseFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $table = 'store_merchandise';

    protected $fillable = ['listing_id', 'shop_id', 'visibility', 'currency', 'collection_instructions', 'archived_at'];

    /** @return BelongsTo<StoreListing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(StoreListing::class, 'listing_id');
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return HasMany<StoreVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(StoreVariant::class, 'listing_id', 'listing_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }
}
