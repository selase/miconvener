<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventSponsor extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TIER_HEADLINE = 'headline';

    public const string TIER_SUPPORTING = 'supporting';

    public const string TIER_PARTNER = 'partner';

    public const array TIERS = [self::TIER_HEADLINE, self::TIER_SUPPORTING, self::TIER_PARTNER];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'name',
        'tier',
        'logo_path',
        'booth',
        'contact_name',
        'contact_email',
        'amount',
        'currency',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'integer',
        'sort_order' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(EventSponsorDeliverable::class, 'sponsor_id')->orderBy('created_at');
    }
}
