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
 * @property int $version
 * @property string $operation_key
 * @property string $fingerprint
 * @property array<string, mixed> $snapshot
 */
final class StoreFulfilmentPolicy extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFulfilmentPolicyFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'shop_id', 'version', 'operation_key', 'fingerprint', 'snapshot'];

    protected $hidden = [];

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }
}
