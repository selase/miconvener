<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * @property string $id
 * @property string $listing_id
 * @property string $shop_id
 * @property string $sku
 * @property array<string, string> $options
 * @property string $option_key
 * @property int $price_pesewas
 * @property int $on_hand
 * @property bool $is_active
 */
final class StoreVariant extends Model
{
    /** @use HasFactory<\Database\Factories\StoreVariantFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['listing_id', 'shop_id', 'sku', 'options', 'price_pesewas', 'is_active'];

    /** @param array<array-key, mixed> $options */
    public static function optionKey(array $options): string
    {
        $canonical = [];
        foreach ($options as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw ValidationException::withMessages(['options' => 'Option names and values must be text.']);
            }
            $key = mb_strtolower(mb_trim((string) preg_replace('/\s+/u', ' ', $name)));
            $value = mb_strtolower(mb_trim((string) preg_replace('/\s+/u', ' ', $value)));
            if ($key === '' || $value === '' || array_key_exists($key, $canonical)) {
                throw ValidationException::withMessages(['options' => 'Choose distinct, non-empty option names and values.']);
            }
            $canonical[$key] = $value;
        }
        ksort($canonical, SORT_STRING);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<StoreMerchandise, $this> */
    public function merchandise(): BelongsTo
    {
        return $this->belongsTo(StoreMerchandise::class, 'listing_id', 'listing_id');
    }

    /** @return HasMany<StoreStockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StoreStockMovement::class, 'variant_id');
    }

    /** @return HasMany<StoreStockAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(StoreStockAllocation::class, 'variant_id');
    }

    protected static function booted(): void
    {
        self::saving(function (self $variant): void {
            if ($variant->exists && ($variant->isDirty('on_hand') || $variant->isDirty('shop_id') || $variant->isDirty('listing_id'))) {
                throw new LogicException('Inventory and ownership cannot be changed through a variant edit.');
            }
            $variant->sku = mb_strtoupper(mb_trim((string) $variant->sku));
            if (! preg_match('/^[A-Z0-9_-]{1,64}$/D', $variant->sku)) {
                throw ValidationException::withMessages(['sku' => 'Use up to 64 letters, numbers, hyphens or underscores.']);
            }
            $variant->option_key = self::optionKey($variant->options ?? []);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['options' => 'array', 'price_pesewas' => 'integer', 'on_hand' => 'integer', 'is_active' => 'boolean'];
    }
}
