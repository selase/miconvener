<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventVenueRoom extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'name',
        'rows',
        'seats_per_row',
        'sort_order',
    ];

    protected $casts = [
        'rows' => 'integer',
        'seats_per_row' => 'integer',
        'sort_order' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(EventSeatAssignment::class, 'room_id');
    }

    public function capacity(): int
    {
        return $this->rows * $this->seats_per_row;
    }

    /**
     * @return array<int, string>
     */
    public function seatLabels(): array
    {
        $labels = [];
        for ($r = 0; $r < $this->rows; $r++) {
            $rowLetter = chr(65 + $r);
            for ($seat = 1; $seat <= $this->seats_per_row; $seat++) {
                $labels[] = sprintf('%s-%02d', $rowLetter, $seat);
            }
        }

        return $labels;
    }
}
