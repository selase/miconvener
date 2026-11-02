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
 * @property string $policy_id
 * @property string $method
 * @property string $operation_key
 * @property string $fingerprint
 * @property array<string, mixed> $policy_snapshot
 * @property array<string, mixed> $destination
 */
final class StoreFulfilmentArrangement extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFulfilmentArrangementFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'shop_id', 'order_id', 'policy_id', 'replaces_order_id', 'method', 'operation_key', 'fingerprint', 'policy_snapshot', 'destination'];

    protected $hidden = ['destination'];

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['policy_snapshot' => 'array', 'destination' => 'encrypted:array'];
    }
}
