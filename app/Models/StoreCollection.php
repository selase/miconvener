<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $order_id
 * @property string $pass_id
 * @property string $method
 * @property string $operation_key
 * @property int $actor_id
 * @property string $actor_name
 * @property string $source
 * @property array<int,array<string,mixed>> $lines_snapshot
 * @property \Carbon\CarbonImmutable $handed_over_at
 */
final class StoreCollection extends Model
{
    /** @use HasFactory<\Database\Factories\StoreCollectionFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'shop_id', 'order_id', 'pass_id', 'method', 'operation_key', 'actor_id', 'actor_name', 'source', 'lines_snapshot', 'handed_over_at'];

    /** @return BelongsTo<StoreOrder,$this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'lines_snapshot' => 'array', 'handed_over_at' => 'immutable_datetime'];
    }
}
