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
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $issued_by
 * @property string $operation_key
 * @property string $request_hash
 * @property array<string,mixed> $snapshot
 * @property \Carbon\CarbonImmutable|null $withdrawn_at
 * @property string|null $withdrawn_by
 * @property string|null $withdrawal_reason
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class StoreSellerQuote extends Model
{
    /** @use HasFactory<\Database\Factories\StoreSellerQuoteFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['id', 'order_id', 'tenant_id', 'shop_id', 'issued_by', 'issuer_name', 'operation_key', 'request_hash', 'snapshot', 'withdrawn_at', 'withdrawn_by', 'withdrawal_reason'];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'order_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'issued_by' => 'string', 'withdrawn_by' => 'string', 'withdrawn_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
