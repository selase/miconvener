<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityMessageDelivery extends Model
{
    /** @use HasFactory<\Database\Factories\CommunityMessageDeliveryFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['address', 'address_hash', 'unsubscribe_token'];

    protected $casts = ['address' => 'encrypted', 'unsubscribe_token' => 'encrypted', 'claimed_at' => 'immutable_datetime'];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<CommunityMessage,$this> */
    public function message(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CommunityMessage::class, 'message_id');
    }
}
