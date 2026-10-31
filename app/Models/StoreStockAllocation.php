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
 * @property string $line_id
 * @property string $shop_id
 * @property string $variant_id
 * @property int $quantity
 * @property string $state
 * @property \Carbon\CarbonImmutable $expires_at
 */
final class StoreStockAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\StoreStockAllocationFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['order_id', 'line_id', 'shop_id', 'variant_id', 'quantity', 'expires_at', 'state', 'release_reason'];

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
