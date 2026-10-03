<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventSpeaker extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'speaker_id',
        'role',
        'sort_order',
        'portal_token',
        'is_confirmed',
        'confirmed_at',
        'last_invited_at',
        'slides_material_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
        'last_invited_at' => 'datetime',
    ];

    public function portalUrl(): string
    {
        $tenantSlug = $this->event?->tenant?->slug ?? $this->tenant->slug;

        return route('public.events.speaker-portal', [
            'subdomain' => $tenantSlug,
            'event' => $this->event?->slug,
            'token' => $this->portal_token,
        ]);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Speaker, $this>
     */
    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }

    /**
     * @return BelongsTo<EventMaterial, $this>
     */
    public function slidesMaterial(): BelongsTo
    {
        return $this->belongsTo(EventMaterial::class, 'slides_material_id');
    }
}
