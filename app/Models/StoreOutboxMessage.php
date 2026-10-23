<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $receipt_id
 * @property string $destination
 * @property string $state
 * @property int $attempts
 * @property \Carbon\CarbonImmutable|null $claimed_at
 * @property \Carbon\CarbonImmutable|null $next_attempt_at
 * @property \Carbon\CarbonImmutable|null $sent_at
 */
final class StoreOutboxMessage extends Model
{
    /** @use HasFactory<\Database\Factories\StoreOutboxMessageFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['receipt_id', 'destination', 'state', 'attempts', 'claimed_at', 'next_attempt_at', 'sent_at'];

    protected $hidden = ['destination'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['destination' => 'encrypted', 'attempts' => 'integer', 'claimed_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
