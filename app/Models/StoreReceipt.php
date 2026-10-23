<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $number
 * @property array<string, mixed> $snapshot
 */
final class StoreReceipt extends Model
{
    /** @use HasFactory<\Database\Factories\StoreReceiptFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['order_id', 'tenant_id', 'shop_id', 'number', 'snapshot'];

    protected $hidden = ['snapshot'];

    /** @return \Illuminate\Database\Eloquent\Relations\HasOne<StoreOutboxMessage, $this> */
    public function outbox(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StoreOutboxMessage::class, 'receipt_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array'];
    }
}
