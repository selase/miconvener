<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $tenant_id
 * @property string $fund_id
 * @property string $email
 * @property string $email_hash
 * @property \Carbon\CarbonImmutable $consented_at
 * @property string $cadence
 * @property int $anchor_day
 * @property string $local_time
 * @property string $timezone
 * @property \Carbon\CarbonImmutable $next_due_at
 * @property \Carbon\CarbonImmutable|null $disabled_at
 * @property string $unsubscribe_hash
 * @property string|null $last_delivery_key
 */
final class GivingReminder extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\GivingReminderFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'fund_id', 'email', 'email_hash', 'consented_at', 'cadence', 'anchor_day', 'local_time', 'timezone', 'next_due_at', 'disabled_at', 'unsubscribe_hash', 'last_delivery_key'];

    protected $hidden = ['email', 'email_hash', 'unsubscribe_hash'];

    protected $casts = ['email' => 'encrypted', 'consented_at' => 'immutable_datetime', 'next_due_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime', 'anchor_day' => 'integer'];

    /** @return BelongsTo<GivingFund, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(GivingFund::class);
    }
}
