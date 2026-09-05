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
        'slides_material_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_confirmed' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }

    public function slidesMaterial(): BelongsTo
    {
        return $this->belongsTo(EventMaterial::class, 'slides_material_id');
    }
}
