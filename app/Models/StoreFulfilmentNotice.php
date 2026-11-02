<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $event_id
 * @property string $order_id
 * @property string $tenant_id
 * @property array<string,mixed> $snapshot
 * @property string $destination
 * @property string $state
 * @property int $attempts
 * @property \Carbon\CarbonImmutable|null $claimed_at
 * @property \Carbon\CarbonImmutable|null $next_attempt_at
 * @property \Carbon\CarbonImmutable|null $sent_at
 */
final class StoreFulfilmentNotice extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFulfilmentNoticeFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['event_id', 'order_id', 'shop_id', 'tenant_id', 'snapshot', 'destination', 'state', 'attempts', 'claimed_at', 'next_attempt_at', 'sent_at'];

    protected $hidden = ['destination', 'snapshot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'destination' => 'encrypted', 'attempts' => 'integer', 'claimed_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
