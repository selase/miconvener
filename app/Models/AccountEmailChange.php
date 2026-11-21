<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $user_id
 * @property string $current_email
 * @property string $new_email
 * @property int $security_version
 * @property string|null $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string $notification_status
 * @property CarbonImmutable|null $notified_at
 * @property-read User|null $user
 */
final class AccountEmailChange extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['token_hash', 'current_email', 'new_email', 'security_version'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'string', 'security_version' => 'integer',
            'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'notified_at' => 'immutable_datetime',
        ];
    }
}
