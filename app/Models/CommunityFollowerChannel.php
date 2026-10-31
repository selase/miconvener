<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommunityFollowerChannel extends Model
{
    /** @use HasFactory<\Database\Factories\CommunityFollowerChannelFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['address_hash'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = ['address' => 'encrypted', 'consented_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime'];

    /** @return BelongsTo<CommunityFollower,$this> */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(CommunityFollower::class);
    }
}
