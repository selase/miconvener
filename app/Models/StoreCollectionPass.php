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
 * @property string $event_id
 * @property string $method
 * @property string $operation_key
 * @property string $qr_hash
 * @property string $manual_hash
 * @property array<string,string>|null $secret_material
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property \Carbon\CarbonImmutable|null $consumed_at
 */
final class StoreCollectionPass extends Model
{
    /** @use HasFactory<\Database\Factories\StoreCollectionPassFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'shop_id', 'order_id', 'event_id', 'method', 'operation_key', 'issued_to', 'qr_hash', 'manual_hash', 'secret_material', 'expires_at', 'revoked_at', 'revocation_reason', 'consumed_at'];

    protected $hidden = ['issued_to', 'qr_hash', 'manual_hash', 'secret_material'];

    /** @return BelongsTo<StoreOrder,$this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['issued_to' => 'encrypted', 'secret_material' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
