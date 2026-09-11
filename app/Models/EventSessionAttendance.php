<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventSessionAttendance extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'session_id',
        'registration_id',
        'checked_in_at',
        'checked_out_at',
        'checked_in_by',
        'checked_out_by',
        'device_name',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'session_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('checked_out_at');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('checked_out_at');
    }

    public function isCurrentlyInRoom(): bool
    {
        return $this->checked_out_at === null;
    }

    /**
     * Dwell duration in whole minutes. If still in room, calculates duration up to now.
     */
    public function durationMinutes(): int
    {
        $start = $this->checked_in_at;
        $end = $this->checked_out_at ?: now();

        if (! $start) {
            return 0;
        }

        return (int) max(0, $start->diffInMinutes($end));
    }

    /**
     * Contact hours (e.g. 1.50) for CPD/CME accreditation calculation.
     */
    public function contactHoursEarned(): float
    {
        return round($this->durationMinutes() / 60, 2);
    }
}
