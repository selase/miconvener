<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventSeatAssignment extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'room_id',
        'registration_id',
        'seat_label',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(EventVenueRoom::class, 'room_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class);
    }
}
