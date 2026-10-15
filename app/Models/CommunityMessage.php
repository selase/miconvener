<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommunityMessage extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityMessageFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = ['channels' => 'array', 'audience' => 'array', 'estimate' => 'array', 'selection_complete' => 'boolean', 'scheduled_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];

    /** @return HasMany<CommunityMessageDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CommunityMessageDelivery::class, 'message_id');
    }
}
