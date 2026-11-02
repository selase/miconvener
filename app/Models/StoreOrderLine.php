<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $order_id
 * @property string $shop_id
 * @property string $variant_id
 * @property string $listing_id
 * @property string $offering_type
 * @property string $title_snapshot
 * @property string $sku_snapshot
 * @property array<string, mixed> $options_snapshot
 * @property int $quantity
 * @property int $unit_price_pesewas
 * @property int $line_amount_pesewas
 */
final class StoreOrderLine extends Model
{
    /** @use HasFactory<\Database\Factories\StoreOrderLineFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $table = 'store_order_lines';

    protected $fillable = ['order_id', 'shop_id', 'variant_id', 'booking_id', 'listing_id', 'offering_type', 'title_snapshot', 'sku_snapshot', 'options_snapshot', 'quantity', 'unit_price_pesewas', 'line_amount_pesewas'];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /** @return BelongsTo<StoreVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(StoreVariant::class);
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['options_snapshot' => 'array', 'quantity' => 'integer', 'unit_price_pesewas' => 'integer', 'line_amount_pesewas' => 'integer'];
    }
}
