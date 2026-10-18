<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommunityLibraryItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityLibraryItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'kind', 'title', 'description', 'plain_text', 'audience', 'state', 'release_at', 'current_version_id', 'event_id', 'session_id', 'created_by'];

    protected $hidden = ['created_by'];

    protected $casts = ['audience' => 'array', 'release_at' => 'immutable_datetime'];

    /** @return BelongsTo<CommunityLibraryVersion,$this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CommunityLibraryVersion::class, 'current_version_id');
    }

    /** @return HasMany<CommunityLibraryVersion,$this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CommunityLibraryVersion::class, 'item_id');
    }

    /** @return BelongsTo<EventSession,$this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'session_id');
    }

    /** @return BelongsTo<Event,$this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }
}
