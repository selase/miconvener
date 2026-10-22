<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $variant_id
 * @property string $shop_id
 * @property string $idempotency_key
 * @property int $delta
 * @property int $balance_after
 * @property string $reason
 * @property \Carbon\Carbon|null $created_at
 * @property string $actor_name
 * @property string $actor_id
 */
final class StoreStockMovement extends Model
{
    /** @use HasFactory<\Database\Factories\StoreStockMovementFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['variant_id', 'shop_id', 'idempotency_key', 'delta', 'balance_after', 'reason', 'actor_id', 'actor_name'];

    /** @return BelongsTo<StoreVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(StoreVariant::class, 'variant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Stock movements are immutable.'));
        self::deleting(fn () => throw new LogicException('Stock movements are immutable.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delta' => 'integer', 'balance_after' => 'integer', 'actor_id' => 'string'];
    }
}
