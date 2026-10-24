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
 * @property string $kind
 * @property string $operation_key
 * @property int $actor_id
 * @property string $actor_name
 * @property string|null $reason
 * @property array<int,array<string,mixed>> $lines_snapshot
 * @property string $terms_hash
 * @property \Carbon\CarbonImmutable $created_at
 */
final class StoreFulfilmentEvent extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFulfilmentEventFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'shop_id', 'order_id', 'kind', 'operation_key', 'actor_id', 'actor_name', 'reason', 'lines_snapshot', 'terms_hash'];

    /** @return BelongsTo<StoreOrder,$this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'lines_snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
