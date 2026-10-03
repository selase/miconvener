<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VenueFacilityMessage extends Model
{
    use HasFactory;
    use HasUuids;

    public const string SENDER_HOST = 'host';

    public const string SENDER_PLANNER = 'planner';

    public const array SENDER_TYPES = [
        self::SENDER_HOST,
        self::SENDER_PLANNER,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'shop_id',
        'venue_booking_id',
        'event_id',
        'sender_user_id',
        'sender_type',
        'sender_name',
        'message',
        'attachment_path',
        'read_at',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function venueBooking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function isFromHost(): bool
    {
        return $this->sender_type === self::SENDER_HOST;
    }

    public function isFromPlanner(): bool
    {
        return $this->sender_type === self::SENDER_PLANNER;
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type,
            'sender_name' => $this->sender_name,
            'message' => $this->message,
            'attachment_url' => $this->attachment_path ? asset('storage/'.$this->attachment_path) : null,
            'is_read' => $this->isRead(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
