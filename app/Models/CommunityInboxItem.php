<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommunityInboxItem extends Model
{
    /** @use HasFactory<\Database\Factories\CommunityInboxItemFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = true;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['reader_key'];

    protected $casts = ['available_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<CommunityMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunityMessage::class);
    }

    /** @return HasMany<CommunityInboxRead, $this> */
    public function reads(): HasMany
    {
        return $this->hasMany(CommunityInboxRead::class, 'item_id');
    }
}
