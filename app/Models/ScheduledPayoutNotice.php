<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $policy_id
 * @property int $recipient_id
 * @property string|null $recipient_email
 * @property string $kind
 * @property string $status
 * @property int $attempts
 * @property \Carbon\CarbonImmutable|null $claimed_at
 * @property \Carbon\CarbonImmutable|null $next_attempt_at
 */
final class ScheduledPayoutNotice extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['recipient_email'];

    protected $casts = ['recipient_email' => 'encrypted', 'attempts' => 'integer', 'claimed_at' => 'immutable_datetime',
        'next_attempt_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
}
